<?php
declare(strict_types=1);

/**
 * Tracks all API costs, enforces monthly budget limits, and provides
 * cost reporting data for the admin dashboard.
 *
 * Every LLM API call in the plugin is logged through this class. The budget
 * enforcement is a hard stop — if the monthly budget is exceeded, no further
 * API calls are made until the next month or the budget is increased.
 *
 * Triggered by: Every class that makes an LLM API call (Content_Analyzer,
 *               Content_Generator, Chief_Editor, Metrics_Collector).
 * Dependencies: WordPress $wpdb, Autoblogger_OpenRouter_Provider (for cost estimation).
 *
 * @see core/class-content-analyzer.php  — Calls log_api_call() after analysis.
 * @see core/class-content-generator.php — Calls log_api_call() after each stage.
 * @see core/class-chief-editor.php      — Calls log_api_call() after review.
 * @see admin/class-metrics-page.php     — Displays cost data from this class.
 * @see ARCHITECTURE.md                  — ab_generation_log table schema.
 */
class Autoblogger_Cost_Tracker {

	/**
	 * Running cost for the current pipeline execution.
	 * Reset each time the pipeline starts.
	 */
	private float $current_run_cost = 0.0;

	/**
	 * Unique identifier for the current pipeline run.
	 * Used to link generation log entries to specific posts without timestamp-based guessing.
	 */
	private ?string $run_id = null;

	/**
	 * Set the run_id for the current pipeline execution.
	 *
	 * All subsequent log_api_call() entries will be tagged with this ID so
	 * link_generation_logs() can attribute them to the correct post.
	 *
	 * @param string $run_id UUID or unique string for this run.
	 *
	 * @return void
	 */
	public function set_run_id( string $run_id ): void {
		$this->run_id = $run_id;
	}

	/**
	 * Get the current run_id.
	 *
	 * @return string|null
	 */
	public function get_run_id(): ?string {
		return $this->run_id;
	}

	/**
	 * Log an API call with its token usage and estimated cost.
	 *
	 * Side effects: database insert into ab_generation_log.
	 *
	 * @param int|null $post_id           WordPress post ID (null during generation, set later).
	 * @param string   $stage             Pipeline stage: 'analysis', 'outline', 'draft', 'polish', 'review'.
	 * @param string   $provider          Provider name: 'OpenRouter'.
	 * @param string   $model             Model identifier used.
	 * @param int      $prompt_tokens     Input tokens consumed.
	 * @param int      $completion_tokens Output tokens generated.
	 * @param string   $response_status   'success', 'error', or 'timeout'.
	 * @param string   $error_message     Error details if status is not 'success'.
	 *
	 * @return void
	 */
	public function log_api_call(
		?int $post_id,
		string $stage,
		string $provider,
		string $model,
		int $prompt_tokens,
		int $completion_tokens,
		string $response_status = 'success',
		string $error_message = ''
	): void {
		$llm  = new Autoblogger_OpenRouter_Provider();
		$cost = $llm->estimate_cost( $model, $prompt_tokens, $completion_tokens );

		$this->current_run_cost += $cost;

		$log_entry = new Autoblogger_Generation_Log( [
			'post_id'           => $post_id,
			'run_id'            => $this->run_id,
			'stage'             => $stage,
			'provider'          => $provider,
			'model'             => $model,
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'estimated_cost'    => $cost,
			'response_status'   => $response_status,
			'error_message'     => $error_message,
			'created_at'        => current_time( 'mysql' ),
		] );

		global $wpdb;
		$table = $wpdb->prefix . 'autoblogger_generation_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $log_entry->to_db_row() );

		if ( false === $wpdb->insert_id ) {
			Autoblogger_Logger::instance()->error( 'Failed to log API call: ' . $wpdb->last_error, 'cost-tracker' );
		}
	}

	/**
	 * Check if the monthly budget has been exceeded.
	 *
	 * Uses round() to 4 decimal places to avoid floating-point comparison edge
	 * cases where e.g. $49.999999997 could be treated as >= $50.00.
	 *
	 * @return bool True if monthly spend >= configured budget.
	 */
	public function is_budget_exceeded(): bool {
		$budget = (float) get_option( 'autoblogger_monthly_budget_usd', 50.00 );
		if ( $budget <= 0 ) {
			// Budget of 0 means no limit.
			return false;
		}

		$monthly_spend = $this->get_monthly_spend();

		// Round to 4 decimal places (0.01 cent precision) to avoid
		// floating-point representation artifacts triggering false positives.
		return round( $monthly_spend, 4 ) >= round( $budget, 4 );
	}

	/**
	 * Get total estimated spend for the current calendar month.
	 *
	 * @return float Total USD spend this month.
	 */
	public function get_monthly_spend(): float {
		global $wpdb;
		$table = $wpdb->prefix . 'autoblogger_generation_log';

		$first_of_month = gmdate( 'Y-m-01 00:00:00' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(estimated_cost), 0) FROM {$table} WHERE created_at >= %s AND response_status = 'success'",
				$first_of_month
			)
		);

		return (float) $result;
	}

	/**
	 * Get the cost accumulated during the current pipeline run.
	 *
	 * @return float USD cost for this run.
	 */
	public function get_current_run_cost(): float {
		return $this->current_run_cost;
	}

	/**
	 * Get daily spend for the last N days (for the metrics dashboard chart).
	 *
	 * @param int $days Number of days to look back.
	 *
	 * @return array<string, float> Associative array of date => total_cost.
	 */
	public function get_daily_spend( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'autoblogger_generation_log';

		$start_date = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as day, SUM(estimated_cost) as total_cost
				FROM {$table}
				WHERE created_at >= %s AND response_status = 'success'
				GROUP BY DATE(created_at)
				ORDER BY day ASC",
				$start_date . ' 00:00:00'
			),
			ARRAY_A
		);

		$daily = [];
		foreach ( ( $results ?: [] ) as $row ) {
			$daily[ $row['day'] ] = (float) $row['total_cost'];
		}

		return $daily;
	}

	/**
	 * Get spend breakdown by pipeline stage for a given period.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 *
	 * @return array<string, array{cost: float, tokens: int, calls: int}> Breakdown by stage.
	 */
	public function get_spend_by_stage( string $start_date, string $end_date ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'autoblogger_generation_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stage,
					SUM(estimated_cost) as total_cost,
					SUM(prompt_tokens + completion_tokens) as total_tokens,
					COUNT(*) as call_count
				FROM {$table}
				WHERE created_at BETWEEN %s AND %s AND response_status = 'success'
				GROUP BY stage",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			),
			ARRAY_A
		);

		$breakdown = [];
		foreach ( ( $results ?: [] ) as $row ) {
			$breakdown[ $row['stage'] ] = [
				'cost'   => (float) $row['total_cost'],
				'tokens' => (int) $row['total_tokens'],
				'calls'  => (int) $row['call_count'],
			];
		}

		return $breakdown;
	}

	/**
	 * Get budget utilization percentage for the current month.
	 *
	 * @return float Percentage (0-100+). Can exceed 100 if overspent.
	 */
	public function get_budget_utilization(): float {
		$budget = (float) get_option( 'autoblogger_monthly_budget_usd', 50.00 );
		if ( $budget <= 0 ) {
			return 0.0;
		}
		return ( $this->get_monthly_spend() / $budget ) * 100.0;
	}
}
