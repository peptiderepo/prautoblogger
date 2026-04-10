<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Unit tests for Autoblogger_OpenRouter_Pricing cost estimation.
 *
 * Cost calculation accuracy directly affects budget enforcement and the
 * metrics dashboard. Getting this wrong means either overspending or
 * blocking generation when there's still budget left.
 */
class OpenRouterPricingTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock WP functions used by the pricing class.
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		// Mock the Logger singleton so calls don't break.
		// The pricing class calls Logger::instance()->warning() for unknown models.
		if ( ! class_exists( 'Autoblogger_Logger' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/core/class-logger.php';
		}

		// Mock WP functions the Logger needs.
		Functions\when( 'current_time' )->justReturn( '2026-04-10 12:00:00' );

		// Mock Encryption class (used by get_api_key).
		if ( ! class_exists( 'Autoblogger_Encryption' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/class-encryption.php';
		}

		// Load the class under test.
		if ( ! class_exists( 'Autoblogger_OpenRouter_Pricing' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/providers/class-openrouter-pricing.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_known_model_cost_calculation(): void {
		$pricing = new Autoblogger_OpenRouter_Pricing();

		// Claude 3.5 Haiku: $0.80/M prompt, $4.00/M completion
		// 1000 prompt tokens = 1000 * 0.80 / 1_000_000 = $0.0008
		// 500 completion tokens = 500 * 4.00 / 1_000_000 = $0.002
		// Total = $0.0028
		$cost = $pricing->estimate_cost( 'anthropic/claude-3.5-haiku', 1000, 500 );
		$this->assertEqualsWithDelta( 0.0028, $cost, 0.00001 );
	}

	public function test_claude_sonnet_cost(): void {
		$pricing = new Autoblogger_OpenRouter_Pricing();

		// Claude Sonnet 4: $3.00/M prompt, $15.00/M completion
		// 2000 prompt + 1000 completion
		// = (2000 * 3.0 / 1M) + (1000 * 15.0 / 1M)
		// = 0.006 + 0.015 = 0.021
		$cost = $pricing->estimate_cost( 'anthropic/claude-sonnet-4', 2000, 1000 );
		$this->assertEqualsWithDelta( 0.021, $cost, 0.00001 );
	}

	public function test_gpt4o_mini_cost(): void {
		$pricing = new Autoblogger_OpenRouter_Pricing();

		// GPT-4o-mini: $0.15/M prompt, $0.60/M completion
		// 5000 prompt + 2000 completion
		// = (5000 * 0.15 / 1M) + (2000 * 0.60 / 1M)
		// = 0.00075 + 0.0012 = 0.00195
		$cost = $pricing->estimate_cost( 'openai/gpt-4o-mini', 5000, 2000 );
		$this->assertEqualsWithDelta( 0.00195, $cost, 0.00001 );
	}

	public function test_zero_tokens_returns_zero_cost(): void {
		$pricing = new Autoblogger_OpenRouter_Pricing();
		$cost = $pricing->estimate_cost( 'anthropic/claude-3.5-haiku', 0, 0 );
		$this->assertEqualsWithDelta( 0.0, $cost, 0.00001 );
	}

	public function test_unknown_model_uses_conservative_estimate(): void {
		$pricing = new Autoblogger_OpenRouter_Pricing();

		// Unknown model: $10.00/M prompt, $30.00/M completion (conservative fallback)
		// 1000 prompt + 1000 completion
		// = (1000 * 10 / 1M) + (1000 * 30 / 1M) = 0.01 + 0.03 = 0.04
		$cost = $pricing->estimate_cost( 'unknown/fake-model', 1000, 1000 );
		$this->assertEqualsWithDelta( 0.04, $cost, 0.00001 );
	}

	public function test_cost_scales_linearly_with_tokens(): void {
		$pricing = new Autoblogger_OpenRouter_Pricing();

		$cost_1x = $pricing->estimate_cost( 'anthropic/claude-3.5-haiku', 1000, 500 );
		$cost_2x = $pricing->estimate_cost( 'anthropic/claude-3.5-haiku', 2000, 1000 );

		// 2x the tokens should be exactly 2x the cost.
		$this->assertEqualsWithDelta( $cost_1x * 2, $cost_2x, 0.00001 );
	}

	public function test_prompt_and_completion_priced_separately(): void {
		$pricing = new Autoblogger_OpenRouter_Pricing();

		// For a model where prompt and completion prices differ (all of them),
		// swapping prompt/completion tokens should give a different cost.
		$cost_a = $pricing->estimate_cost( 'anthropic/claude-sonnet-4', 1000, 500 );
		$cost_b = $pricing->estimate_cost( 'anthropic/claude-sonnet-4', 500, 1000 );

		// Sonnet: prompt=$3/M, completion=$15/M
		// A: 1000*3/1M + 500*15/1M = 0.003 + 0.0075 = 0.0105
		// B: 500*3/1M + 1000*15/1M = 0.0015 + 0.015 = 0.0165
		$this->assertEqualsWithDelta( 0.0105, $cost_a, 0.00001 );
		$this->assertEqualsWithDelta( 0.0165, $cost_b, 0.00001 );
		$this->assertNotEquals( $cost_a, $cost_b );
	}

	/**
	 * Verify that a realistic full-article generation stays under reasonable cost.
	 * This is a sanity check: if this ever fails, pricing data may be stale.
	 */
	public function test_full_article_cost_sanity_check(): void {
		$pricing = new Autoblogger_OpenRouter_Pricing();

		// Typical multi-step article: analysis + outline + draft + polish + review
		// Using Haiku for analysis, Sonnet for the rest.
		$analysis_cost = $pricing->estimate_cost( 'anthropic/claude-3.5-haiku', 8000, 2000 );
		$outline_cost  = $pricing->estimate_cost( 'anthropic/claude-sonnet-4', 3000, 1000 );
		$draft_cost    = $pricing->estimate_cost( 'anthropic/claude-sonnet-4', 5000, 3000 );
		$polish_cost   = $pricing->estimate_cost( 'anthropic/claude-sonnet-4', 6000, 3000 );
		$review_cost   = $pricing->estimate_cost( 'anthropic/claude-sonnet-4', 5000, 2000 );

		$total = $analysis_cost + $outline_cost + $draft_cost + $polish_cost + $review_cost;

		// A full article should cost between $0.01 and $1.00.
		// If outside this range, pricing constants are probably wrong.
		$this->assertGreaterThan( 0.01, $total, 'Full article too cheap — pricing data may be zero.' );
		$this->assertLessThan( 1.00, $total, 'Full article too expensive — pricing data may be inflated.' );
	}
}
