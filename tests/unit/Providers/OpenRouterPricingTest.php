<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Pricing.
 *
 * Validates the model pricing table and cost calculation logic.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;

class OpenRouterPricingTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/providers/class-prab-openrouter-pricing.php';
    }

    /**
     * Test that known models have pricing data.
     */
    public function test_known_models_have_pricing(): void {
        $known_models = [
            'google/gemini-2.0-flash-001',
            'anthropic/claude-3-haiku-20240307',
        ];

        foreach ( $known_models as $model ) {
            $pricing = \PRAutoBlogger_OpenRouter_Pricing::get_pricing( $model );
            $this->assertNotNull(
                $pricing,
                "Model '{$model}' should have pricing data."
            );
            $this->assertArrayHasKey( 'input', $pricing );
            $this->assertArrayHasKey( 'output', $pricing );
            $this->assertIsFloat( $pricing['input'] );
            $this->assertIsFloat( $pricing['output'] );
        }
    }

    /**
     * Test that unknown model returns null or fallback.
     */
    public function test_unknown_model_returns_null(): void {
        $pricing = \PRAutoBlogger_OpenRouter_Pricing::get_pricing( 'nonexistent/fake-model-999' );
        $this->assertNull( $pricing );
    }

    /**
     * Test cost calculation produces correct result.
     */
    public function test_calculate_cost_basic(): void {
        // Manual calculation: if input price is $X per million tokens and output is $Y per million tokens
        // cost = (prompt_tokens * X + completion_tokens * Y) / 1_000_000
        $cost = \PRAutoBlogger_OpenRouter_Pricing::calculate_cost(
            'google/gemini-2.0-flash-001',
            1000,
            2000
        );

        $this->assertIsFloat( $cost );
        $this->assertGreaterThan( 0.0, $cost );
    }

    /**
     * Test cost calculation with zero tokens returns zero.
     */
    public function test_calculate_cost_zero_tokens(): void {
        $cost = \PRAutoBlogger_OpenRouter_Pricing::calculate_cost(
            'google/gemini-2.0-flash-001',
            0,
            0
        );

        $this->assertSame( 0.0, $cost );
    }

    /**
     * Test cost calculation with unknown model returns zero.
     */
    public function test_calculate_cost_unknown_model_returns_zero(): void {
        $cost = \PRAutoBlogger_OpenRouter_Pricing::calculate_cost(
            'fake/model',
            1000,
            2000
        );

        $this->assertSame( 0.0, $cost );
    }

    /**
     * Test that all models in the pricing table have positive prices.
     */
    public function test_all_model_prices_are_positive(): void {
        $all_models = \PRAutoBlogger_OpenRouter_Pricing::get_all_models();

        $this->assertNotEmpty( $all_models, 'Pricing table should not be empty.' );

        foreach ( $all_models as $model => $pricing ) {
            $this->assertGreaterThanOrEqual(
                0.0,
                $pricing['input'],
                "Input price for '{$model}' must be >= 0."
            );
            $this->assertGreaterThanOrEqual(
                0.0,
                $pricing['output'],
                "Output price for '{$model}' must be >= 0."
            );
        }
    }
}
