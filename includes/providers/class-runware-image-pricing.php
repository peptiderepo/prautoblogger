<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Pricing + model-id resolution helpers for the Runware image provider.
 *
 * Each Runware model has a flat per-image cost sourced from
 * runware.ai/pricing (April 2026). Unlike OpenRouter models, Runware
 * prices scale slightly with steps — the quoted rate here assumes the
 * provider's default step count for each model.
 *
 * Triggered by: PRAutoBlogger_Runware_Image_Provider, PRAutoBlogger_Runware_Image_Batch.
 * Dependencies: None — pure calculation + option reads.
 *
 * @see class-runware-image-provider.php — Primary caller.
 * @see class-runware-model-catalog.php — Live model sync; authoritative pricing source.
 * @see class-open-router-image-pricing.php — Sibling pattern for OpenRouter.
 * @see admin/class-image-model-registry.php — Source of truth for model list; keep in sync.
 */
class PRAutoBlogger_Runware_Image_Pricing {

	/**
	 * Flat per-image cost (USD) for each supported Runware model, April 2026.
	 * Update here when Runware reprices or new models launch. Must stay in
	 * sync with the model list in PRAutoBlogger_Image_Model_Registry — any
	 * model listed there that is absent here will silently fall back to schnell.
	 *
	 * Runware model IDs use `family:variant@version` form.
	 *
	 * @var array<string, float>
	 */
	private const COST_PER_IMAGE = array(
		// FLUX.1 schnell — 4-step distilled. Default (2026-04-21).
		'runware:100@1'                => 0.0006,
		// TwinFlow Z-Image-Turbo — fast text-to-image, same cost as schnell.
		'runware:twinflow-z-image-turbo@0' => 0.0006,
		// FLUX.2 klein 4B — newer architecture at schnell price.
		'runware:400@4'                => 0.0006,
		// FLUX.2 klein 9B — stronger than schnell, similar cost.
		'runware:400@2'                => 0.00078,
		// Stable Diffusion 3 — sharp text, complex scenes.
		'runware:5@1'                  => 0.0013,
		// Z-Image Turbo — fast photorealistic generation.
		'runware:z-image@turbo'        => 0.0013,
		// HiDream-I1 Fast — low latency, 17B quality.
		'runware:97@3'                 => 0.0038,
		// HiDream-I1 Dev — strong prompt alignment.
		'runware:97@2'                 => 0.0045,
		// Z-Image — high-quality foundation model.
		'runware:z-image@0'            => 0.0045,
		// FLUX.2 dev — controllable open text-to-image.
		'runware:400@1'                => 0.0051,
		// Qwen-Image — strong text rendering in generated images.
		'runware:108@1'                => 0.0058,
		// HiDream-I1 Full — 17B high-fidelity, LoRA support.
		'runware:97@1'                 => 0.009,
		// FLUX.1 Krea dev — photorealistic open-weight generation.
		'runware:107@1'                => 0.0098,
		// FLUX.1 dev — 28-step full model. Higher fidelity, ~30x schnell cost.
		'runware:101@1'                => 0.02,
		// GLM-Image — hybrid autoregressive+diffusion, excellent text rendering.
		'runware:glm-image@0'          => 0.0225,
	);

	/**
	 * Default number of inference steps per model. Runware bills per image
	 * at the model's standard step count; custom step counts may alter price.
	 * Sources: runware.ai/models (April 2026).
	 *
	 * @var array<string, int>
	 */
	private const DEFAULT_STEPS = array(
		'runware:100@1'                    => 4,
		'runware:twinflow-z-image-turbo@0' => 4,
		'runware:400@4'                    => 20,
		'runware:400@2'                    => 20,
		'runware:5@1'                      => 28,
		'runware:z-image@turbo'            => 4,
		'runware:97@3'                     => 16,
		'runware:97@2'                     => 28,
		'runware:z-image@0'                => 28,
		'runware:400@1'                    => 28,
		'runware:108@1'                    => 20,
		'runware:97@1'                     => 50,
		'runware:107@1'                    => 28,
		'runware:101@1'                    => 28,
		'runware:glm-image@0'              => 30,
	);

	/**
	 * Resolve the model identifier from a hint, falling back to site option,
	 * then to the PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL constant.
	 *
	 * If the saved/default model is not a Runware id (e.g. still points at
	 * a Gemini slug), fall back to schnell — the provider was selected
	 * explicitly, so the user wants a Runware model.
	 *
	 * @param string $hint Caller-supplied model identifier (may be empty).
	 * @return string Runware model id.
	 */
	public function resolve_model( string $hint ): string {
		$candidate = trim( $hint );
		if ( '' === $candidate ) {
			$candidate = trim( (string) get_option( 'prautoblogger_image_model', '' ) );
		}
		if ( '' === $candidate && defined( 'PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL' ) ) {
			$candidate = (string) PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL;
		}

		// If the candidate isn't a known Runware id, fall back to schnell.
		if ( '' === $candidate || ! isset( self::COST_PER_IMAGE[ $candidate ] ) ) {
			return 'runware:100@1';
		}

		return $candidate;
	}

	/**
	 * Estimate USD cost for a single image at the given model.
	 *
	 * Runware pricing is flat per image at the model's standard step count.
	 * Width/height are accepted for interface compatibility but do not affect
	 * cost for the variants we support.
	 *
	 * @param int    $width  Width in pixels (unused for flat pricing).
	 * @param int    $height Height in pixels (unused for flat pricing).
	 * @param string $model  Resolved Runware model id.
	 * @return float USD cost rounded to 6 decimals.
	 */
	public function estimate_cost( int $width, int $height, string $model ): float {
		if ( isset( self::COST_PER_IMAGE[ $model ] ) ) {
			return round( self::COST_PER_IMAGE[ $model ], 6 );
		}

		// Unknown model — log it and use a conservative $0.01 estimate.
		PRAutoBlogger_Logger::instance()->warning(
			sprintf( 'No Runware pricing data for model "%s". Using $0.01 estimate.', $model ),
			'runware-image'
		);
		return 0.01;
	}

	/**
	 * Get the default step count for a Runware model.
	 *
	 * @param string $model Resolved Runware model id.
	 * @return int Default steps.
	 */
	public function default_steps( string $model ): int {
		return self::DEFAULT_STEPS[ $model ] ?? 4;
	}

	/**
	 * Get the full model cost table for admin display.
	 *
	 * @return array<string, float> Model id => cost per image in USD.
	 */
	public static function get_model_costs(): array {
		return self::COST_PER_IMAGE;
	}
}
