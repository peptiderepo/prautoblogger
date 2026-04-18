<?php
declare(strict_types=1);

/**
 * Orchestrates image generation for published articles.
 *
 * Generates two images per article (A/B): Image A (article-driven) as featured
 * image, Image B (source-driven) in post meta. Each is independently fallible.
 *
 * Triggered by: PRAutoBlogger_Pipeline_Runner after publisher creates the post.
 * Dependencies: Image provider, Prompt builder, Sideloader, Cost tracker, Logger.
 *
 * @see core/class-pipeline-runner.php       — Calls this after publisher.
 * @see core/class-image-prompt-builder.php  — Prompt generation (scene + caption).
 * @see core/class-image-media-sideloader.php — Media library integration.
 */
class PRAutoBlogger_Image_Pipeline {

	/**
	 * Default image dimensions (landscape).
	 */
	// FLUX.1 requires both dimensions to be divisible by 8.
	// 1200×632 is the closest 8-aligned pair to the standard OG image size (1200×630).
	private const DEFAULT_WIDTH  = 1200;
	private const DEFAULT_HEIGHT = 632;

	/** @var PRAutoBlogger_Image_Provider_Interface Image gen provider (FLUX.1 etc). */
	private PRAutoBlogger_Image_Provider_Interface $provider;

	/** @var PRAutoBlogger_Image_Prompt_Builder Builds scene + caption from article data. */
	private PRAutoBlogger_Image_Prompt_Builder $prompt_builder;

	/** @var PRAutoBlogger_Image_Media_Sideloader Downloads images into WP media library. */
	private PRAutoBlogger_Image_Media_Sideloader $sideloader;

	/** @var PRAutoBlogger_Cost_Tracker Logs image generation spend. */
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

		// Build the article-driven prompt (returns scene + caption).
		$article_prompt = $this->prompt_builder->build_article_prompt( $article_data );

		// Generate Image A (article-driven prompt).
		$image_a_result = $this->generate_image_a( $post_id, $article_prompt['prompt'] );
		if ( ! is_wp_error( $image_a_result ) && isset( $image_a_result['attachment_id'] ) ) {
			$result['image_a_id'] = $image_a_result['attachment_id'];

			// Set featured image immediately so it persists even if the
			// process times out before Image B finishes or before the
			// caller gets to handle the return value.
			set_post_thumbnail( $post_id, $image_a_result['attachment_id'] );

			// Store the caption as attachment meta for theme/display use.
			if ( '' !== $article_prompt['caption'] ) {
				update_post_meta( $image_a_result['attachment_id'], '_prautoblogger_image_caption', $article_prompt['caption'] );
				$this->prepend_caption_to_post( $post_id, $image_a_result['attachment_id'], $article_prompt['caption'] );
			}

			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Set featured image (attachment %d) for post %d', $image_a_result['attachment_id'], $post_id ),
				'image_pipeline'
			);
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
			$source_prompt  = $this->prompt_builder->build_source_prompt( $source_data );
			$image_b_result = $this->generate_image_b( $post_id, $source_prompt['prompt'] );
			if ( ! is_wp_error( $image_b_result ) && isset( $image_b_result['attachment_id'] ) ) {
				$result['image_b_id'] = $image_b_result['attachment_id'];

				// Store Image B reference immediately for the same
				// timeout-resilience reason as Image A above.
				update_post_meta( $post_id, '_prautoblogger_image_b_id', $image_b_result['attachment_id'] );

				// Store the caption as attachment meta.
				if ( '' !== $source_prompt['caption'] ) {
					update_post_meta( $image_b_result['attachment_id'], '_prautoblogger_image_caption', $source_prompt['caption'] );
				}

				PRAutoBlogger_Logger::instance()->info(
					sprintf( 'Stored Image B (attachment %d) for post %d', $image_b_result['attachment_id'], $post_id ),
					'image_pipeline'
				);
			} else {
				$result['errors'][] = is_wp_error( $image_b_result )
					? $image_b_result->get_error_message()
					: 'Image B generation produced no attachment ID.';
			}
			if ( ! is_wp_error( $image_b_result ) ) {
				$result['cost_usd'] += $image_b_result['cost_usd'] ?? 0.0;
			}
		} else {
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Image B skipped for post %d: no source data available.', $post_id ),
				'image_pipeline'
			);
		}

		return $result;
	}

	/** @see generate_single_image() — Image A wrapper. */
	private function generate_image_a( int $post_id, string $prompt ) {
		return $this->generate_single_image( $post_id, $prompt, 'image_a', 'Image A' );
	}

	/** @see generate_single_image() — Image B wrapper. */
	private function generate_image_b( int $post_id, string $prompt ) {
		return $this->generate_single_image( $post_id, $prompt, 'image_b', 'Image B' );
	}

	/**
	 * Generate a single image: budget check → provider call → sideload → log.
	 *
	 * Shared implementation for Image A and Image B to avoid duplication.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $prompt   Image generation prompt.
	 * @param string $slot     Cost-tracking slot ('image_a' or 'image_b').
	 * @param string $label    Human-readable label for log messages.
	 *
	 * @return array{attachment_id?: int, cost_usd: float}|\WP_Error
	 */
	private function generate_single_image( int $post_id, string $prompt, string $slot, string $label ) {
		try {
			$estimated_cost = $this->provider->estimate_cost( self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT );
			if ( $this->cost_tracker->would_exceed_budget( $estimated_cost ) ) {
				return new \WP_Error( 'budget_exceeded', 'Image generation would exceed monthly budget.' );
			}

			$image_data = $this->provider->generate_image( $prompt, self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT );

			$attachment_id = $this->sideloader->sideload_image(
				$image_data,
				$post_id,
				substr( $prompt, 0, 100 )
			);

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$this->cost_tracker->log_image_generation(
				$image_data['cost_usd'],
				$image_data['model'] ?? 'unknown',
				$post_id,
				$slot
			);

			PRAutoBlogger_Logger::instance()->info(
				sprintf( '%s generated for post %d (attachment %d, cost $%.4f)', $label, $post_id, $attachment_id, $image_data['cost_usd'] ),
				'image_pipeline'
			);

			return [
				'attachment_id' => $attachment_id,
				'cost_usd'      => $image_data['cost_usd'],
			];
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( '%s %s: %s', $label, get_class( $e ), $e->getMessage() ),
				'image_pipeline'
			);

			return new \WP_Error( 'image_generation_failed', $e->getMessage() );
		}
	}

	/**
	 * Prepend a comic caption as a styled figcaption block to post content.
	 *
	 * Inserts a <figure> block at the top of the post containing the featured
	 * image and a <figcaption> with the comic punchline. Uses inline styles
	 * for portability across themes.
	 *
	 * @param int    $post_id       Post ID.
	 * @param int    $attachment_id Featured image attachment ID.
	 * @param string $caption       Caption text (the comic punchline).
	 */
	private function prepend_caption_to_post( int $post_id, int $attachment_id, string $caption ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$img_url = wp_get_attachment_url( $attachment_id );
		$alt     = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		// Build a self-contained figure block with the comic image and caption.
		$figure = sprintf(
			'<figure class="prautoblogger-comic" style="text-align:center;margin:0 0 2em 0;">'
			. '<img src="%s" alt="%s" style="max-width:100%%;height:auto;border:2px solid #333;border-radius:4px;" />'
			. '<figcaption style="font-style:italic;color:#555;margin-top:0.5em;font-size:1.1em;">— "%s"</figcaption>'
			. '</figure>',
			esc_url( $img_url ),
			esc_attr( $alt ),
			esc_html( $caption )
		);

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => $figure . "\n\n" . $post->post_content,
		] );
	}
}
