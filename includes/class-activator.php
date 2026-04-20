<?php
declare(strict_types=1);

/**
 * Handles plugin activation: creates database tables, sets default options,
 * and schedules the initial cron job.
 *
 * Triggered by: WordPress `register_activation_hook` in prautoblogger.php.
 * Dependencies: WordPress $wpdb, dbDelta().
 *
 * @see class-deactivator.php — Reverses transient/cron setup on deactivation.
 * @see ARCHITECTURE.md       — Database schema definitions.
 */
class PRAutoBlogger_Activator {

	/**
	 * Run activation tasks.
	 *
	 * Creates custom database tables if they don't exist, sets default option
	 * values (without overwriting existing ones), and schedules the daily
	 * generation cron event.
	 *
	 * Side effects: writes to wp_options, creates database tables, schedules WP-Cron event.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::set_default_options();
		self::schedule_cron();

		// Store the DB version so we can run migrations on future updates.
		update_option( 'prautoblogger_db_version', PRAUTOBLOGGER_DB_VERSION, false );
	}

	/**
	 * Create all custom database tables using dbDelta.
	 *
	 * Side effects: creates/updates four database tables.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'prautoblogger_';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Source data — raw posts/comments from social platforms.
		$sql_source_data = "CREATE TABLE {$prefix}source_data (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_type VARCHAR(50) NOT NULL,
			source_id VARCHAR(255) NOT NULL,
			subreddit VARCHAR(255) DEFAULT NULL,
			title TEXT DEFAULT NULL,
			content LONGTEXT DEFAULT NULL,
			author VARCHAR(255) DEFAULT NULL,
			score INT DEFAULT 0,
			comment_count INT DEFAULT 0,
			permalink VARCHAR(500) DEFAULT NULL,
			collected_at DATETIME NOT NULL,
			metadata_json LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY source_unique (source_type, source_id),
			KEY source_collected (source_type, collected_at)
		) {$charset_collate};";

		// Analysis results — detected patterns from source data.
		$sql_analysis = "CREATE TABLE {$prefix}analysis_results (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			analysis_type VARCHAR(50) NOT NULL,
			topic VARCHAR(500) NOT NULL,
			summary TEXT DEFAULT NULL,
			frequency INT DEFAULT 1,
			relevance_score FLOAT DEFAULT 0,
			source_ids_json LONGTEXT DEFAULT NULL,
			analyzed_at DATETIME NOT NULL,
			metadata_json LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			KEY type_relevance (analysis_type, relevance_score),
			KEY analyzed_at (analyzed_at)
		) {$charset_collate};";

		// Generation log — every API call with cost tracking.
		// run_id groups all log entries from a single pipeline execution so
		// link_generation_logs() can accurately attribute costs to a post.
		$sql_generation_log = "CREATE TABLE {$prefix}generation_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED DEFAULT NULL,
			run_id VARCHAR(36) DEFAULT NULL,
			stage VARCHAR(50) NOT NULL,
			provider VARCHAR(50) NOT NULL,
			model VARCHAR(100) NOT NULL,
			prompt_tokens INT DEFAULT 0,
			completion_tokens INT DEFAULT 0,
			estimated_cost DECIMAL(10,6) DEFAULT 0,
			request_json LONGTEXT DEFAULT NULL,
			response_status VARCHAR(20) NOT NULL DEFAULT 'success',
			error_message TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY run_id (run_id),
			KEY created_at (created_at),
			KEY stage (stage)
		) {$charset_collate};";

		// Content scores — post performance metrics for self-improvement.
		$sql_content_scores = "CREATE TABLE {$prefix}content_scores (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			pageviews INT DEFAULT 0,
			avg_time_on_page FLOAT DEFAULT 0,
			bounce_rate FLOAT DEFAULT 0,
			comment_count INT DEFAULT 0,
			composite_score FLOAT DEFAULT 0,
			score_factors_json LONGTEXT DEFAULT NULL,
			measured_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY measured_at (measured_at),
			KEY composite_score (composite_score)
		) {$charset_collate};";

		// Event log — structured application log entries for the admin log viewer.
		$sql_event_log = "CREATE TABLE {$prefix}event_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			context VARCHAR(100) DEFAULT NULL,
			message TEXT NOT NULL,
			meta_json LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY level (level),
			KEY created_at (created_at),
			KEY level_created (level, created_at)
		) {$charset_collate};";

		dbDelta( $sql_source_data );
		dbDelta( $sql_analysis );
		dbDelta( $sql_generation_log );
		dbDelta( $sql_content_scores );
		dbDelta( $sql_event_log );
	}

	/**
	 * Set default option values without overwriting existing ones.
	 *
	 * Uses add_option() which is a no-op if the option already exists.
	 *
	 * Side effects: writes to wp_options.
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		$defaults = [
			'prautoblogger_analysis_model'       => PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL,
			'prautoblogger_writing_model'        => PRAUTOBLOGGER_DEFAULT_WRITING_MODEL,
			'prautoblogger_editor_model'         => PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL,
			'prautoblogger_daily_article_target'  => 1,
			'prautoblogger_writing_pipeline'      => 'multi_step',
			'prautoblogger_niche_description'     => '',
			'prautoblogger_target_subreddits'     => '[]',
			'prautoblogger_monthly_budget_usd'    => 50.00,
			'prautoblogger_tone'                  => 'informational',
			'prautoblogger_min_word_count'        => 800,
			'prautoblogger_max_word_count'        => 2000,
			'prautoblogger_topic_exclusions'      => '[]',
			'prautoblogger_enabled_sources'       => '["reddit"]',
			'prautoblogger_article_font_family'   => 'default',
			'prautoblogger_article_font_size'     => 0,
			'prautoblogger_table_borders'         => '1',
			'prautoblogger_schedule_time'         => '03:00',
			'prautoblogger_log_level'             => 'info',
		];

		foreach ( $defaults as $key => $value ) {
			add_option( $key, $value );
		}
	}

	/**
	 * Schedule the daily generation cron event if not already scheduled.
	 *
	 * The custom 'prautoblogger_six_hours' schedule is normally registered via
	 * the `cron_schedules` filter in class-prautoblogger.php. However, activation
	 * runs before `plugins_loaded`, so the filter may not be registered yet.
	 * We register it inline here to ensure it exists at scheduling time.
	 *
	 * Side effects: registers a WP-Cron event, may register a cron schedule.
	 *
	 * @return void
	 */
	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'prautoblogger_daily_generation' ) ) {
			// Schedule for the configured time tomorrow.
			$time_str  = get_option( 'prautoblogger_schedule_time', '03:00' );
			$parts     = explode( ':', $time_str );
			$hour      = isset( $parts[0] ) ? absint( $parts[0] ) : 3;
			$minute    = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
			$timestamp = strtotime( "tomorrow {$hour}:{$minute}" );

			if ( false !== $timestamp ) {
				wp_schedule_event( $timestamp, 'daily', 'prautoblogger_daily_generation' );
			}
		}

		// Ensure the six-hour schedule exists before referencing it.
		// Activation can fire before plugins_loaded, so the cron_schedules filter
		// in class-prautoblogger.php may not be hooked yet.
		$schedules = wp_get_schedules();
		if ( ! isset( $schedules['prautoblogger_six_hours'] ) ) {
			add_filter( 'cron_schedules', static function ( array $scheds ): array {
				$scheds['prautoblogger_six_hours'] = [
					'interval' => 6 * HOUR_IN_SECONDS,
					'display'  => __( 'Every Six Hours', 'prautoblogger' ),
				];
				return $scheds;
			} );
		}

		// Schedule a separate metrics collection job (runs every 6 hours).
		if ( ! wp_next_scheduled( 'prautoblogger_collect_metrics' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'prautoblogger_six_hours', 'prautoblogger_collect_metrics' );
		}
	}
}
