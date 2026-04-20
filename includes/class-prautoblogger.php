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
 * Dependencies: All other plugin classes (loaded via autoloader), PRAutoBlogger_Executor.
 *
 * @see prautoblogger.php                     — Plugin bootstrap that instantiates this class.
 * @see class-executor.php                    — Cron/AJAX handlers for generation, model registry.
 * @see class-ajax-handlers.php               — Non-generation AJAX endpoints (images, models, test).
 * @see class-generation-lock.php             — DB mutex for single-writer generation.
 * @see core/class-pipeline-runner.php         — Executes the generation pipeline.
 * @see ARCHITECTURE.md                        — Data flow diagram showing how classes interact.
 * @see CONVENTIONS.md                         — Hook naming conventions.
 */
class PRAutoBlogger {

	private bool $initialized = false;

	/** @var PRAutoBlogger_Executor Handles generation cron/AJAX, model registry. */
	private PRAutoBlogger_Executor $executor;

	/** @var PRAutoBlogger_Ajax_Handlers Handles non-generation AJAX (images, models, test). */
	private PRAutoBlogger_Ajax_Handlers $ajax_handlers;

	/**
	 * Register all hooks and initialize the plugin.
	 * Called once on `plugins_loaded`. Idempotent.
	 *
	 * Side effects: registers WordPress actions and filters.
	 */
	public function run(): void {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized  = true;
		$this->executor     = new PRAutoBlogger_Executor();
		$this->ajax_handlers = new PRAutoBlogger_Ajax_Handlers( $this->executor->get_model_registry() );

		add_action( 'admin_init', [ $this, 'on_check_db_version' ] );
		add_filter( 'cron_schedules', [ $this, 'filter_add_cron_schedules' ] );

		if ( is_admin() ) {
			$this->register_admin_hooks();
		}

		$this->register_cron_hooks();
		$this->register_frontend_hooks();
		$this->register_ajax_hooks();

		/** Fires after PRAutoBlogger has finished registering all hooks. */
		do_action( 'prautoblogger_loaded' );
	}

	/** Register admin-only hooks (settings, notices, metabox, dashboard widget). */
	private function register_admin_hooks(): void {
		$admin_page = new PRAutoBlogger_Admin_Page();
		add_action( 'admin_menu', [ $admin_page, 'on_register_menu' ] );
		add_action( 'admin_init', [ $admin_page, 'on_register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $admin_page, 'on_enqueue_assets' ] );

		add_action( 'admin_notices', [ new PRAutoBlogger_Admin_Notices(), 'on_display_notices' ] );
		add_action( 'add_meta_boxes', [ new PRAutoBlogger_Post_Metabox(), 'on_register_metabox' ] );
		add_action( 'admin_menu', [ new PRAutoBlogger_Metrics_Page(), 'on_register_menu' ] );
		add_action( 'wp_dashboard_setup', [ new PRAutoBlogger_Dashboard_Widget(), 'on_register_widget' ] );

		$review_queue = new PRAutoBlogger_Review_Queue();
		add_action( 'admin_menu', [ $review_queue, 'on_register_menu' ] );

		$ideas_browser = new PRAutoBlogger_Ideas_Browser();
		add_action( 'admin_menu', [ $ideas_browser, 'on_register_menu' ] );
		add_action( 'admin_menu', [ new PRAutoBlogger_Log_Viewer(), 'on_register_menu' ] );

		( new PRAutoBlogger_Post_List_Columns() )->register();

		// Block false update notifications — our slug collides with another plugin.
		add_filter( 'site_transient_update_plugins', [ $this, 'filter_block_false_updates' ] );
	}

	/** Register frontend hooks (shortcode, REST, typography). */
	private function register_frontend_hooks(): void {
		$posts_widget = new PRAutoBlogger_Posts_Widget();
		add_action( 'init', [ $posts_widget, 'on_register_shortcode' ] );
		add_action( 'rest_api_init', [ $posts_widget, 'on_register_rest_route' ] );

		$typography = new PRAutoBlogger_Article_Typography();
		add_action( 'wp_head', [ $typography, 'on_wp_head' ] );
		add_action( 'wp_enqueue_scripts', [ $typography, 'on_enqueue_fonts' ] );
	}

	/** Register cron-triggered hooks for scheduled generation and metrics. */
	private function register_cron_hooks(): void {
		add_action( 'prautoblogger_daily_generation', [ $this->executor, 'on_daily_generation' ] );
		add_action( 'prautoblogger_collect_metrics', [ $this->executor, 'on_collect_metrics' ] );

		// Manual "Generate Now" runs as a one-shot cron event to avoid
		// Hostinger's 120-second web-server connection timeout.
		add_action( 'prautoblogger_manual_generation', [ $this->executor, 'on_manual_generation' ] );

		// Chained article generation — each queued article fires as its own
		// cron event so it gets a fresh PHP process and execution time budget.
		add_action(
			PRAutoBlogger_Pipeline_Runner::CRON_ACTION,
			[ $this->executor, 'on_process_article_queue' ]
		);

		$registry = $this->executor->get_model_registry();
		add_action( 'prautoblogger_refresh_model_registry', [ $registry, 'refresh' ] );

		// Single-idea generation from the Ideas browser page.
		add_action( 'prautoblogger_generate_from_idea', [ 'PRAutoBlogger_Ideas_Browser', 'on_cron_generate_from_idea' ] );
	}

	/** Register AJAX handlers for admin actions. */
	private function register_ajax_hooks(): void {
		// Generation AJAX (start + status polling) stays on executor.
		add_action( 'wp_ajax_prautoblogger_generate_now', [ $this->executor, 'on_ajax_generate_now' ] );
		add_action( 'wp_ajax_prautoblogger_generation_status', [ $this->executor, 'on_ajax_generation_status' ] );

		// Non-generation AJAX (images, models, test) on dedicated handler.
		add_action( 'wp_ajax_prautoblogger_generate_image', [ $this->ajax_handlers, 'on_ajax_generate_image' ] );
		add_action( 'wp_ajax_prautoblogger_test_connection', [ $this->ajax_handlers, 'on_ajax_test_connection' ] );
		add_action( 'wp_ajax_prautoblogger_get_models', [ $this->ajax_handlers, 'on_ajax_get_models' ] );

		$review_queue = new PRAutoBlogger_Review_Queue();
		add_action( 'wp_ajax_prautoblogger_approve_post', [ $review_queue, 'on_ajax_approve_post' ] );
		add_action( 'wp_ajax_prautoblogger_reject_post', [ $review_queue, 'on_ajax_reject_post' ] );

		add_action( 'wp_ajax_prautoblogger_clear_logs', [ new PRAutoBlogger_Log_Viewer(), 'on_ajax_clear_logs' ] );

		$ideas = new PRAutoBlogger_Ideas_Browser();
		add_action( 'wp_ajax_prautoblogger_generate_from_idea', [ $ideas, 'on_ajax_generate_from_idea' ] );
		add_action( 'wp_ajax_prautoblogger_idea_gen_status', [ $ideas, 'on_ajax_idea_gen_status' ] );
	}

	/**
	 * Check if database needs migration after plugin update.
	 * Compares stored DB version with PRAUTOBLOGGER_DB_VERSION; re-runs activation if different.
	 *
	 * Side effects: may update database schema and db_version option.
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

		// One-time migration (v3): switch to single-panel newspaper comic style.
		// Replaces both the old infomercial pastiche and the short-lived premium
		// photography style. Force-update unless the user has a truly custom value.
		if ( ! get_option( 'prautoblogger_migrated_style_suffix_v3' ) ) {
			$known_old_prefixes = [
				'Style: a screengrab from a 1995',       // v1: infomercial.
				'Style: premium scientific lifestyle',    // v2: photography.
			];
			$current = get_option( 'prautoblogger_image_style_suffix', '' );
			$is_old  = ( '' === $current );
			foreach ( $known_old_prefixes as $prefix ) {
				if ( false !== strpos( $current, $prefix ) ) {
					$is_old = true;
					break;
				}
			}
			if ( $is_old ) {
				update_option( 'prautoblogger_image_style_suffix', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX );
			}
			update_option( 'prautoblogger_migrated_style_suffix_v3', '1' );
		}

		// One-time migration (v4): remove caption-in-image instruction from style suffix.
		// Captions are now inserted as HTML below the image, not baked into the image.
		if ( ! get_option( 'prautoblogger_migrated_style_suffix_v4' ) ) {
			$current = get_option( 'prautoblogger_image_style_suffix', '' );
			// Detect v3 style suffix (contains "caption text" instruction).
			if ( false !== strpos( $current, 'caption text in a clean sans-serif font below the panel' ) ) {
				update_option( 'prautoblogger_image_style_suffix', PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX );
			}
			update_option( 'prautoblogger_migrated_style_suffix_v4', '1' );
		}

		// One-time migration: re-wrap existing encrypted values with "enc:" prefix.
		if ( ! get_option( 'prautoblogger_migrated_enc_prefix' ) ) {
			$enc_options = [ 'prautoblogger_openrouter_api_key', 'prautoblogger_ga4_credentials_json' ];
			foreach ( $enc_options as $opt ) {
				$val = get_option( $opt, '' );
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
	 * @return array<string, array{interval: int, display: string}>
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
	 * Our plugin slug may collide with a different plugin in the directory.
	 *
	 * @param object $transient The update_plugins transient data.
	 * @return object Modified transient.
	 */
	public function filter_block_false_updates( $transient ) {
		if ( isset( $transient->response[ PRAUTOBLOGGER_PLUGIN_BASENAME ] ) ) {
			unset( $transient->response[ PRAUTOBLOGGER_PLUGIN_BASENAME ] );
		}
		return $transient;
	}

	/** Expose the executor for external access (e.g., model registry). */
	public function get_executor(): PRAutoBlogger_Executor {
		return $this->executor;
	}

	// ── Backward-compatible proxies ──

	/** @see PRAutoBlogger_Executor::on_daily_generation() */
	public function on_daily_generation(): void { $this->executor->on_daily_generation(); }

	/** @see PRAutoBlogger_Executor::on_collect_metrics() */
	public function on_collect_metrics(): void { $this->executor->on_collect_metrics(); }

	/** @see PRAutoBlogger_Executor::on_ajax_generate_now() */
	public function on_ajax_generate_now(): void { $this->executor->on_ajax_generate_now(); }

	/** @see PRAutoBlogger_Ajax_Handlers::on_ajax_test_connection() */
	public function on_ajax_test_connection(): void { $this->ajax_handlers->on_ajax_test_connection(); }

	/** @see PRAutoBlogger_Executor::get_model_registry() */
	public function get_model_registry(): PRAutoBlogger_OpenRouter_Model_Registry { return $this->executor->get_model_registry(); }
}
