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
	// Dimensions must be divisible by 8 (required by some providers).
	// 1200×632 is the closest 8-aligned pair to the standard OG image size (1200×630).
	private const DEFAULT_WIDTH  = 1200;
	private const DEFAULT_HEIGHT = 632;

	/** @var PRAutoBlogger_Image_Provider_Interface Image gen provider. */
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
	 * Builds all prompts upfront, fires a single batch call (parallel via
	 * curl_multi on OpenRouter), then processes results. Wall-clock time
	 * equals the slowest image, not the sum — solving the Image B timeout.
	 *
	 * @param int        $post_id      Post ID to attach images to.
	 * @param array      $article_data Article title + content.
	 * @param array|null $source_data  Optional source data for Image B.
	 *
	 * @return array{image_a_id?: int, image_b_id?: int, cost_usd: float, errors: string[]}
	 */
	public function generate_and_attach_images(
		int $post_id,
		array $article_data,
		?array $source_data = null
	): array {
		$result = [ 'cost_usd' => 0.0, 'errors' => [] ];

		if ( ! get_option( 'prautoblogger_image_enabled' ) ) {
			PRAutoBlogger_Logger::instance()->info( 'Image generation disabled in settings.', 'image_pipeline' );
			return $result;
		}

		// Check whether Image B is enabled in settings.
		$image_b_enabled = get_option( 'prautoblogger_image_b_enabled', '1' );
		$has_source_data = null !== $source_data && ! empty( $source_data );
		$generate_b      = $has_source_data && '1' === $image_b_enabled;

		// Budget pre-check: estimate cost for all images before any HTTP calls.
		$image_count    = $generate_b ? 2 : 1;
		$estimated_cost = $this->provider->estimate_cost( self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT ) * $image_count;
		if ( $this->cost_tracker->would_exceed_budget( $estimated_cost ) ) {
			$result['errors'][] = 'Image generation would exceed monthly budget.';
			return $result;
		}

		// Build all prompts upfront (LLM calls happen here, sequentially).
		$article_prompt = $this->prompt_builder->build_article_prompt( $article_data );
		$batch_requests = [
			'image_a' => [
				'prompt' => $article_prompt['prompt'],
				'width'  => self::DEFAULT_WIDTH,
				'height' => self::DEFAULT_HEIGHT,
			],
		];
		$captions = [ 'image_a' => $article_prompt['caption'] ];

		$source_prompt = null;
		if ( $generate_b ) {
			$source_prompt = $this->prompt_builder->build_source_prompt( $source_data );
			$batch_requests['image_b'] = [
				'prompt' => $source_prompt['prompt'],
				'width'  => self::DEFAULT_WIDTH,
				'height' => self::DEFAULT_HEIGHT,
			];
			$captions['image_b'] = $source_prompt['caption'];
		}

		// Fire all image generation requests in parallel.
		try {
			$batch_results = $this->provider->generate_image_batch( $batch_requests );
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error( 'Batch generation failed: ' . $e->getMessage(), 'image_pipeline' );
			$result['errors'][] = $e->getMessage();
			return $result;
		}

		// Process Image A result.
		$this->process_image_a( $post_id, $batch_results, $captions, $result );

		// Process Image B result (if requested).
		if ( isset( $batch_results['image_b'] ) ) {
			$this->process_image_b( $post_id, $batch_results, $captions, $result );
		} elseif ( null === $source_data || empty( $source_data ) ) {
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Image B skipped for post %d: no source data.', $post_id ),
				'image_pipeline'
			);
		}

		return $result;
	}

	/**
	 * Process Image A batch result: sideload, set featured image, insert caption.
	 *
	 * @param int   $post_id       Post ID.
	 * @param array $batch_results Keyed results from generate_image_batch().
	 * @param array $captions      Keyed captions from prompt builder.
	 * @param array $result        Pipeline result array (modified by reference).
	 */
	private function process_image_a( int $post_id, array $batch_results, array $captions, array &$result ): void {
		$image_data = $batch_results['image_a'] ?? null;
		if ( ! $image_data || isset( $image_data['error'] ) ) {
			$result['errors'][] = $image_data['error'] ?? 'Image A missing from batch results.';
			return;
		}

		$attachment_id = $this->sideload_and_log( $image_data, $post_id, 'image_a', 'Image A' );
		if ( is_wp_error( $attachment_id ) ) {
			$result['errors'][] = $attachment_id->get_error_message();
			return;
		}

		$result['image_a_id'] = $attachment_id;
		$result['cost_usd']  += $image_data['cost_usd'];
		set_post_thumbnail( $post_id, $attachment_id );

		if ( '' !== ( $captions['image_a'] ?? '' ) ) {
			update_post_meta( $attachment_id, '_prautoblogger_image_caption', $captions['image_a'] );
			$this->prepend_caption_to_post( $post_id, $attachment_id, $captions['image_a'] );
		}
	}

	/**
	 * Process Image B batch result: sideload, store meta.
	 *
	 * @param int   $post_id       Post ID.
	 * @param array $batch_results Keyed results from generate_image_batch().
	 * @param array $captions      Keyed captions from prompt builder.
	 * @param array $result        Pipeline result array (modified by reference).
	 */
	private function process_image_b( int $post_id, array $batch_results, array $captions, array &$result ): void {
		$image_data = $batch_results['image_b'] ?? null;
		if ( ! $image_data || isset( $image_data['error'] ) ) {
			$result['errors'][] = $image_data['error'] ?? 'Image B missing from batch results.';
			return;
		}

		$attachment_id = $this->sideload_and_log( $image_data, $post_id, 'image_b', 'Image B' );
		if ( is_wp_error( $attachment_id ) ) {
			$result['errors'][] = $attachment_id->get_error_message();
			return;
		}

		$result['image_b_id'] = $attachment_id;
		$result['cost_usd']  += $image_data['cost_usd'];
		update_post_meta( $post_id, '_prautoblogger_image_b_id', $attachment_id );

		if ( '' !== ( $captions['image_b'] ?? '' ) ) {
			update_post_meta( $attachment_id, '_prautoblogger_image_caption', $captions['image_b'] );
		}
	}

	/**
	 * Sideload image bytes into the media library and log the cost.
	 *
	 * @param array  $image_data Provider result with bytes, model, cost_usd.
	 * @param int    $post_id    Post ID.
	 * @param string $slot       Cost-tracking slot ('image_a' or 'image_b').
	 * @param string $label      Human-readable label for logs.
	 * @return int|\WP_Error Attachment ID on success.
	 */
	private function sideload_and_log( array $image_data, int $post_id, string $slot, string $label ) {
		$attachment_id = $this->sideloader->sideload_image(
			$image_data,
			$post_id,
			substr( $image_data['model'] ?? 'image', 0, 100 )
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
			sprintf( '%s for post %d (att %d, $%.4f)', $label, $post_id, $attachment_id, $image_data['cost_usd'] ),
			'image_pipeline'
		);

		return $attachment_id;
	}

	/**
	 * Prepend the comic caption as styled text at the top of the post content.
	 *
	 * Only inserts the caption — NOT the image. The theme already displays the
	 * featured image, so embedding it again would cause a duplicate.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $attachment_id Featured image attachment ID (for meta storage only).
	 * @param string $caption Caption text (the comic punchline).
	 */
	private function prepend_caption_to_post( int $post_id, int $attachment_id, string $caption ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Caption-only block — the theme handles the featured image display.
		$caption_html = sprintf(
			'<p class="prautoblogger-comic-caption" style="text-align:center;font-style:italic;color:#555;font-size:1.1em;margin:0 0 2em 0;">— "%s"</p>',
			esc_html( $caption )
		);

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => $caption_html . "\n\n" . $post->post_content,
		] );
	}
}
