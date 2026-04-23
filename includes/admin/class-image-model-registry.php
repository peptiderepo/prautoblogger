<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Static registry of available image generation models.
 *
 * What: Returns a hardcoded list of image models for the admin model picker.
 *       No API discovery — updated manually when providers add/remove models.
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
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_models(): array {
		return array(
			array(
				'id'             => 'runware:100@1',
				'name'           => 'FLUX.1 schnell (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.0006,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Default. Fast, very cheap, comic-friendly fidelity.', 'prautoblogger' ),
			),
			array(
				'id'             => 'runware:101@1',
				'name'           => 'FLUX.1 dev (Runware)',
				'provider'       => 'runware',
				'cost_per_image' => 0.02,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Higher fidelity FLUX. ~30x schnell cost.', 'prautoblogger' ),
			),
			array(
				'id'             => 'google/gemini-2.5-flash-image',
				'name'           => 'Gemini 2.5 Flash Image (Nano Banana)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.005,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Mid-tier quality, proven.', 'prautoblogger' ),
			),
			array(
				'id'             => 'google/gemini-3.1-flash-image-preview',
				'name'           => 'Gemini 3.1 Flash Image (Nano Banana 2)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.008,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Latest Google. Better quality.', 'prautoblogger' ),
			),
			array(
				'id'             => 'google/gemini-3-pro-image-preview',
				'name'           => 'Gemini 3 Pro Image (Nano Banana Pro)',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.03,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'Highest quality Google. Premium.', 'prautoblogger' ),
			),
			array(
				'id'             => 'openai/gpt-5-image-mini',
				'name'           => 'GPT-5 Image Mini',
				'provider'       => 'openrouter',
				'cost_per_image' => 0.02,
				'capabilities'   => array( 'image_generation' ),
				'description'    => __( 'OpenAI budget image model.', 'prautoblogger' ),
			),
			array(
				'id'             => 'openai/gpt-5-image',
				'name'           => 'GPT-5 Image',
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
