<?php
declare(strict_types=1);

/**
 * Pricing + model-id resolution helpers for the Cloudflare image provider.
 *
 * Kept separate from the provider so the provider stays under the 300-line
 * cap and so cost estimation can be unit-tested without spinning up HTTP
 * mocks. Mirrors the `class-open-router-pricing.php` split.
 *
 * Triggered by: PRAutoBlogger_Cloudflare_Image_Provider (both generate_image()
 *               and estimate_cost() delegate here).
 * Dependencies: None — pure calculation + option reads.
 *
 * @see class-cloudflare-image-provider.php — Caller.
 * @see class-open-router-pricing.php       — Sibling pattern for LLM pricing.
 */
class PRAutoBlogger_Cloudflare_Image_Pricing {

	/**
	 * Cloudflare Workers AI FLUX pricing (USD per megapixel), April 2026.
	 * Single source of truth — update here if Cloudflare repricies.
	 */
	private const COST_PER_MP_SCHNELL_USD = 0.0011;
	private const COST_MULTIPLIER_DEV     = 4.0;

	/**
	 * Short-alias → fully-qualified Workers AI model id.
	 *
	 * @var array<string, string>
	 */
	private const MODEL_ALIAS_MAP = [
		'flux-1-schnell' => '@cf/black-forest-labs/flux-1-schnell',
		'flux-1-dev'     => '@cf/black-forest-labs/flux-1-dev',
	];

	/**
	 * Normalize a caller's model hint to a fully-qualified Workers AI id.
	 *
	 * Accepts short aliases (`flux-1-schnell`), fully-qualified names
	 * (`@cf/black-forest-labs/...`), or empty. Empty input falls back to
	 * the site option, then to the hardcoded default.
	 *
	 * @param string $hint Caller-supplied model identifier (may be empty).
	 * @return string Fully-qualified Workers AI model id suitable for the URL path.
	 */
	public function resolve_model( string $hint ): string {
		$hint = trim( $hint );
		if ( '' === $hint ) {
			$hint = (string) get_option( 'prautoblogger_image_model', PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL );
		}
		if ( 0 === strpos( $hint, '@cf/' ) ) {
			return $hint;
		}
		return self::MODEL_ALIAS_MAP[ $hint ] ?? self::MODEL_ALIAS_MAP['flux-1-schnell'];
	}

	/**
	 * Estimate USD cost for a single image at the given dimensions.
	 *
	 * FLUX is priced per megapixel of output. Schnell is the baseline;
	 * [dev] is a ~4x multiplier. We clamp the MP floor at 0.01 so a
	 * tiny thumbnail doesn't round to zero and bypass the budget check.
	 *
	 * @param int    $width  Width in pixels.
	 * @param int    $height Height in pixels.
	 * @param string $model  Fully-qualified Workers AI model id (call resolve_model first).
	 * @return float USD cost rounded to 6 decimals.
	 */
	public function estimate_cost( int $width, int $height, string $model ): float {
		$mp   = max( 0.01, ( $width * $height ) / 1_000_000 );
		$rate = self::COST_PER_MP_SCHNELL_USD;
		if ( false !== strpos( $model, 'flux-1-dev' ) ) {
			$rate *= self::COST_MULTIPLIER_DEV;
		}
		return round( $mp * $rate, 6 );
	}
}
