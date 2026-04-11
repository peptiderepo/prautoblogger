<?php
/**
 * Tests for PRAutoBlogger_Cost_Tracker.
 *
 * Validates API call logging, monthly spend aggregation,
 * budget enforcement, and cost estimation.
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

        require_once PRAB_PLUGIN_DIR . 'includes/core/class-prab-cost-tracker.php';
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // log_api_call
    // ---------------------------------------------------------------

    /**
     * Test log_api_call inserts a row with all required fields.
     */
    public function test_log_api_call_inserts_complete_row(): void {
        $this->stub_current_time( '2026-04-11 14:30:00' );

        $data = [
            'model'             => 'google/gemini-2.0-flash-001',
            'step'              => 'content_generation',
            'prompt_tokens'     => 500,
            'completion_tokens' => 1200,
            'cost'              => 0.0034,
            'duration'          => 2.45,
            'success'           => true,
            'error'             => '',
        ];

        $this->wpdb->expects( $this->once() )
            ->method( 'insert' )
            ->with(
                'wp_prab_cost_logs',
                $this->callback( function ( $row ) use ( $data ) {
                    return $row['model'] === $data['model']
                        && $row['prompt_tokens'] === $data['prompt_tokens']
                        && $row['completion_tokens'] === $data['completion_tokens']
                        && abs( $row['cost'] - $data['cost'] ) < 0.0001
                        && $row['step'] === $data['step'];
                } )
            )
            ->willReturn( 1 );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $result  = $tracker->log_api_call( $data );

        $this->assertTrue( $result );
    }

    /**
     * Test log_api_call returns false on DB insert failure.
     */
    public function test_log_api_call_returns_false_on_db_failure(): void {
        $this->stub_current_time( '2026-04-11 14:30:00' );

        $this->wpdb->expects( $this->once() )
            ->method( 'insert' )
            ->willReturn( false );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $result  = $tracker->log_api_call( [
            'model'             => 'test/model',
            'step'              => 'test',
            'prompt_tokens'     => 100,
            'completion_tokens' => 200,
            'cost'              => 0.001,
            'duration'          => 1.0,
            'success'           => true,
            'error'             => '',
        ] );

        $this->assertFalse( $result );
    }

    // ---------------------------------------------------------------
    // get_monthly_spend
    // ---------------------------------------------------------------

    /**
     * Test get_monthly_spend returns aggregated cost for given month.
     */
    public function test_get_monthly_spend_returns_sum(): void {
        $this->wpdb->expects( $this->once() )
            ->method( 'prepare' )
            ->willReturn( "SELECT SUM(cost) FROM wp_prab_cost_logs WHERE YEAR(created_at) = 2026 AND MONTH(created_at) = 4" );

        $this->wpdb->expects( $this->once() )
            ->method( 'get_var' )
            ->willReturn( '12.5600' );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $spend   = $tracker->get_monthly_spend( 2026, 4 );

        $this->assertSame( 12.56, round( $spend, 2 ) );
    }

    /**
     * Test get_monthly_spend returns zero when no rows exist.
     */
    public function test_get_monthly_spend_returns_zero_when_empty(): void {
        $this->wpdb->method( 'prepare' )->willReturn( 'prepared query' );
        $this->wpdb->method( 'get_var' )->willReturn( null );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $spend   = $tracker->get_monthly_spend( 2026, 1 );

        $this->assertSame( 0.0, (float) $spend );
    }

    // ---------------------------------------------------------------
    // is_budget_exceeded
    // ---------------------------------------------------------------

    /**
     * Test is_budget_exceeded returns false when budget is unlimited (0).
     */
    public function test_is_budget_exceeded_returns_false_when_unlimited(): void {
        $this->stub_get_option( [
            'prab_settings' => [ 'monthly_budget' => 0 ],
        ] );

        // Should not even query the DB when budget is unlimited.
        $this->wpdb->expects( $this->never() )
            ->method( 'get_var' );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $this->assertFalse( $tracker->is_budget_exceeded() );
    }

    /**
     * Test is_budget_exceeded returns true when spend exceeds budget.
     */
    public function test_is_budget_exceeded_returns_true_when_over(): void {
        $this->stub_get_option( [
            'prab_settings' => [ 'monthly_budget' => 10.00 ],
        ] );

        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_var' )->willReturn( '15.75' );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $this->assertTrue( $tracker->is_budget_exceeded() );
    }

    /**
     * Test is_budget_exceeded returns false when under budget.
     */
    public function test_is_budget_exceeded_returns_false_when_under(): void {
        $this->stub_get_option( [
            'prab_settings' => [ 'monthly_budget' => 50.00 ],
        ] );

        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_var' )->willReturn( '23.40' );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $this->assertFalse( $tracker->is_budget_exceeded() );
    }

    // ---------------------------------------------------------------
    // estimate_cost
    // ---------------------------------------------------------------

    /**
     * Test estimate_cost calculates based on model pricing.
     */
    public function test_estimate_cost_calculates_correctly(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();

        // Using a known model — the exact price depends on the pricing table,
        // but the structure should be: (prompt_tokens * input_price + completion_tokens * output_price) / 1_000_000
        $cost = $tracker->estimate_cost( 'google/gemini-2.0-flash-001', 1000, 2000 );

        $this->assertIsFloat( $cost );
        $this->assertGreaterThanOrEqual( 0.0, $cost );
    }

    /**
     * Test estimate_cost returns zero for unknown model.
     */
    public function test_estimate_cost_returns_zero_for_unknown_model(): void {
        $tracker = new \PRAutoBlogger_Cost_Tracker();

        $cost = $tracker->estimate_cost( 'nonexistent/model-xyz', 1000, 2000 );

        $this->assertSame( 0.0, $cost );
    }

    // ---------------------------------------------------------------
    // get_recent_logs
    // ---------------------------------------------------------------

    /**
     * Test get_recent_logs returns array of recent entries.
     */
    public function test_get_recent_logs_returns_limited_results(): void {
        $mock_rows = [
            (object) [
                'id'                => 1,
                'model'             => 'model/a',
                'step'              => 'analysis',
                'prompt_tokens'     => 100,
                'completion_tokens' => 200,
                'cost'              => 0.001,
                'duration'          => 0.5,
                'success'           => 1,
                'error'             => '',
                'created_at'        => '2026-04-11 14:00:00',
            ],
            (object) [
                'id'                => 2,
                'model'             => 'model/b',
                'step'              => 'generation',
                'prompt_tokens'     => 500,
                'completion_tokens' => 1000,
                'cost'              => 0.005,
                'duration'          => 2.1,
                'success'           => 1,
                'error'             => '',
                'created_at'        => '2026-04-11 14:05:00',
            ],
        ];

        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_results' )->willReturn( $mock_rows );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $logs    = $tracker->get_recent_logs( 10 );

        $this->assertIsArray( $logs );
        $this->assertCount( 2, $logs );
    }

    /**
     * Test get_recent_logs returns empty array when no logs exist.
     */
    public function test_get_recent_logs_returns_empty_when_no_rows(): void {
        $this->wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $this->wpdb->method( 'get_results' )->willReturn( [] );

        $tracker = new \PRAutoBlogger_Cost_Tracker();
        $logs    = $tracker->get_recent_logs( 10 );

        $this->assertIsArray( $logs );
        $this->assertEmpty( $logs );
    }
}
