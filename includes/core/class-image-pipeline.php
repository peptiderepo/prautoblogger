<?php
declare(strict_types=1);

/**
 * Orchestrates image generation for published articles.
 *
 * Generates two images per article (A/B experiment):
 *   - Image A: Article-driven prompt → featured image
 *   - Image B: Source-driven prompt → post meta (_prautoblogger_image_b_id)
 *
 * Each image is independently fallible — if A fails, still try B. If both fail,
 * the article is published without images (graceful degradation).
 *
 * Triggered by: PRAutoBlogger_Pipeline_Runner after publisher creates the post.
 * Dependencies: PRAutoBlogger_Image_Provider_Interface (concrete provider),
 *               PRAutoBlogger_Image_Prompt_Builder, PRAutoBlogger_Image_Media_Sideloader,
 *               PRAutoBlogger_Cost_Tracker, PRAutoBlogger_Logger.
 *
 * @see core/class-pipeline-runner.php       — Calls this after publisher.
 * @see core/class-publisher.php             — Sets featured image + post meta.
 * @see core/class-image-prompt-builder.php  — Prompt generation.
 * @see core/class-image-media-sideloader.php — Media library integration.
 * @see ARCHITECTURE.md                      — Image pipeline data flow.
 */
class PRAutoBlogger_Image_Pipeline {

	/**
	 * Default image dimensions (landscape).
	 */
	// FLUX.1 requires both dimensions to be divisible by 8.
	// 1200×632 is the closest 8-aligned pair to the standard OG image size (1200×630).
	private const DEFAULT_WIDTH  = 1200;
	private const DEFAULT_HEIGHT = 632;

	/**
	 * The image provider (FLUX.1 via Cloudflare or another).
	 *
	 * @var PRAutoBlogger_Image_Provider_Interface
	 */
	private PRAutoBlogger_Image_Provider_Interface $provider;

	/**
	 * Prompt builder.
	 *
	 * @var PRAutoBlogger_Image_Prompt_Builder
	 */
	private PRAutoBlogger_Image_Prompt_Builder $prompt_builder;

	/**
	 * Media library sideloader.
	 *
	 * @var PRAutoBlogger_Image_Media_Sideloader
	 */
	private PRAutoBlogger_Image_Media_Sideloader $sideloader;

	/**
	 * Cost tracker for logging spend.
	 *
	 * @var PRAutoBlogger_Cost_Tracker
	 */
	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	/**
	 * Construct with dependencies.
	 *
	 * When no provider is injected, the constructor reads the
	 * `prautoblogger_image_provider` setting and instantiates the
	 * matching concrete class ('openrouter' or 'cloudflare').
	 *
	 * @param PRAutoBlogger_Image_Provider_Interface|null $provider Optional provider override.
	 * @param PRAutoBlogger_Cost_Tracker|null             $cost_tracker Optional cost tracker.
	 */
	public function __construct(
		?PRAutoBlogger_Image_Provider_Interface $provider = null,
		?PRAutoBlogger_Cost_Tracker $cost_tracker = null
	) {
		$this->provider         = $provider ?? self::create_default_provider();
		$this->prompt_builder   = new PRAutoBlogger_Image_Prompt_Builder();
		$this->sideloader       = new PRAutoBlogger_Image_Media_Sideloader();
		$this->cost_tracker     = $cost_tracker ?? new PRAutoBlogger_Cost_Tracker();
	}

	/**
	 * Instantiate the image provider based on the admin setting.
	 *
	 * @return PRAutoBlogger_Image_Provider_Interface
	 */
	private static function create_default_provider(): PRAutoBlogger_Image_Provider_Interface {
		$provider_id = (string) get_option( 'prautoblogger_image_provider', PRAUTOBLOGGER_DEFAULT_IMAGE_PROVIDER );
		if ( 'openrouter' === $provider_id ) {
			return new PRAutoBlogger_OpenRouter_Image_Provider();
		}
		return new PRAutoBlogger_Cloudflare_Image_Provider();
	}

	/**
	 * Generate and attach images to a published post.
	 *
	 * Generates Image A (article-driven) and Image B (source-driven). If enabled
	 * in settings. Each image is independently fallible.
	 *
	 * @param int                    $post_id Post ID to attach images to.
	 * @param array{
	 *     post_title?: string,
	 *     post_content?: string,
	 *     suggested_title?: string,
	 * }                           $article_data Article title + content.
	 * @param array{
	 *     title?: string,
	 *     selftext?: string,
	 *     comments?: string[],
	 * }|null                      $source_data Optional Reddit source data for Image B.
	 *
	 * @return array{
	 *     image_a_id?: int,
	 *     image_b_id?: int,
	 *     cost_usd: float,
	 *     errors: string[],
	 * } Attachment IDs (if successful) and cost/errors.
	 */
	public function generate_and_attach_images(
		int $post_id,
		array $article_data,
		?array $source_data = null
	): array {
		$result = [
			'cost_usd' => 0.0,
			'errors'   => [],
		];

		// Early exit if image generation is disabled.
		if ( ! get_option( 'prautoblogger_image_enabled' ) ) {
			PRAutoBlogger_Logger::instance()->info( 'Image generation disabled in settings.', 'image_pipeline' );
			return $result;
		}

		// Generate Image A (article-driven prompt).
		$image_a_result = $this->generate_image_a( $post_id, $article_data );
		if ( ! is_wp_error( $image_a_result ) && isset( $image_a_result['attachment_id'] ) ) {
			$result['image_a_id'] = $image_a_result['attachment_id'];
		} else {
			$result['errors'][] = is_wp_error( $image_a_result )
				? $image_a_result->get_error_message()
				: 'Image A generation produced no attachment ID.';
		}
		if ( ! is_wp_error( $image_a_result ) ) {
			$result['cost_usd'] += $image_a_result['cost_usd'] ?? 0.0;
		}

		// Generate Image B (source-driven prompt) if source data is available.
		if ( null !== $source_data && ! empty( $source_data ) ) {
			$image_b_result = $this->generate_image_b( $post_id, $source_data );
			if ( ! is_wp_error( $image_b_result ) && isset( $image_b_result['attachment_id'] ) ) {
				$result['image_b_id'] = $image_b_result['attachment_id'];
			} else {
				$result['errors'][] = is_wp_error( $image_b_result )
					? $image_b_result->get_error_message()
					: 'Image B generation produced no attachment ID.';
			}
			if ( ! is_wp_error( $image_b_result ) ) {
				$result['cost_usd'] += $image_b_result['cost_usd'] ?? 0.0;
			}
		}

		return $result;
	}

	/**
	 * Generate Image A from article content.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $article_data Article data.
	 *
	 * @return array{
	 *     attachment_id?: int,
	 *     cost_usd: float,
	 * }|\WP_Error
	 */
	private function generate_image_a( int $post_id, array $article_data ) {
		try {
			// Build the article-driven prompt.
			$prompt = $this->prompt_builder->build_article_prompt( $article_data );

			// Estimate cost before generating.
			$estimated_cost = $this->provider->estimate_cost( self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT );
			if ( $this->cost_tracker->would_exceed_budget( $estimated_cost ) ) {
				return new \WP_Error(
					'budget_exceeded',
					'Image generation would exceed monthly budget.'
				);
			}

			// Generate the image.
			$image_data = $this->provider->generate_image( $prompt, self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT );

			// Sideload the image into media library.
			$attachment_id = $this->sideloader->sideload_image(
				$image_data,
				$post_id,
				substr( $prompt, 0, 100 ) // Use first 100 chars of prompt as alt text.
			);

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			// Log the cost.
			$this->cost_tracker->log_image_generation(
				$image_data['cost_usd'],
				$image_data['model'] ?? 'unknown',
				$post_id,
				'image_a'
			);

			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Image A generated for post %d (attachment %d, cost $%.4f)', $post_id, $attachment_id, $image_data['cost_usd'] ),
				'image_pipeline'
			);

			return [
				'attachment_id' => $attachment_id,
				'cost_usd'      => $image_data['cost_usd'],
			];
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Image A %s: %s', get_class( $e ), $e->getMessage() ),
				'image_pipeline'
			);

			return new \WP_Error( 'image_generation_failed', $e->getMessage() );
		}
	}

	/**
	 * Generate Image B from source data.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $source_data Source Reddit data.
	 *
	 * @return array{
	 *     attachment_id?: int,
	 *     cost_usd: float,
	 * }|\WP_Error
	 */
	private function generate_image_b( int $post_id, array $source_data ) {
		try {
			// Build the source-driven prompt.
			$prompt = $this->prompt_builder->build_source_prompt( $source_data );

			// Estimate cost before generating.
			$estimated_cost = $this->provider->estimate_cost( self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT );
			if ( $this->cost_tracker->would_exceed_budget( $estimated_cost ) ) {
				return new \WP_Error(
					'budget_exceeded',
					'Image generation would exceed monthly budget.'
				);
			}

			// Generate the image.
			$image_data = $this->provider->generate_image( $prompt, self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT );

			// Sideload the image into media library.
			$attachment_id = $this->sideloader->sideload_image(
				$image_data,
				$post_id,
				substr( $prompt, 0, 100 ) // Use first 100 chars of prompt as alt text.
			);

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			// Log the cost.
			$this->cost_tracker->log_image_generation(
				$image_data['cost_usd'],
				$image_data['model'] ?? 'unknown',
				$post_id,
				'image_b'
			);

			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Image B generated for post %d (attachment %d, cost $%.4f)', $post_id, $attachment_id, $image_data['cost_usd'] ),
				'image_pipeline'
			);

			return [
				'attachment_id' => $attachment_id,
				'cost_usd'      => $image_data['cost_usd'],
			];
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Image B %s: %s', get_class( $e ), $e->getMessage() ),
				'image_pipeline'
			);

			return new \WP_Error( 'image_generation_failed', $e->getMessage() );
		}
	}
}
