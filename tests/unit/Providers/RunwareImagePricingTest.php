<?php
/**
 * Tests for PRAutoBlogger_Runware_Image_Pricing.
 *
 * Validates model resolution fallback logic and per-image cost estimation
 * for the Runware FLUX models.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class RunwareImagePricingTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			static $options = [];
			return $options[ $key ] ?? $default;
		} );
	}

	/** Empty hint resolves to the v0.9.0 default (runware schnell). */
	public function test_resolve_model_empty_hint_uses_schnell_default(): void {
		$pricing = new \PRAutoBlogger_Runware_Image_Pricing();
		$model   = $pricing->resolve_model( '' );
		$this->assertSame( 'runware:100@1', $model );
	}

	/** Explicit schnell hint resolves to itself. */
	public function test_resolve_model_schnell_hint(): void {
		$pricing = new \PRAutoBlogger_Runware_Image_Pricing();
		$this->assertSame( 'runware:100@1', $pricing->resolve_model( 'runware:100@1' ) );
	}

	/** Explicit dev hint resolves to itself. */
	public function test_resolve_model_dev_hint(): void {
		$pricing = new \PRAutoBlogger_Runware_Image_Pricing();
		$this->assertSame( 'runware:101@1', $pricing->resolve_model( 'runware:101@1' ) );
	}

	/**
	 * A non-Runware hint (e.g. a Gemini slug) falls back to schnell because
	 * the Runware provider was selected explicitly — the user wants a
	 * Runware model even if the admin option still holds a foreign slug.
	 */
	public function test_resolve_model_unknown_hint_falls_back_to_schnell(): void {
		$pricing = new \PRAutoBlogger_Runware_Image_Pricing();
		$this->assertSame( 'runware:100@1', $pricing->resolve_model( 'google/gemini-2.5-flash-image' ) );
	}

	/** FLUX.1 schnell costs ~$0.0006/image. */
	public function test_estimate_cost_schnell(): void {
		$pricing = new \PRAutoBlogger_Runware_Image_Pricing();
		$cost    = $pricing->estimate_cost( 1216, 640, 'runware:100@1' );
		$this->assertEqualsWithDelta( 0.0006, $cost, 0.000001 );
	}

	/** FLUX.1 dev costs ~$0.02/image. */
	public function test_estimate_cost_dev(): void {
		$pricing = new \PRAutoBlogger_Runware_Image_Pricing();
		$cost    = $pricing->estimate_cost( 1216, 640, 'runware:101@1' );
		$this->assertEqualsWithDelta( 0.02, $cost, 0.000001 );
	}

	/** Unknown model falls back to conservative $0.01 estimate. */
	public function test_estimate_cost_unknown_model(): void {
		$pricing = new \PRAutoBlogger_Runware_Image_Pricing();
		$cost    = $pricing->estimate_cost( 1216, 640, 'runware:999@1' );
		$this->assertEqualsWithDelta( 0.01, $cost, 0.000001 );
	}

	/** Default step count: schnell=4, dev=28. */
	public function test_default_steps(): void {
		$pricing = new \PRAutoBlogger_Runware_Image_Pricing();
		$this->assertSame( 4, $pricing->default_steps( 'runware:100@1' ) );
		$this->assertSame( 28, $pricing->default_steps( 'runware:101@1' ) );
		$this->assertSame( 4, $pricing->default_steps( 'runware:999@1' ) );
	}

	/** get_model_costs returns a non-empty map with Runware models. */
	public function test_get_model_costs(): void {
		$costs = \PRAutoBlogger_Runware_Image_Pricing::get_model_costs();
		$this->assertIsArray( $costs );
		$this->assertArrayHasKey( 'runware:100@1', $costs );
		$this->assertArrayHasKey( 'runware:101@1', $costs );
	}
}
