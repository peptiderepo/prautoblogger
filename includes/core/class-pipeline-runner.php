<?php
declare(strict_types=1);

/**
 * Executes the full content generation pipeline.
 *
 * Orchestrates: Budget check → Collect source data → Analyze patterns →
 * Score ideas → Generate content → Editorial review → Publish.
 *
 * Triggered by: PRAutoBlogger::on_daily_generation() and PRAutoBlogger::on_ajax_generate_now().
 * Dependencies: All core content classes (Collector, Analyzer, Scorer, Generator, Editor, Publisher).
 *
 * @see class-prautoblogger.php         — Instantiates this class and calls run().
 * @see core/class-cost-tracker.php   — Tracks cumulative API costs throughout the run.
 * @see ARCHITECTURE.md               — Data flow diagram showing pipeline steps.
 */
class PRAutoBlogger_Pipeline_Runner {

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
	/** @var bool Whether to skip duplicate-topic detection (used for manual runs). */
	private bool $skip_dedup = false;

	/**
	 * Allow manual runs to skip the has_similar_post() check so "Generate Now"
	 * always produces content even when a related article was recently published.
	 *
	 * @param bool $skip True to skip deduplication.
	 *
	 * @return $this
	 */
	public function set_skip_dedup( bool $skip ): self {
		$this->skip_dedup = $skip;
		return $this;
	}

	public function run(): array {
		$cost_tracker = new PRAutoBlogger_Cost_Tracker();

		// Tag all log entries in this run with a unique ID so link_generation_logs()
		// can accurately attribute costs to the correct post in batch runs.
		$cost_tracker->set_run_id( wp_generate_uuid4() );

		// Hard stop if monthly budget is exceeded.
		if ( $cost_tracker->is_budget_exceeded() ) {
			throw new \RuntimeException(
				__( 'Monthly API budget exceeded. Generation halted.', 'prautoblogger' )
			);
		}

		$target_count = absint( get_option( 'prautoblogger_daily_article_target', 1 ) );

		// Step 1: Collect source data.
		$this->broadcast_stage( __( 'Collecting sources from Reddit…', 'prautoblogger' ) );
		$collector = new PRAutoBlogger_Source_Collector();
		$collector->collect_from_all_sources();

		// Step 2: Analyze collected data for patterns.
		// Pass a generous target so the LLM returns enough diverse ideas for
		// scoring to pick from — ask for 2× the daily target so dedup has room.
		$this->broadcast_stage( __( 'Analyzing topics and scoring…', 'prautoblogger' ) );
		$llm      = new PRAutoBlogger_OpenRouter_Provider();
		$analyzer = new PRAutoBlogger_Content_Analyzer( $llm, $cost_tracker );
		$analysis = $analyzer->analyze_recent_data( max( $target_count * 2, 6 ) );

		// Step 3: Score and deduplicate ideas.
		$this->broadcast_stage( __( 'Selecting best topic…', 'prautoblogger' ) );
		$scorer = new PRAutoBlogger_Idea_Scorer();
		$scorer->set_skip_dedup( $this->skip_dedup );
		$ideas  = $scorer->score_and_rank( $analysis, $target_count );

		if ( empty( $ideas ) ) {
			PRAutoBlogger_Logger::instance()->info( 'No viable article ideas found. Skipping generation.', 'pipeline' );
			return [ 'generated' => 0, 'published' => 0, 'rejected' => 0, 'cost' => 0.0 ];
		}

		// Step 4 & 5: Generate content and run through editor for each idea.
		$generator    = new PRAutoBlogger_Content_Generator( $llm, $cost_tracker );
		$editor       = new PRAutoBlogger_Chief_Editor( $llm, $cost_tracker );
		$publisher    = new PRAutoBlogger_Publisher();
		$auto_publish = in_array( get_option( 'prautoblogger_auto_publish', '0' ), [ '1', 'yes' ], true );

		$generated = 0;
		$published = 0;
		$rejected  = 0;

		foreach ( $ideas as $idea ) {
			// Re-check budget before each article — stop early if we hit the limit.
			if ( $cost_tracker->is_budget_exceeded() ) {
				PRAutoBlogger_Logger::instance()->warning( 'Budget limit reached during generation. Stopping early.', 'pipeline' );
				break;
			}

			try {
				$this->broadcast_stage( __( 'Generating article draft via AI…', 'prautoblogger' ) );
				$content = $generator->generate( $idea );
				$generated++;

				$this->broadcast_stage( __( 'Running editorial pass…', 'prautoblogger' ) );
				$review = $editor->review( $content, $idea );

				if ( 'approved' === $review->get_verdict() || 'revised' === $review->get_verdict() ) {
					// Null-coalesce: if editor returns 'revised' verdict but
					// get_revised_content() is null (e.g. malformed LLM response),
					// fall back to the original generated content.
					$final_content = 'revised' === $review->get_verdict()
						? ( $review->get_revised_content() ?? $content )
						: $content;

					$this->broadcast_stage( __( 'Saving and publishing…', 'prautoblogger' ) );
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
					PRAutoBlogger_Logger::instance()->info( 'Article rejected by editor: ' . $idea->get_topic(), 'pipeline' );
				}
			} catch ( \Throwable $e ) {
				PRAutoBlogger_Logger::instance()->error( sprintf( 'Article generation %s for "%s": %s', get_class( $e ), $idea->get_topic(), $e->getMessage() ), 'pipeline' );
				// Continue with next idea — don't let one failure stop the batch.
			}
		}

		$total_cost = $cost_tracker->get_current_run_cost();

		PRAutoBlogger_Logger::instance()->info(
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

	/**
	 * Update the generation status transient with the current pipeline stage.
	 *
	 * Called at key checkpoints during the pipeline so the frontend status
	 * poller can show real progress instead of fake timers. No-ops if the
	 * transient doesn't exist (e.g., during daily cron runs).
	 *
	 * @param string $stage Human-readable stage description.
	 */
	private function broadcast_stage( string $stage ): void {
		$transient_key = 'prautoblogger_generation_status';
		$current       = get_transient( $transient_key );
		if ( is_array( $current ) && 'running' === ( $current['status'] ?? '' ) ) {
			$current['stage'] = $stage;
			set_transient( $transient_key, $current, 600 );
		}
	}
}
