<?php
declare(strict_types=1);

/**
 * Single-shot retry of NSFW-blocked image slots with a sanitized prompt.
 *
 * When an image provider rejects a prompt via its content filter, its
 * batch result carries
 * `['error_type' => 'nsfw_blocked']`. This helper reruns just the blocked
 * slot once with the rule-based fallback prompt (article title + style
 * suffix, no LLM rewrite) and merges the new result back into the batch.
 *
 * Intentionally single-retry: if the fallback is also blocked, we log a
 * WARNING and leave the `['error' => ...]` entry in place so the pipeline
 * proceeds to publish without a featured image (current behaviour for any
 * unrecoverable image failure).
 *
 * Gated behind the `prautoblogger_image_nsfw_retry` setting (default on).
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline::generate_and_attach_images.
 * Dependencies: PRAutoBlogger_Image_Provider_Interface (retry HTTP call),
 *               PRAutoBlogger_Image_Prompt_Builder (fallback prompt),
 *               PRAutoBlogger_Logger (warning + info lines).
 *
 * @see core/class-image-pipeline.php            — Sole caller.
 * @see core/class-image-prompt-builder.php      — Supplies the fallback prompt.
 * @see providers/class-image-nsfw-blocked.php   — Typed exception raised by providers.
 */
class PRAutoBlogger_Image_NSFW_Retry {

	/**
	 * Option key for the admin toggle.
	 */
	private const SETTING_KEY = 'prautoblogger_image_nsfw_retry';

	/** @var PRAutoBlogger_Image_Provider_Interface */
	private PRAutoBlogger_Image_Provider_Interface $provider;

	/** @var PRAutoBlogger_Image_Prompt_Builder */
	private PRAutoBlogger_Image_Prompt_Builder $prompt_builder;

	/**
	 * @param PRAutoBlogger_Image_Provider_Interface $provider       Provider to retry against.
	 * @param PRAutoBlogger_Image_Prompt_Builder     $prompt_builder Fallback prompt source.
	 */
	public function __construct(
		PRAutoBlogger_Image_Provider_Interface $provider,
		PRAutoBlogger_Image_Prompt_Builder $prompt_builder
	) {
		$this->provider       = $provider;
		$this->prompt_builder = $prompt_builder;
	}

	/**
	 * Whether the admin setting currently allows NSFW retries.
	 *
	 * Defaults to on. Kept as a static so callers can short-circuit before
	 * constructing a retry object when nothing needs retrying anyway.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return '1' === (string) get_option( self::SETTING_KEY, '1' );
	}

	/**
	 * Replace every NSFW-blocked slot in $batch_results with a fresh result
	 * from a sanitized fallback prompt.
	 *
	 * Mutates $batch_results and $captions in place. Also updates the
	 * per-slot entry in $captions so the caller writes the fallback
	 * caption under the post if the retry succeeds.
	 *
	 * Side effects: up to one HTTP call per blocked slot; Logger lines
	 * for every retry attempt (info on success, warning on second block).
	 *
	 * @param int                        $post_id        Target post ID (for logs).
	 * @param array                      $batch_requests Original per-slot request map.
	 * @param array<string, array|null>  $batch_results  Provider result map (mutated).
	 * @param array<string, string>      $captions       Caption map (mutated).
	 * @param array<string, mixed>       $article_data   Article data (for title).
	 * @param array<string, mixed>|null  $source_data    Source data (for title on image_b).
	 * @return void
	 */
	public function retry_blocked_slots(
		int $post_id,
		array $batch_requests,
		array &$batch_results,
		array &$captions,
		array $article_data,
		?array $source_data
	): void {
		foreach ( $batch_results as $key => $entry ) {
			if ( ! is_array( $entry ) || 'nsfw_blocked' !== ( $entry['error_type'] ?? '' ) ) {
				continue;
			}

			$title = 'image_b' === $key
				? (string) ( $source_data['title'] ?? '' )
				: (string) ( $article_data['post_title'] ?? $article_data['suggested_title'] ?? '' );

			$fallback = $this->prompt_builder->build_fallback_prompt( $title );

			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'NSFW retry for post %d slot "%s" with sanitized fallback prompt.', $post_id, $key ),
				'image_pipeline'
			);

			$retry_request = [
				'prompt'  => $fallback['prompt'],
				'width'   => (int) ( $batch_requests[ $key ]['width']  ?? 0 ),
				'height'  => (int) ( $batch_requests[ $key ]['height'] ?? 0 ),
				'options' => $batch_requests[ $key ]['options'] ?? [],
			];

			$retry_results = $this->provider->generate_image_batch( [ $key => $retry_request ] );
			$retry_entry   = $retry_results[ $key ] ?? [ 'error' => 'Retry provider returned no result.' ];

			if ( isset( $retry_entry['error'] ) ) {
				PRAutoBlogger_Logger::instance()->warning(
					sprintf(
						'NSFW retry for post %d slot "%s" also failed: %s',
						$post_id,
						$key,
						(string) $retry_entry['error']
					),
					'image_pipeline'
				);
				// Leave the original ['error' => ...] entry so the pipeline
				// publishes without this image, matching current behaviour
				// for any unrecoverable image failure.
				continue;
			}

			$batch_results[ $key ] = $retry_entry;
			$captions[ $key ]      = $fallback['caption'];
		}
	}
}
