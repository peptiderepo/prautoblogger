<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Pricing.
 *
 * Validates the pricing API with instance methods for model prices and cost estimation.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterPricingTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();

        // OpenRouterPricing::get_available_models() calls get_transient() to check cache.
        // Return a valid cached model list so it doesn't try to make HTTP calls.
        Functions\when( 'get_transient' )->alias(
            function ( string $key ) {
                if ( 'prautoblogger_openrouter_models' === $key ) {
                    return [
                        [
                            'id'             => 'google/gemini-2.0-flash-001',
                            'name'           => 'Gemini 2.0 Flash',
                            'context_length' => 32768,
                            'pricing'        => [ 'prompt' => 0.10, 'completion' => 0.40 ],
                        ],
                        [
                            'id'             => 'anthropic/claude-sonnet-4',
                            'name'           => 'Claude Sonnet 4',
                            'context_length' => 200000,
                            'pricing'        => [ 'prompt' => 3.00, 'completion' => 15.00 ],
                        ],
                    ];
                }
                return false;
            }
        );

        Functions\when( 'set_transient' )->justReturn( true );
    }

    /**
     * Test Pricing can be instantiated.
     */
    public function test_openrouter_pricing_instantiation(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();

        $this->assertInstanceOf( \PRAutoBlogger_OpenRouter_Pricing::class, $pricing );
    }

    /**
     * Test get_available_models returns array.
     */
    public function test_get_available_models_returns_array(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $models = $pricing->get_available_models();

        $this->assertIsArray( $models );
    }

    /**
     * Test get_available_models returns non-empty array.
     */
    public function test_get_available_models_not_empty(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $models = $pricing->get_available_models();

        $this->assertNotEmpty( $models );
    }

    /**
     * Test estimate_cost returns float.
     */
    public function test_estimate_cost_returns_float(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $cost = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 500 );

        $this->assertIsFloat( $cost );
    }

    /**
     * Test estimate_cost with zero tokens returns zero or non-negative.
     */
    public function test_estimate_cost_with_zero_tokens(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $cost = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 0, 0 );

        $this->assertIsFloat( $cost );
        $this->assertGreaterThanOrEqual( 0.0, $cost );
    }

    /**
     * Test estimate_cost with positive tokens.
     */
    public function test_estimate_cost_with_positive_tokens(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $cost = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 1000 );

        $this->assertIsFloat( $cost );
        $this->assertGreaterThanOrEqual( 0.0, $cost );
    }

    /**
     * Test estimate_cost with unknown model.
     */
    public function test_estimate_cost_with_unknown_model(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $cost = $pricing->estimate_cost( 'unknown/fake-model', 1000, 500 );

        // Unknown models may return 0.0 or a fallback value.
        $this->assertIsFloat( $cost );
        $this->assertGreaterThanOrEqual( 0.0, $cost );
    }

    /**
     * Test estimate_cost consistency.
     */
    public function test_estimate_cost_consistency(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();

        $cost1 = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 500 );
        $cost2 = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 500 );

        // Same inputs should yield same results.
        $this->assertSame( $cost1, $cost2 );
    }

    /**
     * Test estimate_cost increases with tokens.
     */
    public function test_estimate_cost_increases_with_tokens(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();

        $cost_small = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 100, 100 );
        $cost_large = $pricing->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 1000 );

        // Larger token counts should cost more or equal.
        $this->assertGreaterThanOrEqual( $cost_small, $cost_large );
    }

    /**
     * Test available models includes known OpenRouter models.
     */
    public function test_available_models_includes_popular_models(): void {
        $pricing = new \PRAutoBlogger_OpenRouter_Pricing();
        $models = $pricing->get_available_models();

        // At least some models should be present.
        $this->assertGreaterThan( 0, count( $models ) );
    }
}
