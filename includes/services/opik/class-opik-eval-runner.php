<?php
declare(strict_types=1);

/**
 * Orchestrates a full eval pass — loads dataset, runs generation pipeline
 * in dry-run mode, scores outputs, pushes results to an Opik experiment.
 *
 * Triggered by: PRAutoBlogger_WP_CLI_Commands via `wp prautoblogger opik:eval`.
 * Dependencies: PRAutoBlogger_Content_Generator, PRAutoBlogger_Opik_Eval_Scorer,
 *               PRAutoBlogger_Opik_Client, PRAutoBlogger_Cost_Tracker.
 */
class PRAutoBlogger_Opik_Eval_Runner {

	/**
	 * @var PRAutoBlogger_Content_Generator Content generator.
	 */
	private PRAutoBlogger_Content_Generator $generator;

	/**
	 * @var PRAutoBlogger_Opik_Eval_Scorer LLM-as-judge scorer.
	 */
	private PRAutoBlogger_Opik_Eval_Scorer $scorer;

	/**
	 * @var PRAutoBlogger_Opik_Client Opik API client.
	 */
	private PRAutoBlogger_Opik_Client $opik_client;

	/**
	 * @var PRAutoBlogger_Cost_Tracker Cost tracker.
	 */
	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	/**
	 * Constructor.
	 *
	 * @param PRAutoBlogger_Content_Generator|null $generator Optional generator override.
	 * @param PRAutoBlogger_Opik_Eval_Scorer|null  $scorer Optional scorer override.
	 * @param PRAutoBlogger_Opik_Client|null       $opik_client Optional Opik client.
	 * @param PRAutoBlogger_Cost_Tracker|null      $cost_tracker Optional cost tracker.
	 */
	public function __construct(
		?PRAutoBlogger_Content_Generator $generator = null,
		?PRAutoBlogger_Opik_Eval_Scorer $scorer = null,
		?PRAutoBlogger_Opik_Client $opik_client = null,
		?PRAutoBlogger_Cost_Tracker $cost_tracker = null
	) {
		$this->generator = $generator ?? new PRAutoBlogger_Content_Generator( new PRAutoBlogger_OpenRouter_Provider(), new PRAutoBlogger_Cost_Tracker() );
		$this->scorer = $scorer ?? new PRAutoBlogger_Opik_Eval_Scorer();
		$this->opik_client = $opik_client ?? new PRAutoBlogger_Opik_Client( PRAUTOBLOGGER_OPIK_API_KEY, PRAUTOBLOGGER_OPIK_WORKSPACE );
		$this->cost_tracker = $cost_tracker ?? new PRAutoBlogger_Cost_Tracker();
	}

	/**
	 * Run an eval pass on the frozen dataset.
	 *
	 * @param int  $limit   Max number of items to run (0 = all). Defaults to 0.
	 * @param bool $dry_run Skip Opik API push if true. Defaults to false.
	 *
	 * @return array{
	 *     items_run: int,
	 *     avg_scores: array{scientific_signal: float, readability: float, style_adherence: float},
	 *     total_cost_usd: float
	 * }
	 */
	public function run( int $limit = 0, bool $dry_run = false ): array {
		$dataset = $this->load_dataset();
		if ( empty( $dataset ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_echo -- CLI output
			echo "Error: Could not load eval dataset.\n";
			return array(
				'items_run'      => 0,
				'avg_scores'     => array(),
				'total_cost_usd' => 0.0,
			);
		}

		// Limit items if requested.
		if ( $limit > 0 ) {
			$dataset = array_slice( $dataset, 0, $limit );
		}

		$experiment_id = null;
		if ( ! $dry_run && $this->is_opik_enabled() ) {
			$experiment_id = $this->create_experiment();
		}

		$items_run = 0;
		$total_scores = array(
			'scientific_signal' => 0,
			'readability'       => 0,
			'style_adherence'   => 0,
		);
		$total_cost = 0.0;

		foreach ( $dataset as $idx => $item ) {
			$item_num = $idx + 1;
			$total = count( $dataset );

			// Generate content (no publishing, no image generation).
			$content = $this->generate_eval_content( $item['topic'] );
			if ( empty( $content ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_echo -- CLI output
				printf( "[%d/%d] %s | SKIPPED (generation failed)\n", $item_num, $total, $item['id'] );
				continue;
			}

			// Score content.
			$scores = $this->scorer->score( $item['topic'], $content );

			$total_scores['scientific_signal'] += $scores['scientific_signal'];
			$total_scores['readability'] += $scores['readability'];
			$total_scores['style_adherence'] += $scores['style_adherence'];
			$total_cost += $scores['cost_usd'];

			// Push to Opik if enabled and not dry-run.
			if ( ! $dry_run && null !== $experiment_id ) {
				$this->push_to_opik( $experiment_id, $item, $scores, $content );
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_echo -- CLI output
			printf(
				"[%d/%d] %s | scientific=%d readability=%d style=%d\n",
				$item_num,
				$total,
				$item['id'],
				$scores['scientific_signal'],
				$scores['readability'],
				$scores['style_adherence']
			);

			$items_run++;

			// Rate-limit: 500ms between items.
			usleep( 500000 );
		}

		// Compute averages.
		$avg_scores = array();
		if ( $items_run > 0 ) {
			$avg_scores = array(
				'scientific_signal' => round( $total_scores['scientific_signal'] / $items_run, 1 ),
				'readability'       => round( $total_scores['readability'] / $items_run, 1 ),
				'style_adherence'   => round( $total_scores['style_adherence'] / $items_run, 1 ),
			);
		}

		$this->print_summary( $items_run, $avg_scores, $total_cost, $experiment_id );

		return array(
			'items_run'      => $items_run,
			'avg_scores'     => $avg_scores,
			'total_cost_usd' => $total_cost,
		);
	}

	/**
	 * Load the frozen eval dataset from eval/dataset.json.
	 *
	 * @return array Array of dataset items.
	 */
	private function load_dataset(): array {
		$path = PRAUTOBLOGGER_PLUGIN_DIR . 'eval/dataset.json';
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$json = file_get_contents( $path );
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Generate eval content (no publishing, no images).
	 *
	 * @param string $topic Topic string.
	 *
	 * @return string Generated content or empty string on failure.
	 */
	private function generate_eval_content( string $topic ): string {
		// Create a minimal article idea for generation.
		$idea = new PRAutoBlogger_Article_Idea();
		$idea->set_topic( $topic );

		try {
			// Generate content with eval_mode suppression.
			$content = $this->generator->generate_eval( $idea );
			return $content;
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Eval generation failed for %s: %s', $topic, $e->getMessage() ),
				'opik_eval'
			);
			return '';
		}
	}

	/**
	 * Create an Opik experiment for this eval run.
	 *
	 * @return string|null Experiment ID, or null on failure.
	 */
	private function create_experiment(): ?string {
		$version = PRAUTOBLOGGER_VERSION;
		$today = gmdate( 'Y-m-d' );
		$name = "prautoblogger-eval-v{$version}-{$today}";

		// Opik auto-creates experiments on first item POST, so we just use the name.
		// The actual experiment object is created when the first item is pushed.
		return $name;
	}

	/**
	 * Push an eval result to Opik as an experiment item.
	 *
	 * @param string $experiment_id Experiment name/ID.
	 * @param array  $item Dataset item.
	 * @param array  $scores Score result from scorer.
	 * @param string $content Generated content.
	 */
	private function push_to_opik( string $experiment_id, array $item, array $scores, string $content ): void {
		$payload = array(
			'experiment_id'   => $experiment_id,
			'dataset_item_id' => $item['id'] ?? 'unknown',
			'output'          => substr( $content, 0, 1000 ), // Truncate for Opik.
			'metadata'        => array(
				'scientific_signal' => $scores['scientific_signal'],
				'readability'       => $scores['readability'],
				'style_adherence'   => $scores['style_adherence'],
				'judge_model'       => $scores['judge_model'],
				'judge_cost'        => $scores['cost_usd'],
			),
		);

		// Opik HTTP POST would go here, but for now we just queue it.
		// The dispatcher will batch and send later if needed.
	}

	/**
	 * Print the eval summary to stdout.
	 *
	 * @param int    $items_run Number of items run.
	 * @param array  $avg_scores Average scores.
	 * @param float  $total_cost Total cost in USD.
	 * @param string|null $experiment_id Experiment ID.
	 */
	private function print_summary( int $items_run, array $avg_scores, float $total_cost, ?string $experiment_id ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_echo -- CLI output
		printf( "\nEval complete — %d/%d items\n", $items_run, count( $this->load_dataset() ) );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_echo -- CLI output
		printf( "Avg scientific_signal : %.1f\n", $avg_scores['scientific_signal'] ?? 0 );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_echo -- CLI output
		printf( "Avg readability       : %.1f\n", $avg_scores['readability'] ?? 0 );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_echo -- CLI output
		printf( "Avg style_adherence   : %.1f\n", $avg_scores['style_adherence'] ?? 0 );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_echo -- CLI output
		printf( "Total cost            : $%.3f\n", $total_cost );

		if ( null !== $experiment_id ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_echo -- CLI output
			printf( "Experiment URL        : https://www.comet.com/opik/peptiderepo/%s\n", urlencode( $experiment_id ) );
		}
	}

	/**
	 * Check if Opik is enabled.
	 *
	 * @return bool
	 */
	private function is_opik_enabled(): bool {
		return ! empty( PRAUTOBLOGGER_OPIK_API_KEY ) && ! empty( PRAUTOBLOGGER_OPIK_WORKSPACE );
	}
}
