<?php
declare(strict_types=1);

/**
 * Static registry of available image generation models.
 *
 * What: Returns a hardcoded list of image models for the admin model picker.
 *       No API discovery — updated manually when providers add/remove models.
 * Who calls it: PRAutoBlogger_Admin_Page (model picker UI) and
 *               PRAutoBlogger_Image_Pipeline (model validation).
 * Dependencies: None.
 *
 * @see admin/class-settings-fields-extended.php — Image settings reference this registry.
 * @see core/class-image-pipeline.php            — Validates selected model against this list.
 */
class PRAutoBlogger_Image_Model_Registry {

	/**
	 * Get all available image generation models.
	 *
	 * Each entry contains:
	 * - id:             Model identifier used in API calls.
	 * - name:           Human-readable display name.
	 * - provider:       'openrouter' or 'cloudflare'.
	 * - cost_per_image: Estimated USD cost per generation.
	 * - capabilities:   Array of capability tags.
	 * - description:    Short description for the admin UI.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_models(): array {
		return [
			[
				'id'             => 'google/gemini-2.5-flash-image',
				'name'           => 'Gemini 2.5 Flash Image (Nano Banana)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.005,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'Good quality, low cost. Recommended.', 'prautoblogger' ),
			],
			[
				'id'             => 'google/gemini-3.1-flash-image-preview',
				'name'           => 'Gemini 3.1 Flash Image (Nano Banana 2)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.008,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'Latest Google. Better quality.', 'prautoblogger' ),
			],
			[
				'id'             => 'google/gemini-3-pro-image-preview',
				'name'           => 'Gemini 3 Pro Image (Nano Banana Pro)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.03,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'Highest quality Google. Premium.', 'prautoblogger' ),
			],
			[
				'id'             => 'openai/gpt-5-image-mini',
				'name'           => 'GPT-5 Image Mini',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.02,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'OpenAI budget image model.', 'prautoblogger' ),
			],
			[
				'id'             => 'openai/gpt-5-image',
				'name'           => 'GPT-5 Image',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.08,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'OpenAI premium. Photorealistic.', 'prautoblogger' ),
			],
			[
				'id'             => 'flux-1-schnell',
				'name'           => 'FLUX.1 schnell (Cloudflare)',
				'provider'       => 'cloudflare',
				'cost_per_image' => 0.0007,
				'capabilities'   => [ 'image_generation' ],
				'description'    => __( 'Cheapest. Low quality, 4-step.', 'prautoblogger' ),
			],
		];
	}
}
