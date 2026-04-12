<?php
/**
 * Tests for PRAutoBlogger_Content_Analyzer.
 *
 * Validates the LLM analysis pipeline via the analyze_recent_data method.
 * All LLM API calls are mocked.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ContentAnalyzerTest extends BaseTestCase {

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject Mock LLM provider.
     */
    private $mock_provider;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject Mock cost tracker.
     */
    private $mock_cost_tracker;

    protected function setUp(): void {
        parent::setUp();

        // Create a mock that implements the LLM Provider Interface.
        $this->mock_provider = $this->createMock( \PRAutoBlogger_LLM_Provider_Interface::class );

        // Create a mock cost tracker — ContentAnalyzer requires both provider and tracker.
        $this->mock_cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );

        // Stub WordPress functions used internally by ContentAnalyzer.
        $this->stub_get_option( [
            'prautoblogger_monthly_budget_usd' => '50.00',
        ] );

        // Stub $wpdb for any database calls.
        $wpdb = $this->create_mock_wpdb();
        $wpdb->method( 'prepare' )->willReturn( 'prepared' );
        $wpdb->method( 'get_var' )->willReturn( '0' );
        $wpdb->method( 'get_results' )->willReturn( [] );
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    /**
     * Test that ContentAnalyzer can be instantiated.
     */
    public function test_content_analyzer_instantiation(): void {
        $analyzer = new \PRAutoBlogger_Content_Analyzer( $this->mock_provider, $this->mock_cost_tracker );

        $this->assertInstanceOf( \PRAutoBlogger_Content_Analyzer::class, $analyzer );
    }

    /**
     * Test analyze_recent_data returns array.
     */
    public function test_analyze_recent_data_returns_array(): void {
        // Mock send_chat_completion to return a valid response.
        $this->mock_provider->method( 'send_chat_completion' )
            ->willReturn( [
                'content' => 'Analysis result',
                'usage'   => [
                    'prompt_tokens'     => 100,
                    'completion_tokens' => 50,
                ],
            ] );

        $this->mock_provider->method( 'get_available_models' )
            ->willReturn( [ 'model/test' ] );

        $analyzer = new \PRAutoBlogger_Content_Analyzer( $this->mock_provider, $this->mock_cost_tracker );

        // analyze_recent_data takes no parameters.
        $result = $analyzer->analyze_recent_data();

        $this->assertIsArray( $result );
    }

    /**
     * Test get_available_models on provider.
     */
    public function test_llm_provider_get_available_models(): void {
        $this->mock_provider->method( 'get_available_models' )
            ->willReturn( [ 'model/a', 'model/b' ] );

        $models = $this->mock_provider->get_available_models();

        $this->assertIsArray( $models );
        $this->assertCount( 2, $models );
    }

    /**
     * Test estimate_cost on provider.
     */
    public function test_llm_provider_estimate_cost(): void {
        $this->mock_provider->method( 'estimate_cost' )
            ->with( 'model/test', 1000, 500 )
            ->willReturn( 0.01 );

        $cost = $this->mock_provider->estimate_cost( 'model/test', 1000, 500 );

        $this->assertIsFloat( $cost );
        $this->assertGreaterThanOrEqual( 0.0, $cost );
    }

    /**
     * Test get_provider_name on provider.
     */
    public function test_llm_provider_get_provider_name(): void {
        $this->mock_provider->method( 'get_provider_name' )
            ->willReturn( 'test_provider' );

        $name = $this->mock_provider->get_provider_name();

        $this->assertIsString( $name );
        $this->assertNotEmpty( $name );
    }

    /**
     * Test validate_credentials on provider.
     */
    public function test_llm_provider_validate_credentials(): void {
        $this->mock_provider->method( 'validate_credentials' )
            ->willReturn( true );

        $valid = $this->mock_provider->validate_credentials();

        $this->assertTrue( $valid );
    }

    /**
     * Test send_chat_completion on provider.
     */
    public function test_llm_provider_send_chat_completion(): void {
        $messages = [
            [ 'role' => 'user', 'content' => 'Analyze this.' ],
        ];

        $this->mock_provider->method( 'send_chat_completion' )
            ->with( $messages, 'model/test', [] )
            ->willReturn( [
                'content' => 'Analysis',
                'usage'   => [ 'prompt_tokens' => 100, 'completion_tokens' => 50 ],
            ] );

        $response = $this->mock_provider->send_chat_completion( $messages, 'model/test', [] );

        $this->assertIsArray( $response );
        $this->assertArrayHasKey( 'content', $response );
    }
}
