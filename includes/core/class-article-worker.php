<?php
declare(strict_types=1);

/**
 * Generates a single article: content → editorial review → publish/draft.
 *
 * Extracted from Pipeline_Runner so each chained cron job has a focused,
 * single-responsibility worker. One worker = one article = one PHP process.
 *
 * Triggered by: Pipeline_Runner (directly for article 1, via cron for 2..N).
 * Dependencies: Content_Generator, Chief_Editor, Publisher, Cost_Tracker.
 *
 * @see core/class-pipeline-runner.php — Orchestrates and dispatches workers.
 * @see core/class-content-generator.php — LLM content generation.
 * @see core/class-chief-editor.php      — Editorial quality gate.
 * @see core/class-publisher.php         — WordPress post creation.
 */
class PRAutoBlogger_Article_Worker {

	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	/**
	 * @param PRAutoBlogger_Cost_Tracker $cost_tracker Shared cost tracker.
	 */
	public function __construct( PRAutoBlogger_Cost_Tracker $cost_tracker ) {
		$this->cost_tracker = $cost_tracker;
	}

	/**
	 * Generate one article from an idea.
	 *
	 * Runs the full single-article pipeline: LLM content generation →
	 * editorial review → publish or save as draft.
	 *
	 * Side effects: LLM API calls, database writes, WordPress post creation,
	 * image generation, cost logging.
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The scored idea to generate from.
	 *
	 * @return array{generated: int, published: int, rejected: int, cost: float}
	 */
	public function generate( PRAutoBlogger_Article_Idea $idea ): array {
		$llm       = new PRAutoBlogger_OpenRouter_Provider();
		$generator = new PRAutoBlogger_Content_Generator( $llm, $this->cost_tracker );
		$editor    = new PRAutoBlogger_Chief_Editor( $llm, $this->cost_tracker );
		$publisher = new PRAutoBlogger_Publisher();

		$auto_publish = in_array(
			get_option( 'prautoblogger_auto_publish', '0' ),
			[ '1', 'yes' ],
			true
		);

		$result = [
			'generated' => 0,
			'published' => 0,
			'rejected'  => 0,
			'cost'      => 0.0,
		];

		try {
			$this->broadcast_stage( __( 'Generating article draft via AI…', 'prautoblogger' ) );
			$content = $generator->generate( $idea );
			$result['generated'] = 1;

			$this->broadcast_stage( __( 'Running editorial pass…', 'prautoblogger' ) );
			$review = $editor->review( $content, $idea );

			$this->broadcast_stage( __( 'Saving and publishing…', 'prautoblogger' ) );
			$this->publish_or_draft(
				$content, $idea, $review, $publisher, $auto_publish, $result
			);
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf(
					'Article generation %s for "%s": %s',
					get_class( $e ),
					$idea->get_topic(),
					$e->getMessage()
				),
				'pipeline'
			);
		}

		$result['cost'] = $this->cost_tracker->get_current_run_cost();
		return $result;
	}

	/**
	 * Publish approved content or save as draft.
	 *
	 * @param string                         $content      Generated HTML content.
	 * @param PRAutoBlogger_Article_Idea     $idea         Source idea.
	 * @param PRAutoBlogger_Editorial_Review $review       Editor verdict.
	 * @param PRAutoBlogger_Publisher         $publisher    Publisher instance.
	 * @param bool                           $auto_publish Whether to auto-publish.
	 * @param array                          &$result      Result counters (by ref).
	 */
	private function publish_or_draft(
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		PRAutoBlogger_Publisher $publisher,
		bool $auto_publish,
		array &$result
	): void {
		$verdict = $review->get_verdict();
		$run_id  = $this->cost_tracker->get_run_id();

		if ( 'approved' === $verdict || 'revised' === $verdict ) {
			$final = 'revised' === $verdict
				? ( $review->get_revised_content() ?? $content )
				: $content;

			if ( $auto_publish ) {
				$publisher->publish( $final, $idea, $review, $run_id );
				$result['published']++;
			} else {
				$publisher->save_as_draft( $final, $idea, $review, $run_id );
			}
		} else {
			$publisher->save_as_draft( $content, $idea, $review, $run_id );
			$result['rejected']++;
			PRAutoBlogger_Logger::instance()->info(
				'Article rejected by editor: ' . $idea->get_topic(),
				'pipeline'
			);
		}
	}

	/**
	 * Update the generation status transient with the current stage.
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
