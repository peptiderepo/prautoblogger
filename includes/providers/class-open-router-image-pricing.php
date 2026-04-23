<?php
declare(strict_types=1);

/**
 * Pricing + model-id resolution helpers for the OpenRouter image provider.
 *
 * Mirrors the Runware pricing helper pattern. Each model's cost per image
 * is a flat rate sourced from OpenRouter's pricing page (April 2026).
 *
 * Triggered by: PRAutoBlogger_OpenRouter_Image_Provider (both generate_image()
 *               and estimate_cost() delegate here).
 * Dependencies: None — pure calculation + option reads.
 *
 * @see class-openrouter-image-provider.php — Caller.
 * @see class-runware-image-pricing.php     — Sibling pattern for Runware.
 */
class PRAutoBlogger_OpenRouter_Image_Pricing {

	/**
	 * Flat per-image cost (USD) for each supported model, April 2026.
	 * Update here when OpenRouter reprices or new models launch.
	 *
	 * @var array<string, float>
	 */
	private const COST_PER_IMAGE = array(
		'google/gemini-2.5-flash-image'         => 0.005,
		'google/gemini-3.1-flash-image-preview' => 0.008,
		'google/gemini-3-pro-image-preview'     => 0.03,
		'openai/gpt-5-image-mini'               => 0.02,
		'openai/gpt-5-image'                    => 0.08,
	);

	/**
	 * Resolve the model identifier from a hint, falling back to site option,
	 * then to the PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL constant.
	 *
	 * Never silently swaps to a different model — if the user picked something,
	 * we send it to OpenRouter and let the API error if it's invalid. That way
	 * the user sees a clear log message instead of getting billed for a model
	 * they didn't choose.
	 *
	 * @param string $hint Caller-supplied model identifier (may be empty).
	 * @return string OpenRouter model id.
	 */
	public function resolve_model( string $hint ): string {
		$hint = trim( $hint );
		if ( '' !== $hint ) {
			return $hint;
		}

		$saved = trim( (string) get_option( 'prautoblogger_image_model', '' ) );
		if ( '' !== $saved ) {
			return $saved;
		}

		// Last resort: use the constant from prautoblogger.php (admin can change it).
		return defined( 'PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL' )
			? PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL
			: 'google/gemini-2.5-flash-image';
	}

	/**
	 * Estimate USD cost for a single image at the given model.
	 *
	 * OpenRouter image models use flat per-image pricing, not per-megapixel.
	 * Width/height are accepted for interface compatibility but don't affect cost.
	 *
	 * @param int    $width  Width in pixels (unused for flat pricing).
	 * @param int    $height Height in pixels (unused for flat pricing).
	 * @param string $model  Resolved OpenRouter model id.
	 * @return float USD cost rounded to 6 decimals.
	 */
	public function estimate_cost( int $width, int $height, string $model ): float {
		if ( isset( self::COST_PER_IMAGE[ $model ] ) ) {
			return round( self::COST_PER_IMAGE[ $model ], 6 );
		}

		// Unknown model — log it and use a conservative $0.05 estimate.
		PRAutoBlogger_Logger::instance()->warning(
			sprintf( 'No pricing data for image model "%s". Using $0.05 estimate.', $model ),
			'openrouter-image'
		);
		return 0.05;
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
