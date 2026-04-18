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
		} catch ( \Exception $e ) {
			PRAutoBlogger_Logger::instance()->error( 'Daily generation FAILED: ' . $e->getMessage(), 'scheduler' );
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
		} catch ( \Exception $e ) {
			PRAutoBlogger_Logger::instance()->error( 'Metrics collection FAILED: ' . $e->getMessage(), 'metrics' );
		}
	}

	/**
	 * AJAX handler: trigger manual generation.
	 * Verifies nonce/capability, then runs the pipeline with the same DB lock.
	 *
	 * Side effects: API calls, database writes, WordPress post creation.
	 */
	public function on_ajax_generate_now(): void {
		check_ajax_referer( 'prautoblogger_generate_now', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ], 403 );
			return;
		}

		// LLM calls take 30-60+ seconds; extend PHP limit (may be blocked by host).
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		$force = isset( $_POST['force'] ) && '1' === $_POST['force'];
		if ( $force ) {
			$this->release_generation_lock();
		}

		if ( ! $this->acquire_generation_lock() ) {
			wp_send_json_error( [ 'message' => __( 'A generation run is already in progress. Pass force=1 to clear the lock.', 'prautoblogger' ) ] );
			return;
		}

		try {
			// Manual runs skip dedup so "Generate Now" always produces content.
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

			$result = $pipeline->generate_and_attach_images( $post_id, $article_data, $source_data );

			// Set featured image if Image A was generated.
			if ( ! empty( $result['image_a_id'] ) ) {
				set_post_thumbnail( $post_id, $result['image_a_id'] );
			}

			// Store Image B in post meta if generated.
			if ( ! empty( $result['image_b_id'] ) ) {
				update_post_meta( $post_id, '_prautoblogger_image_b_id', $result['image_b_id'] );
			}

			wp_send_json_success( $result );
		} catch ( \Exception $e ) {
			PRAutoBlogger_Logger::instance()->error(
				'Retroactive image gen failed for post ' . $post_id . ': ' . $e->getMessage(),
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
