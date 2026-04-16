<?php
declare(strict_types=1);

/**
 * Main orchestrator for the PRAutoBlogger plugin.
 *
 * Registers all WordPress hooks (actions and filters) and wires up dependencies.
 * This is the ONLY place hooks are registered — individual classes contain only
 * business logic, not hook registration.
 *
 * Triggered by: prautoblogger() singleton in prautoblogger.php, called on `plugins_loaded`.
 * Dependencies: All other plugin classes (loaded via autoloader), PRAutoBlogger_Pipeline_Runner.
 *
 * @see prautoblogger.php                — Plugin bootstrap that instantiates this class.
 * @see core/class-pipeline-runner.php — Executes the generation pipeline.
 * @see ARCHITECTURE.md                — Data flow diagram showing how classes interact.
 * @see CONVENTIONS.md                 — Hook naming conventions.
 */
class PRAutoBlogger {

	private bool $initialized = false;

	/** @var PRAutoBlogger_OpenRouter_Model_Registry|null Lazy-loaded singleton. */
	private ?PRAutoBlogger_OpenRouter_Model_Registry $model_registry = null;

	/**
	 * Register all hooks and initialize the plugin.
	 *
	 * Called once on `plugins_loaded`. Idempotent — calling it twice is a no-op.
	 *
	 * Side effects: registers WordPress actions and filters.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		// Check for database migrations on admin_init.
		add_action( 'admin_init', [ $this, 'on_check_db_version' ] );

		// Register custom cron schedules.
		add_filter( 'cron_schedules', [ $this, 'filter_add_cron_schedules' ] );

		// Admin hooks — only load on admin pages.
		if ( is_admin() ) {
			$this->register_admin_hooks();
		}

		// Cron hooks — always registered so WP-Cron can fire them.
		$this->register_cron_hooks();

		// Frontend hooks — shortcode and REST API for public-facing widget.
		$this->register_frontend_hooks();

		// AJAX hooks — always registered so admin AJAX works.
		$this->register_ajax_hooks();

		/**
		 * Fires after PRAutoBlogger has finished registering all hooks.
		 *
		 * Useful for add-ons that need to hook into PRAutoBlogger's lifecycle.
		 */
		do_action( 'prautoblogger_loaded' );
	}

	/**
	 * Register admin-only hooks (settings page, notices, metabox, dashboard widget).
	 *
	 * @return void
	 */
	private function register_admin_hooks(): void {
		$admin_page = new PRAutoBlogger_Admin_Page();
		add_action( 'admin_menu', [ $admin_page, 'on_register_menu' ] );
		add_action( 'admin_init', [ $admin_page, 'on_register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $admin_page, 'on_enqueue_assets' ] );

		$notices = new PRAutoBlogger_Admin_Notices();
		add_action( 'admin_notices', [ $notices, 'on_display_notices' ] );

		$metabox = new PRAutoBlogger_Post_Metabox();
		add_action( 'add_meta_boxes', [ $metabox, 'on_register_metabox' ] );

		$metrics_page = new PRAutoBlogger_Metrics_Page();
		add_action( 'admin_menu', [ $metrics_page, 'on_register_menu' ] );

		$dashboard_widget = new PRAutoBlogger_Dashboard_Widget();
		add_action( 'wp_dashboard_setup', [ $dashboard_widget, 'on_register_widget' ] );

		$review_queue = new PRAutoBlogger_Review_Queue();
		add_action( 'admin_menu', [ $review_queue, 'on_register_menu' ] );

		$log_viewer = new PRAutoBlogger_Log_Viewer();
		add_action( 'admin_menu', [ $log_viewer, 'on_register_menu' ] );

		// Block false update notifications from wordpress.org — our plugin name
		// collides with a different "PRAutoBlogger" in the plugin directory.
		add_filter( 'site_transient_update_plugins', [ $this, 'filter_block_false_updates' ] );
	}

	/**
	 * Register frontend hooks (shortcode and REST API for posts widget).
	 *
	 * The shortcode renders a mount-point div; the React component hydrates it
	 * on the client side by fetching posts from the REST endpoint.
	 *
	 * @return void
	 */
	private function register_frontend_hooks(): void {
		$posts_widget = new PRAutoBlogger_Posts_Widget();
		add_action( 'init', [ $posts_widget, 'on_register_shortcode' ] );
		add_action( 'rest_api_init', [ $posts_widget, 'on_register_rest_route' ] );
	}

	/** Register cron-triggered hooks for scheduled generation and metrics. */
	private function register_cron_hooks(): void {
		add_action( 'prautoblogger_daily_generation', [ $this, 'on_daily_generation' ] );
		add_action( 'prautoblogger_collect_metrics', [ $this, 'on_collect_metrics' ] );

		// Model registry refresh — fires inside on_daily_generation() before the
		// generation lock, so a stuck lock doesn't freeze the registry too.
		$registry = $this->get_model_registry();
		add_action( 'prautoblogger_refresh_model_registry', [ $registry, 'refresh' ] );
	}

	/** Register AJAX handlers for admin actions. */
	private function register_ajax_hooks(): void {
		add_action( 'wp_ajax_prautoblogger_generate_now', [ $this, 'on_ajax_generate_now' ] );
		add_action( 'wp_ajax_prautoblogger_test_connection', [ $this, 'on_ajax_test_connection' ] );

		$review_queue = new PRAutoBlogger_Review_Queue();
		add_action( 'wp_ajax_prautoblogger_approve_post', [ $review_queue, 'on_ajax_approve_post' ] );
		add_action( 'wp_ajax_prautoblogger_reject_post', [ $review_queue, 'on_ajax_reject_post' ] );

		$log_viewer = new PRAutoBlogger_Log_Viewer();
		add_action( 'wp_ajax_prautoblogger_clear_logs', [ $log_viewer, 'on_ajax_clear_logs' ] );
	}

	/**
	 * Check if database needs migration after plugin update.
	 *
	 * Compares stored DB version with current PRAUTOBLOGGER_DB_VERSION.
	 * If different, re-runs activation to apply schema changes via dbDelta.
	 *
	 * Side effects: may update database schema and db_version option.
	 *
	 * @return void
	 */
	public function on_check_db_version(): void {
		$stored_version = get_option( 'prautoblogger_db_version', '0' );
		if ( version_compare( $stored_version, PRAUTOBLOGGER_DB_VERSION, '<' ) ) {
			PRAutoBlogger_Activator::activate();
		}

		// One-time migration: switch to Gemini 2.5 Flash Lite for cost/speed.
		if ( ! get_option( 'prautoblogger_migrated_gemini_flash_lite' ) ) {
			update_option( 'prautoblogger_analysis_model', PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL );
			update_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL );
			update_option( 'prautoblogger_editor_model', PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL );
			update_option( 'prautoblogger_migrated_gemini_flash_lite', '1' );
		}

		// One-time migration: re-wrap existing encrypted values with "enc:" prefix
		// to prevent the double-encryption bug permanently.
		if ( ! get_option( 'prautoblogger_migrated_enc_prefix' ) ) {
			$enc_options = [ 'prautoblogger_openrouter_api_key', 'prautoblogger_ga4_credentials_json' ];
			foreach ( $enc_options as $opt ) {
				$val = get_option( $opt, '' );
				// If it has a value but no "enc:" prefix, it's a legacy encrypted value.
				// Clear it — user must re-enter. We can't reliably tell if it's
				// single or double encrypted, so a fresh entry is safest.
				if ( '' !== $val && ! PRAutoBlogger_Encryption::is_encrypted( $val ) ) {
					delete_option( $opt );
				}
			}
			update_option( 'prautoblogger_migrated_enc_prefix', '1' );
		}
	}

	/**
	 * Add custom cron schedules (six-hourly for metrics collection).
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 *
	 * @return array<string, array{interval: int, display: string}> Modified schedules.
	 */
	public function filter_add_cron_schedules( array $schedules ): array {
		$schedules['prautoblogger_six_hours'] = [
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every Six Hours', 'prautoblogger' ),
		];
		return $schedules;
	}

	/**
	 * Block false update notifications from wordpress.org.
	 *
	 * Our plugin slug may collide with a different plugin in the directory.
	 * This filter removes any update notice that wordpress.org might push
	 * for our plugin basename, since we manage updates via our own CI/CD.
	 *
	 * @param object $transient The update_plugins transient data.
	 *
	 * @return object Modified transient with our plugin removed from updates.
	 */
	public function filter_block_false_updates( $transient ) {
		$plugin_basename = PRAUTOBLOGGER_PLUGIN_BASENAME;
		if ( isset( $transient->response[ $plugin_basename ] ) ) {
			unset( $transient->response[ $plugin_basename ] );
		}
		return $transient;
	}

	/**
	 * Handle the daily generation cron event.
	 *
	 * Uses a database-level atomic mutex to prevent overlapping runs.
	 *
	 * Side effects: API calls, database writes, WordPress post creation.
	 *
	 * @return void
	 */
	public function on_daily_generation(): void {
		// Refresh the OpenRouter model registry (idempotent, skips if <12h old).
		// Fires BEFORE the generation lock so a stuck lock doesn't block the refresh.
		do_action( 'prautoblogger_refresh_model_registry', false );

		if ( ! $this->acquire_generation_lock() ) {
			PRAutoBlogger_Logger::instance()->info( 'Daily generation skipped: already running (lock held).', 'scheduler' );
			return;
		}

		try {
			( new PRAutoBlogger_Pipeline_Runner() )->run();
		} catch ( \Exception $e ) {
			PRAutoBlogger_Logger::instance()->error( 'Daily generation FAILED: ' . $e->getMessage(), 'scheduler' );
		} finally {
			$this->release_generation_lock();
		}
	}

	/**
	 * Handle the metrics collection cron event.
	 *
	 * Side effects: API calls to GA4, database writes to ab_content_scores.
	 *
	 * @return void
	 */
	public function on_collect_metrics(): void {
		try {
			$metrics = new PRAutoBlogger_Metrics_Collector();
			$metrics->collect_all();
		} catch ( \Exception $e ) {
			PRAutoBlogger_Logger::instance()->error( 'Metrics collection FAILED: ' . $e->getMessage(), 'metrics' );
		}
	}

	/**
	 * AJAX handler: trigger manual generation.
	 *
	 * Verifies nonce and capability, then runs the generation pipeline.
	 * Uses the same atomic DB lock as the cron handler.
	 *
	 * Side effects: API calls, database writes, WordPress post creation.
	 *
	 * @return void
	 */
	public function on_ajax_generate_now(): void {
		check_ajax_referer( 'prautoblogger_generate_now', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ], 403 );
			return;
		}

		// LLM API calls can take 30-60+ seconds each. Extend PHP's execution
		// limit so the pipeline isn't killed mid-generation. This may be
		// blocked by Hostinger's hardcoded limits, but it's worth trying.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		// Allow admins to force-clear a stale lock.
		$force = isset( $_POST['force'] ) && '1' === $_POST['force'];
		if ( $force ) {
			$this->release_generation_lock();
		}

		if ( ! $this->acquire_generation_lock() ) {
			wp_send_json_error( [ 'message' => __( 'A generation run is already in progress. Pass force=1 to clear the lock.', 'prautoblogger' ) ] );
			return;
		}

		try {
			// Manual runs skip dedup so "Generate Now" always produces content
			// even when a related article was recently published.
			$results = ( new PRAutoBlogger_Pipeline_Runner() )
				->set_skip_dedup( true )
				->run();
			wp_send_json_success( $results );
		} catch ( \Exception $e ) {
			PRAutoBlogger_Logger::instance()->error( 'Manual generation FAILED: ' . $e->getMessage(), 'pipeline' );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		} finally {
			$this->release_generation_lock();
		}
	}

	/** AJAX handler: test API connections (OpenRouter, Reddit). */
	public function on_ajax_test_connection(): void {
		check_ajax_referer( 'prautoblogger_test_connection', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ], 403 );
			return;
		}

		$service = isset( $_POST['service'] ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : '';
		$results = [];

		if ( 'openrouter' === $service || 'all' === $service ) {
			$llm                   = new PRAutoBlogger_OpenRouter_Provider();
			$results['openrouter'] = $llm->validate_credentials_detailed();
		}
		if ( 'reddit' === $service || 'all' === $service ) {
			$reddit  = new PRAutoBlogger_Reddit_Provider();
			$is_ok   = $reddit->validate_credentials();
			$results['reddit'] = $is_ok
				? [ 'status' => 'ok', 'message' => __( 'Reddit source available (RSS primary, .json fallback).', 'prautoblogger' ) ]
				: [ 'status' => 'error', 'message' => __( 'Reddit sources unreachable (both RSS and .json failed).', 'prautoblogger' ) ];
		}
		wp_send_json_success( $results );
	}

	/**
	 * Acquire a database-level atomic mutex for generation.
	 *
	 * Uses INSERT IGNORE into wp_options — option_name has a UNIQUE index so only
	 * one process can insert the row. Expired locks (>1 hour) are cleaned up first.
	 *
	 * @return bool True if lock acquired, false if another process holds it.
	 */
	private function acquire_generation_lock(): bool {
		global $wpdb;

		$lock_name = 'prautoblogger_generation_lock';
		$now       = (string) time();

		// Clean up expired locks older than 1 hour — prevents permanent deadlock.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				$lock_name,
				time() - HOUR_IN_SECONDS
			)
		);

		// Atomic insert — UNIQUE constraint on option_name guarantees only one process succeeds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$lock_name,
				$now,
				'no'
			)
		);

		return $result > 0;
	}

	/**
	 * Lazy-load the OpenRouter model registry singleton.
	 *
	 * Config (option names, transient names) is injected here — the registry
	 * class itself has no knowledge of PRAUTOBLOGGER_* constants.
	 *
	 * @return PRAutoBlogger_OpenRouter_Model_Registry
	 */
	public function get_model_registry(): PRAutoBlogger_OpenRouter_Model_Registry {
		if ( null === $this->model_registry ) {
			$this->model_registry = new PRAutoBlogger_OpenRouter_Model_Registry(
				'prautoblogger_openrouter_model_registry',
				'prautoblogger_openrouter_model_registry_cache'
			);
		}
		return $this->model_registry;
	}

	/** Release the generation mutex. */
	private function release_generation_lock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				'prautoblogger_generation_lock'
			)
		);
	}

}
