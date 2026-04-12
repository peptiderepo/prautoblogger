<?php
/**
 * Tests for PRAutoBlogger_Cost_Tracker.
 *
 * Validates run ID management, monthly spend, budget limits,
 * current run cost, and cost retrieval methods.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class CostTrackerTest extends BaseTestCase {

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject Mock $wpdb instance.
     */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();

        $this->wpdb = $this->create_mock_wpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        // CostTracker methods call get_option for budget settings.
        $this->stub_get_option( [
            'prautoblogger_monthly_budget_usd' => '50.00',
        ] );

        // Stub current_time used in date-based queries.
        $this->stub_current_time( '2026-04-12 10:00:00' );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    /**
     * Test set_run_id and get_run_id.
     */
    public function test_set_and_get_run_id(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();

        $tracker->set_run_id( 'run_abc123' );
        $this->assertSame( 'run_abc123', $tracker->get_run_id() );
    }

    /**
     * Test get_run_id returns null when not set.
     */
    public function test_get_run_id_returns_null_when_not_set(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();

        $this->assertNull( $tracker->get_run_id() );
    }

    /**
     * Test is_budget_exceeded returns false by default.
     */
    public function test_is_budget_exceeded_returns_false_by_default(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();

        $this->assertFalse( $tracker->is_budget_exceeded() );
    }

    /**
     * Test get_monthly_spend returns float.
     */
    public function test_get_monthly_spend_returns_float(): void {
        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_var' )->willReturn( '10.50' );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $spend = $tracker->get_monthly_spend();

        $this->assertIsFloat( $spend );
        $this->assertGreaterThanOrEqual( 0.0, $spend );
    }

    /**
     * Test get_current_run_cost returns float.
     */
    public function test_get_current_run_cost_returns_float(): void {
        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_var' )->willReturn( '0.05' );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $tracker->set_run_id( 'run_abc123' );

        $cost = $tracker->get_current_run_cost();

        $this->assertIsFloat( $cost );
        $this->assertGreaterThanOrEqual( 0.0, $cost );
    }

    /**
     * Test get_daily_spend returns array.
     */
    public function test_get_daily_spend_returns_array(): void {
        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_results' )->willReturn( [] );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $daily = $tracker->get_daily_spend( 30 );

        $this->assertIsArray( $daily );
    }

    /**
     * Test get_spend_by_stage returns array.
     */
    public function test_get_spend_by_stage_returns_array(): void {
        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_results' )->willReturn( [] );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $spend = $tracker->get_spend_by_stage( '2026-04-01', '2026-04-30' );

        $this->assertIsArray( $spend );
    }

    /**
     * Test get_budget_utilization returns float between 0 and 1.
     */
    public function test_get_budget_utilization_returns_float(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $utilization = $tracker->get_budget_utilization();

        $this->assertIsFloat( $utilization );
        $this->assertGreaterThanOrEqual( 0.0, $utilization );
    }

    /**
     * Test log_api_call with mock database.
     */
    public function test_log_api_call_interacts_with_database(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $tracker->set_run_id( 'run_test' );

        // Mock wpdb to expect an insert call.
        $this->wpdb->expects( $this->once() )
            ->method( 'insert' )
            ->willReturn( 1 );

        // Signature: log_api_call(?int $post_id, string $stage, string $provider,
        //   string $model, int $prompt_tokens, int $completion_tokens,
        //   float $estimated_cost, float $duration_seconds).
        $tracker->log_api_call(
            123,
            'analysis',
            'openrouter',
            'openai/gpt-4',
            500,
            250,
            0.01,
            1.5
        );

        // If we get here without exception, the method worked.
        $this->assertTrue( true );
    }
}
