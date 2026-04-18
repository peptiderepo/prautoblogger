<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Image_Pricing.
 *
 * Validates model resolution fallback logic and per-image cost estimation.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterImagePricingTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			static $options = [];
			return $options[ $key ] ?? $default;
		} );
	}

	/** Empty hint resolves to the PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL constant. */
	public function test_resolve_model_empty_hint_uses_default(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$model   = $pricing->resolve_model( '' );
		$this->assertSame( PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL, $model );
	}

	/** Explicit hint resolves to itself, regardless of what default is set. */
	public function test_resolve_model_valid_hint(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$model   = $pricing->resolve_model( 'openai/gpt-5-image' );
		$this->assertSame( 'openai/gpt-5-image', $model );
	}

	/** Gemini Flash Image costs $0.005/image. */
	public function test_estimate_cost_gemini_flash(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$cost    = $pricing->estimate_cost( 1200, 632, 'google/gemini-2.5-flash-image' );
		$this->assertEqualsWithDelta( 0.005, $cost, 0.000001 );
	}

	/** GPT-5 Image costs $0.08/image. */
	public function test_estimate_cost_gpt5_image(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$cost    = $pricing->estimate_cost( 1200, 632, 'openai/gpt-5-image' );
		$this->assertEqualsWithDelta( 0.08, $cost, 0.000001 );
	}

	/** Unknown model falls back to conservative $0.05 estimate. */
	public function test_estimate_cost_unknown_model(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$cost    = $pricing->estimate_cost( 1200, 632, 'unknown/model' );
		$this->assertEqualsWithDelta( 0.05, $cost, 0.000001 );
	}

	/** get_model_costs returns a non-empty map with known models. */
	public function test_get_model_costs(): void {
		$costs = \PRAutoBlogger_OpenRouter_Image_Pricing::get_model_costs();
		$this->assertIsArray( $costs );
		$this->assertArrayHasKey( 'google/gemini-2.5-flash-image', $costs );
		$this->assertArrayHasKey( 'openai/gpt-5-image', $costs );
		$this->assertGreaterThan( 0, count( $costs ) );
	}
}
