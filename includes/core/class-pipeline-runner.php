<?php
declare(strict_types=1);

/**
 * Executes the full content generation pipeline.
 *
 * Orchestrates: Budget check → Collect source data → Analyze patterns →
 * Score ideas → Generate content → Editorial review → Publish.
 *
 * Triggered by: Autoblogger::on_daily_generation() and Autoblogger::on_ajax_generate_now().
 * Dependencies: All core content classes (Collector, Analyzer, Scorer, Generator, Editor, Publisher).
 *
 * @see class-autoblogger.php         — Instantiates this class and calls run().
 * @see core/class-cost-tracker.php   — Tracks cumulative API costs throughout the run.
 * @see ARCHITECTURE.md               — Data flow diagram showing pipeline steps.
 */
class Autoblogger_Pipeline_Runner {

	/**
	 * Execute the full content generation pipeline.
	 *
	 * Steps: Budget check → Collect source data → Analyze patterns →
	 *        Score ideas → Generate content → Editorial review → Publish.
	 *
	 * Side effects: API calls (OpenRouter, Reddit), database writes, post creation.
	 *
	 * @return array{generated: int, published: int, rejected: int, cost: float} Pipeline results.
	 *
	 * @throws \RuntimeException If budget is exceeded or critical step fails.
	 */
	public function run(): array {
		$cost_tracker = new Autoblogger_Cost_Tracker();

		// Tag all log entries in this run with a unique ID so link_generation_logs()
		// can accurately attribute costs to the correct post in batch runs.
		$cost_tracker->set_run_id( wp_generate_uuid4() );

		// Hard stop if monthly budget is exceeded.
		if ( $cost_tracker->is_budget_exceeded() ) {
			throw new \RuntimeException(
				__( 'Monthly API budget exceeded. Generation halted.', 'autoblogger' )
			);
		}

		$target_count = absint( get_option( 'autoblogger_daily_article_target', 1 ) );

		// Step 1: Collect source data.
		$collector = new Autoblogger_Source_Collector();
		$collector->collect_from_all_sources();

		// Step 2: Analyze collected data for patterns.
		$llm      = new Autoblogger_OpenRouter_Provider();
		$analyzer = new Autoblogger_Content_Analyzer( $llm, $cost_tracker );
		$analysis = $analyzer->analyze_recent_data();

		// Step 3: Score and deduplicate ideas.
		$scorer = new Autoblogger_Idea_Scorer();
		$ideas  = $scorer->score_and_rank( $analysis, $target_count );

		if ( empty( $ideas ) ) {
			Autoblogger_Logger::instance()->info( 'No viable article ideas found. Skipping generation.', 'pipeline' );
			return [ 'generated' => 0, 'published' => 0, 'rejected' => 0, 'cost' => 0.0 ];
		}

		// Step 4 & 5: Generate content and run through editor for each idea.
		$generator    = new Autoblogger_Content_Generator( $llm, $cost_tracker );
		$editor       = new Autoblogger_Chief_Editor( $llm, $cost_tracker );
		$publisher    = new Autoblogger_Publisher();
		$auto_publish = in_array( get_option( 'autoblogger_auto_publish', '0' ), [ '1', 'yes' ], true );

		$generated = 0;
		$published = 0;
		$rejected  = 0;

		foreach ( $ideas as $idea ) {
			// Re-check budget before each article — stop early if we hit the limit.
			if ( $cost_tracker->is_budget_exceeded() ) {
				Autoblogger_Logger::instance()->warning( 'Budget limit reached during generation. Stopping early.', 'pipeline' );
				break;
			}

			try {
				$content = $generator->generate( $idea );
				$generated++;

				$review = $editor->review( $content, $idea );

				if ( 'approved' === $review->get_verdict() || 'revised' === $review->get_verdict() ) {
					// Null-coalesce: if editor returns 'revised' verdict but
					// get_revised_content() is null (e.g. malformed LLM response),
					// fall back to the original generated content.
					$final_content = 'revised' === $review->get_verdict()
						? ( $review->get_revised_content() ?? $content )
						: $content;

					if ( $auto_publish ) {
						$publisher->publish( $final_content, $idea, $review, $cost_tracker->get_run_id() );
						$published++;
					} else {
						// Auto-publish disabled: send to review queue as draft.
						$publisher->save_as_draft( $final_content, $idea, $review, $cost_tracker->get_run_id() );
					}
				} else {
					$publisher->save_as_draft( $content, $idea, $review, $cost_tracker->get_run_id() );
					$rejected++;
					Autoblogger_Logger::instance()->info( 'Article rejected by editor: ' . $idea->get_topic(), 'pipeline' );
				}
			} catch ( \Exception $e ) {
				Autoblogger_Logger::instance()->error( 'Failed to generate article for topic "' . $idea->get_topic() . '": ' . $e->getMessage(), 'pipeline' );
				// Continue with next idea — don't let one failure stop the batch.
			}
		}

		$total_cost = $cost_tracker->get_current_run_cost();

		Autoblogger_Logger::instance()->info(
			sprintf( 'Pipeline complete: %d generated, %d published, %d rejected. Cost: $%.4f', $generated, $published, $rejected, $total_cost ),
			'pipeline'
		);

		return [
			'generated' => $generated,
			'published' => $published,
			'rejected'  => $rejected,
			'cost'      => $total_cost,
		];
	}
}
