<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Pricing + model-id resolution helpers for the Runware image provider.
 *
 * Each Runware FLUX model has a flat per-image cost sourced from
 * runware.ai/pricing (April 2026). Unlike OpenRouter models, Runware
 * prices scale slightly with steps — the quoted rate here assumes the
 * provider's default step count (4 for schnell, 28 for dev).
 *
 * Triggered by: PRAutoBlogger_Runware_Image_Provider, PRAutoBlogger_Runware_Image_Batch.
 * Dependencies: None — pure calculation + option reads.
 *
 * @see class-runware-image-provider.php — Primary caller.
 * @see class-open-router-image-pricing.php — Sibling pattern for OpenRouter.
 */
class PRAutoBlogger_Runware_Image_Pricing {

	/**
	 * Flat per-image cost (USD) for each supported Runware model, April 2026.
	 * Update here when Runware reprices or new models launch.
	 *
	 * Runware model IDs use `family:variant@version` form. The two FLUX
	 * variants we support are the CEO-sanctioned defaults.
	 *
	 * @var array<string, float>
	 */
	private const COST_PER_IMAGE = array(
		// FLUX.1 schnell — 4-step distilled. Our new default (2026-04-21).
		'runware:100@1' => 0.0006,
		// FLUX.1 dev — 28-step full model. Higher fidelity, ~30x cost.
		'runware:101@1' => 0.02,
	);

	/**
	 * Default number of inference steps per model. Runware bills per image
	 * at the model's standard step count; custom step counts may alter price.
	 *
	 * @var array<string, int>
	 */
	private const DEFAULT_STEPS = array(
		'runware:100@1' => 4,
		'runware:101@1' => 28,
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
	 * Runware FLUX pricing is flat per image at the model's standard step
	 * count. Width/height are accepted for interface compatibility but do
	 * not affect cost for the variants we support.
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
