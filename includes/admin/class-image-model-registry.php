<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Static registry of available image generation models.
 *
 * What: Returns a hardcoded list of image models for the admin model picker.
 *       No API discovery — updated manually when providers add/remove models.
 *       As of 2026-04-26, registry includes 21 models across Runware (15) and
 *       OpenRouter (6), ordered cheapest-to-most-expensive within each provider.
 * Who calls it: PRAutoBlogger_Admin_Page (model picker UI + save-time provider
 *               derivation) and PRAutoBlogger_Image_Pipeline (provider lookup).
 * Dependencies: None.
 *
 * @see admin/class-settings-fields-extended.php — Image settings reference this registry.
 * @see core/class-image-pipeline.php            — Derives provider from picked model.
 * @see providers/class-runware-image-provider.php — Runware FLUX backend.
 */
class PRAutoBlogger_Image_Model_Registry {

	/**
	 * Get all available image generation models.
	 *
	 * Each entry contains:
	 * - id:             Model identifier used in API calls.
	 * - name:           Human-readable display name.
	 * - provider:       'runware' | 'openrouter'.
	 * - cost_per_image: Estimated USD cost per generation.
	 * - capabilities:   Array of capability tags.
	 * - description:    Short description for the admin UI.
	 *
	 * Ordered cheapest-to-most-expensive within each provider, with the
	 * recommended default (Runware schnell) at the top.
	 *
	 * Last verified: 2026-04-26. To update: check https://runware.ai/models (text-to-image section)
	 * and https://openrouter.ai/api/v1/models filtering output_modalities containing 'image'.
	 * Exclude: inpainting (needs mask), img2img (needs seedImage), video, upscalers, background removal.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_models(): array {
		return array(
			// RUNWARE MODELS
			array(
				'id'             => 'runware:100@1',
				'name'           => 'FLUX.1 schnell (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0006,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Default. Fast, very cheap, comic-friendly fidelity.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:twinflow-z-image-turbo@0',
				'name'           => 'TwinFlow Z-Image-Turbo (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0006,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Fast text-to-image, same cost as schnell.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:400@2',
				'name'           => 'FLUX.2 klein 9B (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.00078,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'FLUX.2 compact 9B. Stronger than schnell at similar cost.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:400@4',
				'name'           => 'FLUX.2 klein 4B (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0006,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'FLUX.2 compact 4B. Newer architecture at schnell price.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:5@1',
				'name'           => 'Stable Diffusion 3 (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0013,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Stable Diffusion 3. Sharp text, complex scenes.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:z-image@turbo',
				'name'           => 'Z-Image Turbo (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0013,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Fast photorealistic generation.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:101@1',
				'name'           => 'FLUX.1 dev (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.02,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Higher fidelity FLUX.1. ~30x schnell cost.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:97@3',
				'name'           => 'HiDream-I1 Fast (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0038,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'HiDream-I1 Fast. Low latency, 17B quality.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:97@2',
				'name'           => 'HiDream-I1 Dev (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0045,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'HiDream-I1 Dev. Strong prompt alignment.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:z-image@0',
				'name'           => 'Z-Image (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0045,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'High-quality Z-Image foundation model.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:400@1',
				'name'           => 'FLUX.2 dev (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0051,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'FLUX.2 dev. Controllable open text-to-image. From $0.0051.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:108@1',
				'name'           => 'Qwen-Image (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0058,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Alibaba Qwen-Image. Strong text rendering in generated images.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:97@1',
				'name'           => 'HiDream-I1 Full (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.009,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'HiDream-I1 Full. 17B high-fidelity, LoRA support.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:107@1',
				'name'           => 'FLUX.1 Krea dev (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0098,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'FLUX.1 Krea Dev. Photorealistic open-weight generation.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:glm-image@0',
				'name'           => 'GLM-Image (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0225,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'GLM-Image. Hybrid autoregressive+diffusion, excellent text rendering.', 'prautoblogger' ),
			),
			// OPENROUTER MODELS
			array(
				'id'             => 'google/gemini-2.5-flash-image',
				'name'           => 'Gemini 2.5 Flash Image (OpenRouter)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.005,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Mid-tier quality, proven.', 'prautoblogger' ),
			),
			array(
				'id'             => 'google/gemini-3.1-flash-image-preview',
				'name'           => 'Gemini 3.1 Flash Image (OpenRouter)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.008,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Latest Google Flash image. Better quality.', 'prautoblogger' ),
			),
			array(
				'id'             => 'openai/gpt-5-image-mini',
				'name'           => 'GPT-5 Image Mini (OpenRouter)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.02,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'OpenAI budget image model.', 'prautoblogger' ),
			),
			array(
				'id'             => 'openai/gpt-5.4-image-2',
				'name'           => 'GPT-5.4 Image 2 (OpenRouter)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.02,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'OpenAI GPT-5.4 image generation.', 'prautoblogger' ),
			),
			array(
				'id'             => 'google/gemini-3-pro-image-preview',
				'name'           => 'Gemini 3 Pro Image (OpenRouter)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.03,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Highest quality Google image. Premium.', 'prautoblogger' ),
			),
			array(
				'id'             => 'openai/gpt-5-image',
				'name'           => 'GPT-5 Image (OpenRouter)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.08,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'OpenAI premium. Photorealistic.', 'prautoblogger' ),
			),
		);
	}

	/**
	 * Return the provider id for a known model, or empty string if the
	 * model id is not in the registry.
	 *
	 * @param string $model_id Model slug from the admin UI.
	 * @return string Provider id ('runware' | 'openrouter' | '').
	 */
	public static function provider_for( string $model_id ): string {
		foreach ( self::get_models() as $model ) {
			if ( ( $model['id'] ?? '' ) === $model_id ) {
				return (string) ( $model['provider'] ?? '' );
			}
		}
		return '';
	}
}
