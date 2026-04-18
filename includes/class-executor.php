<?php
declare(strict_types=1);

/**
 * Execution handlers for content generation and metrics collection.
 *
 * What: Cron handlers, generation AJAX (start + status polling), model registry.
 * Who calls it: PRAutoBlogger registers hooks that delegate here.
 * Dependencies: Pipeline_Runner, Metrics_Collector, Generation_Lock, Logger, Model_Registry.
 *
 * @see class-prautoblogger.php          — Hook registration that wires these handlers.
 * @see core/class-pipeline-runner.php    — Actual generation pipeline.
 * @see class-generation-lock.php         — DB mutex extracted from this class.
 * @see class-ajax-handlers.php           — Non-generation AJAX endpoints (images, models, test).
 */
class PRAutoBlogger_Executor {

	/** @var PRAutoBlogger_OpenRouter_Model_Registry|null Lazy-loaded singleton. */
	private ?PRAutoBlogger_OpenRouter_Model_Registry $model_registry = null;

	/** Transient key for background generation status. */
	private const STATUS_TRANSIENT = 'prautoblogger_generation_status';

	/** How long to keep the result available for polling (seconds). */
	private const STATUS_TTL = 600;

	/**
	 * Handle the daily generation cron event.
	 * Uses an atomic DB mutex to prevent overlapping runs.
	 *
	 * Side effects: API calls, database writes, WordPress post creation.
	 */
	public function on_daily_generation(): void {
		// Refresh model registry before lock — stuck lock shouldn't block refresh.
		do_action( 'prautoblogger_refresh_model_registry', false );

		if ( ! PRAutoBlogger_Generation_Lock::acquire() ) {
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
			PRAutoBlogger_Generation_Lock::release();
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
			PRAutoBlogger_Generation_Lock::release();
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
		// Keep PHP running even after LiteSpeed closes the HTTP connection.
		// On Hostinger shared hosting, the web server kills HTTP connections
		// at 120 seconds, but ignore_user_abort lets the PHP process continue.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ignore_user_abort( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		if ( ! PRAutoBlogger_Generation_Lock::acquire() ) {
			set_transient( self::STATUS_TRANSIENT, [
				'status'  => 'error',
				'message' => __( 'Could not acquire generation lock. Another run may be in progress.', 'prautoblogger' ),
			], self::STATUS_TTL );
			return;
		}

		try {
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
			PRAutoBlogger_Generation_Lock::release();
		}
	}

	/**
	 * AJAX handler: return current generation status for frontend polling.
	 *
	 * Lightweight endpoint — reads a transient and returns it. Called every
	 * few seconds by admin.js while generation is in progress.
	 *
	 * Includes fallback detection: if the pipeline produced a post but the
	 * PHP process was killed before updating the transient (Hostinger's
	 * 120-second timeout), this endpoint detects the orphaned state and
	 * recovers gracefully after 180 seconds.
	 *
	 * Side effects: may delete stale transient and lock on recovery.
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

		// Fallback: detect orphaned "running" state when PHP process was killed.
		if ( 'running' === $status['status'] ) {
			$started = $status['started'] ?? 0;
			$elapsed = time() - $started;

			// After 10 minutes, give up unconditionally.
			if ( $elapsed > self::STATUS_TTL ) {
				delete_transient( self::STATUS_TRANSIENT );
				wp_send_json_success( [
					'status'  => 'error',
					'message' => __( 'Generation timed out. Check the Activity Log for details.', 'prautoblogger' ),
				] );
				return;
			}

			// After 5 minutes, check if pipeline produced output despite the kill.
			// Pipeline with 3 articles + images legitimately takes 3–4 minutes,
			// so we wait 5 minutes before declaring it orphaned.
			if ( $elapsed > 300 ) {
				$recent = get_posts( [
					'post_type'      => 'post',
					'post_status'    => [ 'publish', 'draft' ],
					'posts_per_page' => 1,
					'date_query'     => [ [ 'after' => gmdate( 'Y-m-d H:i:s', $started ) ] ],
					'meta_key'       => '_prautoblogger_run_id',
					'orderby'        => 'date',
					'order'          => 'DESC',
				] );
				delete_transient( self::STATUS_TRANSIENT );
				PRAutoBlogger_Generation_Lock::release();

				if ( ! empty( $recent ) ) {
					wp_send_json_success( [
						'status'    => 'complete',
						'generated' => 1,
						'published' => 1,
						'rejected'  => 0,
						'cost'      => 0.0,
						'note'      => __( 'Generation completed (status recovered).', 'prautoblogger' ),
					] );
					return;
				}
				wp_send_json_success( [
					'status'  => 'error',
					'message' => __( 'Generation process ended without producing content. Check Activity Log.', 'prautoblogger' ),
				] );
				return;
			}
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
}
