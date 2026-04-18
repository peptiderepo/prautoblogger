<?php
declare(strict_types=1);

/**
 * Pricing + model-id resolution helpers for the OpenRouter image provider.
 *
 * Mirrors the Cloudflare pricing helper pattern. Each model's cost per image
 * is a flat rate sourced from OpenRouter's pricing page (April 2026).
 *
 * Triggered by: PRAutoBlogger_OpenRouter_Image_Provider (both generate_image()
 *               and estimate_cost() delegate here).
 * Dependencies: None — pure calculation + option reads.
 *
 * @see class-openrouter-image-provider.php — Caller.
 * @see class-cloudflare-image-pricing.php  — Sibling pattern for Cloudflare.
 */
class PRAutoBlogger_OpenRouter_Image_Pricing {

	/**
	 * Flat per-image cost (USD) for each supported model, April 2026.
	 * Update here when OpenRouter reprices or new models launch.
	 *
	 * @var array<string, float>
	 */
	private const COST_PER_IMAGE = [
		'black-forest-labs/flux-2-pro'          => 0.03,
		'black-forest-labs/flux-1.1-pro'        => 0.04,
		'black-forest-labs/flux-1.1-pro-ultra'  => 0.06,
		'bytedance/seedream-3.0'                => 0.003,
		'google/gemini-2.5-flash-preview:image' => 0.01,
		'openai/gpt-image-1'                    => 0.04,
	];

	/** @var string Default model when none is configured. */
	public const DEFAULT_MODEL = 'black-forest-labs/flux-2-pro';

	/**
	 * Resolve the model identifier from a hint, falling back to site option,
	 * then to the hardcoded default.
	 *
	 * @param string $hint Caller-supplied model identifier (may be empty).
	 * @return string OpenRouter model id.
	 */
	public function resolve_model( string $hint ): string {
		$hint = trim( $hint );
		if ( '' === $hint ) {
			$hint = (string) get_option( 'prautoblogger_image_model', self::DEFAULT_MODEL );
		}
		// Validate the model exists in our cost table; fall back to default.
		if ( ! isset( self::COST_PER_IMAGE[ $hint ] ) ) {
			return self::DEFAULT_MODEL;
		}
		return $hint;
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
		return round( self::COST_PER_IMAGE[ $model ] ?? self::COST_PER_IMAGE[ self::DEFAULT_MODEL ], 6 );
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
