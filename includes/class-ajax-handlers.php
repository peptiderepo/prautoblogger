<?php
declare(strict_types=1);

/**
 * AJAX endpoint handlers for PRAutoBlogger admin actions.
 *
 * What: Handles image generation, model registry, and connection test AJAX requests.
 * Who calls it: PRAutoBlogger registers wp_ajax hooks that delegate to methods here.
 * Dependencies: PRAutoBlogger_Image_Pipeline, PRAutoBlogger_Source_Collector,
 *               PRAutoBlogger_OpenRouter_Provider, PRAutoBlogger_Reddit_Provider,
 *               PRAutoBlogger_OpenRouter_Model_Registry, PRAutoBlogger_Logger.
 *
 * @see class-prautoblogger.php — Hook registration that wires these handlers.
 * @see class-executor.php      — Generation-related AJAX lives there (generate_now, status).
 */
class PRAutoBlogger_Ajax_Handlers {

	/** @var PRAutoBlogger_OpenRouter_Model_Registry Model registry instance (shared with Executor). */
	private PRAutoBlogger_OpenRouter_Model_Registry $model_registry;

	/**
	 * @param PRAutoBlogger_OpenRouter_Model_Registry $model_registry Shared model registry.
	 */
	public function __construct( PRAutoBlogger_OpenRouter_Model_Registry $model_registry ) {
		$this->model_registry = $model_registry;
	}

	/**
	 * AJAX handler: generate images for existing posts that lack them.
	 *
	 * Accepts a `post_id` parameter. Generates Image A (article-driven) and
	 * sets it as the featured image. Useful for retroactively adding images
	 * to posts published before image generation was enabled/fixed.
	 *
	 * Side effects: image provider API call, media library write, post meta update.
	 */
	public function on_ajax_generate_image(): void {
		check_ajax_referer( 'prautoblogger_generate_image', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ], 403 );
			return;
		}

		// Image generation can take 20-30s (Runware FLUX + OpenRouter image models).
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

			// The pipeline sets featured image and Image B meta internally.
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

		$models = $this->model_registry->get_models();

		// Auto-refresh if the registry has never been populated.
		if ( empty( $models ) ) {
			$this->model_registry->refresh( true );
			$models = $this->model_registry->get_models();
		}

		wp_send_json_success( $models );
	}

	/**
	 * AJAX handler: test API connections (OpenRouter, Reddit).
	 *
	 * Accepts a `service` parameter: 'openrouter', 'reddit', or 'all'.
	 *
	 * Side effects: API calls to OpenRouter and/or Reddit to validate credentials.
	 */
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
}
