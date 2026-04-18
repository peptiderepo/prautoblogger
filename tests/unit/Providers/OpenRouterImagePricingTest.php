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

	/** Default model constant is FLUX.2 Pro. */
	public function test_default_model_constant(): void {
		$this->assertSame(
			'black-forest-labs/flux-2-pro',
			\PRAutoBlogger_OpenRouter_Image_Pricing::DEFAULT_MODEL
		);
	}

	/** Empty hint resolves to default model. */
	public function test_resolve_model_empty_hint_uses_default(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$model   = $pricing->resolve_model( '' );
		$this->assertSame( 'black-forest-labs/flux-2-pro', $model );
	}

	/** Valid hint resolves to itself. */
	public function test_resolve_model_valid_hint(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$model   = $pricing->resolve_model( 'bytedance/seedream-3.0' );
		$this->assertSame( 'bytedance/seedream-3.0', $model );
	}

	/** Unknown model falls back to default. */
	public function test_resolve_model_unknown_falls_back_to_default(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$model   = $pricing->resolve_model( 'nonexistent/model-999' );
		$this->assertSame( 'black-forest-labs/flux-2-pro', $model );
	}

	/** FLUX.2 Pro costs $0.03/image. */
	public function test_estimate_cost_flux2_pro(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$cost    = $pricing->estimate_cost( 1200, 632, 'black-forest-labs/flux-2-pro' );
		$this->assertEqualsWithDelta( 0.03, $cost, 0.000001 );
	}

	/** Seedream costs $0.003/image. */
	public function test_estimate_cost_seedream(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$cost    = $pricing->estimate_cost( 1200, 632, 'bytedance/seedream-3.0' );
		$this->assertEqualsWithDelta( 0.003, $cost, 0.000001 );
	}

	/** Unknown model in estimate_cost falls back to default pricing. */
	public function test_estimate_cost_unknown_model(): void {
		$pricing = new \PRAutoBlogger_OpenRouter_Image_Pricing();
		$cost    = $pricing->estimate_cost( 1200, 632, 'unknown/model' );
		$this->assertEqualsWithDelta( 0.03, $cost, 0.000001 );
	}

	/** get_model_costs returns a non-empty map. */
	public function test_get_model_costs(): void {
		$costs = \PRAutoBlogger_OpenRouter_Image_Pricing::get_model_costs();
		$this->assertIsArray( $costs );
		$this->assertArrayHasKey( 'black-forest-labs/flux-2-pro', $costs );
		$this->assertGreaterThan( 0, count( $costs ) );
	}
}
