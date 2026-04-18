<?php
declare(strict_types=1);

/**
 * Execution handlers for generation, metrics collection, and AJAX actions.
 *
 * What: Cron event handlers, AJAX endpoints, atomic generation lock, model registry access.
 * Who calls it: PRAutoBlogger registers hooks that delegate to methods on this class.
 * Dependencies: PRAutoBlogger_Pipeline_Runner, PRAutoBlogger_Metrics_Collector,
 *               PRAutoBlogger_Logger, PRAutoBlogger_OpenRouter_Provider, PRAutoBlogger_Reddit_Provider.
 *
 * @see class-prautoblogger.php       — Hook registration that wires these handlers.
 * @see core/class-pipeline-runner.php — Actual generation pipeline.
 */
class PRAutoBlogger_Executor {

	/** @var PRAutoBlogger_OpenRouter_Model_Registry|null Lazy-loaded singleton. */
	private ?PRAutoBlogger_OpenRouter_Model_Registry $model_registry = null;

	/**
	 * Handle the daily generation cron event.
	 * Uses an atomic DB mutex to prevent overlapping runs.
	 *
	 * Side effects: API calls, database writes, WordPress post creation.
	 */
	public function on_daily_generation(): void {
		// Refresh model registry before lock — stuck lock shouldn't block refresh.
		do_action( 'prautoblogger_refresh_model_registry', false );

		if ( ! $this->acquire_generation_lock() ) {
			PRAutoBlogger_Logger::instance()->info( 'Daily generation skipped: already running (lock held).', 'scheduler' );
			return;
		}

		try {
			( new PRAutoBlogger_Pipeline_Runner() )->run();
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Daily generation %s: %s', get_class( $e ), $e->getMessage() ),
				'scheduler'
			);
		} finally {
			$this->release_generation_lock();
		}
	}

	/**
	 * Handle the metrics collection cron event.
	 * Side effects: API calls to GA4, database writes to ab_content_scores.
	 */
	public function on_collect_metrics(): void {
		try {
			( new PRAutoBlogger_Metrics_Collector() )->collect_all();
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Metrics collection %s: %s', get_class( $e ), $e->getMessage() ),
				'metrics'
			);
		}
	}

	/** Transient key for background generation status. */
	private const STATUS_TRANSIENT = 'prautoblogger_generation_status';

	/** How long to keep the result available for polling (seconds). */
	private const STATUS_TTL = 600;

	/**
	 * AJAX handler: kick off manual generation as a background cron job.
	 *
	 * Returns immediately so the browser never hits Hostinger's 120-second
	 * connection timeout. The frontend polls on_ajax_generation_status()
	 * every few seconds to get progress and final results.
	 *
	 * Side effects: schedules a WP-Cron event, writes a status transient.
	 */
	public function on_ajax_generate_now(): void {
		check_ajax_referer( 'prautoblogger_generate_now', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ], 403 );
			return;
		}

		$force = isset( $_POST['force'] ) && '1' === $_POST['force'];
		if ( $force ) {
			$this->release_generation_lock();
			delete_transient( self::STATUS_TRANSIENT );
		}

		// Check if a run is already in progress.
		$current = get_transient( self::STATUS_TRANSIENT );
		if ( is_array( $current ) && 'running' === ( $current['status'] ?? '' ) ) {
			wp_send_json_success( [
				'background' => true,
				'message'    => __( 'Generation already in progress.', 'prautoblogger' ),
			] );
			return;
		}

		// Write initial "running" status for the frontend to poll.
		set_transient( self::STATUS_TRANSIENT, [
			'status'  => 'running',
			'stage'   => __( 'Starting generation...', 'prautoblogger' ),
			'started' => time(),
		], self::STATUS_TTL );

		// Schedule immediate one-shot cron event. WordPress will fire this
		// on the next page load (or via wp-cron.php) in a separate PHP process
		// that is not subject to the 120-second connection timeout.
		if ( ! wp_next_scheduled( 'prautoblogger_manual_generation' ) ) {
			wp_schedule_single_event( time(), 'prautoblogger_manual_generation' );
		}

		// Spawn the cron immediately via a non-blocking loopback request
		// so we don't depend on the next visitor to trigger it.
		// Some hosts (including Hostinger) set DISABLE_WP_CRON = true and
		// rely on an external cron runner. spawn_cron() respects that flag
		// and may no-op, so we also fire a direct loopback as a fallback.
		spawn_cron();

		// Direct non-blocking loopback to wp-cron.php — ensures the event
		// fires even if DISABLE_WP_CRON is true or spawn_cron() no-ops.
		wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ),
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
			]
		);

		wp_send_json_success( [
			'background' => true,
			'message'    => __( 'Generation started in background. Polling for status...', 'prautoblogger' ),
		] );
	}

	/**
	 * Cron handler for manual (background) generation.
	 *
	 * Runs in a separate PHP process triggered by WP-Cron, free from
	 * the web server's connection timeout. Writes progress and final
	 * results to a transient that the frontend polls.
	 *
	 * Side effects: API calls, database writes, WordPress post creation,
	 *               transient updates.
	 */
	public function on_manual_generation(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		if ( ! $this->acquire_generation_lock() ) {
			set_transient( self::STATUS_TRANSIENT, [
				'status'  => 'error',
				'message' => __( 'Could not acquire generation lock. Another run may be in progress.', 'prautoblogger' ),
			], self::STATUS_TTL );
			return;
		}

		try {
			// Update stage for frontend polling.
			$this->update_generation_stage( __( 'Collecting sources from Reddit...', 'prautoblogger' ) );

			$results = ( new PRAutoBlogger_Pipeline_Runner() )
				->set_skip_dedup( true )
				->run();

			set_transient( self::STATUS_TRANSIENT, [
				'status'    => 'complete',
				'generated' => $results['generated'],
				'published' => $results['published'],
				'rejected'  => $results['rejected'],
				'cost'      => $results['cost'],
			], self::STATUS_TTL );
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Manual generation %s: %s', get_class( $e ), $e->getMessage() ),
				'pipeline'
			);
			set_transient( self::STATUS_TRANSIENT, [
				'status'  => 'error',
				'message' => $e->getMessage(),
			], self::STATUS_TTL );
		} finally {
			$this->release_generation_lock();
		}
	}

	/**
	 * AJAX handler: return current generation status for frontend polling.
	 *
	 * Lightweight endpoint — reads a transient and returns it. Called every
	 * few seconds by admin.js while generation is in progress.
	 *
	 * Side effects: none.
	 */
	public function on_ajax_generation_status(): void {
		check_ajax_referer( 'prautoblogger_generate_now', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ], 403 );
			return;
		}

		$status = get_transient( self::STATUS_TRANSIENT );

		if ( ! is_array( $status ) ) {
			wp_send_json_success( [ 'status' => 'idle' ] );
			return;
		}

		// If still "running" but started more than 10 minutes ago, assume it died.
		if ( 'running' === $status['status'] && isset( $status['started'] ) && ( time() - $status['started'] ) > self::STATUS_TTL ) {
			delete_transient( self::STATUS_TRANSIENT );
			wp_send_json_success( [
				'status'  => 'error',
				'message' => __( 'Generation timed out. Check the Activity Log for details.', 'prautoblogger' ),
			] );
			return;
		}

		wp_send_json_success( $status );
	}

	/**
	 * Helper: update the generation stage for frontend polling.
	 *
	 * @param string $stage Human-readable stage description.
	 */
	private function update_generation_stage( string $stage ): void {
		$current = get_transient( self::STATUS_TRANSIENT );
		if ( is_array( $current ) ) {
			$current['stage'] = $stage;
			set_transient( self::STATUS_TRANSIENT, $current, self::STATUS_TTL );
		}
	}

	/**
	 * AJAX handler: generate images for existing posts that lack them.
	 *
	 * Accepts a `post_id` parameter. Generates Image A (article-driven) and
	 * sets it as the featured image. Useful for retroactively adding images
	 * to posts published before image generation was enabled/fixed.
	 *
	 * Side effects: Cloudflare API call, media library write, post meta update.
	 */
	public function on_ajax_generate_image(): void {
		check_ajax_referer( 'prautoblogger_generate_image', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ], 403 );
			return;
		}

		// Image generation can take 20-30s on Cloudflare Workers AI.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 120 );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( 0 === $post_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing post_id parameter.', 'prautoblogger' ) ] );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( [ 'message' => sprintf( __( 'Post %d not found.', 'prautoblogger' ), $post_id ) ] );
			return;
		}

		try {
			$pipeline     = new PRAutoBlogger_Image_Pipeline();
			$article_data = [
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
			];

			// Retrieve original source IDs from post meta so Image B can generate
			// a source-driven prompt even on retroactive regeneration.
			$source_ids_json = get_post_meta( $post_id, '_prautoblogger_source_ids', true );
			$source_ids      = is_string( $source_ids_json ) ? json_decode( $source_ids_json, true ) : [];
			$source_data     = ( new PRAutoBlogger_Source_Collector() )->get_source_data_for_image(
				is_array( $source_ids ) ? array_map( 'absint', $source_ids ) : []
			);

			// The pipeline now sets featured image and Image B meta
			// internally, immediately after each image generates.
			$result = $pipeline->generate_and_attach_images( $post_id, $article_data, $source_data );

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Retroactive image gen %s for post %d: %s', get_class( $e ), $post_id, $e->getMessage() ),
				'image_pipeline'
			);
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * AJAX handler: return the model registry for the admin model picker.
	 *
	 * Returns the full cached model list. If the registry is empty, triggers
	 * a refresh first. Called by the model-picker.js popup.
	 *
	 * Side effects: may trigger an OpenRouter API call if registry is empty.
	 */
	public function on_ajax_get_models(): void {
		check_ajax_referer( 'prautoblogger_get_models', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ], 403 );
			return;
		}

		$registry = $this->get_model_registry();
		$models   = $registry->get_models();

		// Auto-refresh if the registry has never been populated.
		if ( empty( $models ) ) {
			$registry->refresh( true );
			$models = $registry->get_models();
		}

		wp_send_json_success( $models );
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
			$results['openrouter'] = ( new PRAutoBlogger_OpenRouter_Provider() )->validate_credentials_detailed();
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
	 * Lazy-load the OpenRouter model registry singleton.
	 * Config injected here — registry class has no knowledge of PRAUTOBLOGGER_* constants.
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

	/**
	 * Acquire a database-level atomic mutex for generation.
	 * Uses INSERT IGNORE — option_name UNIQUE index guarantees single-writer.
	 * Expired locks (>1 hour) are cleaned up first.
	 *
	 * @return bool True if lock acquired.
	 */
	public function acquire_generation_lock(): bool {
		global $wpdb;

		$lock_name = 'prautoblogger_generation_lock';

		// Clean up expired locks to prevent permanent deadlock.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				$lock_name,
				time() - HOUR_IN_SECONDS
			)
		);

		// Atomic insert — UNIQUE constraint guarantees only one process succeeds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$lock_name,
				(string) time(),
				'no'
			)
		);

		return $result > 0;
	}

	/** Release the generation mutex. */
	public function release_generation_lock(): void {
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
