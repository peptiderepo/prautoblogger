<?php
/**
 * Tests for PRAutoBlogger_Content_Analyzer.
 *
 * Validates the LLM analysis pipeline: prompt construction,
 * response parsing, cost tracking integration, and error handling.
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

        require_once PRAB_PLUGIN_DIR . 'includes/models/class-prab-source-data.php';
        require_once PRAB_PLUGIN_DIR . 'includes/models/class-prab-analysis-result.php';
        require_once PRAB_PLUGIN_DIR . 'includes/core/class-prab-content-analyzer.php';

        // Mock the LLM provider interface.
        $this->mock_provider = $this->createMock( \PRAutoBlogger_LLM_Provider::class );

        // Mock the cost tracker.
        $this->mock_cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
    }

    /**
     * Test analyze returns AnalysisResult on successful LLM response.
     */
    public function test_analyze_returns_analysis_result_on_success(): void {
        $source = new \PRAutoBlogger_Source_Data(
            'BPC-157 Shows Promise in Gut Healing',
            'https://example.com/bpc157',
            'BPC-157 is a synthetic peptide that has shown remarkable healing properties...',
            'rss'
        );

        $llm_response = [
            'content' => json_encode( [
                'category'        => 'peptide-research',
                'keywords'        => [ 'BPC-157', 'gut healing', 'peptide therapy' ],
                'relevance_score' => 9,
                'summary'         => 'Article discusses BPC-157 healing properties for gut issues.',
                'topics'          => [ 'regenerative medicine', 'gastrointestinal health' ],
            ] ),
            'usage' => [
                'prompt_tokens'     => 200,
                'completion_tokens' => 150,
            ],
        ];

        $this->mock_provider->expects( $this->once() )
            ->method( 'chat_completion' )
            ->willReturn( $llm_response );

        $this->mock_cost_tracker->expects( $this->once() )
            ->method( 'log_api_call' );

        $analyzer = new \PRAutoBlogger_Content_Analyzer(
            $this->mock_provider,
            $this->mock_cost_tracker
        );

        $result = $analyzer->analyze( $source );

        $this->assertInstanceOf( \PRAutoBlogger_Analysis_Result::class, $result );
        $this->assertSame( 'peptide-research', $result->get_category() );
        $this->assertSame( 9, $result->get_relevance_score() );
        $this->assertContains( 'BPC-157', $result->get_keywords() );
    }

    /**
     * Test analyze handles LLM error response gracefully.
     */
    public function test_analyze_handles_llm_error(): void {
        $source = new \PRAutoBlogger_Source_Data(
            'Test Article',
            'https://example.com',
            'Content.',
            'rss'
        );

        $this->mock_provider->expects( $this->once() )
            ->method( 'chat_completion' )
            ->willReturn( [ 'error' => 'Rate limit exceeded' ] );

        $this->mock_cost_tracker->expects( $this->once() )
            ->method( 'log_api_call' )
            ->with( $this->callback( function ( $data ) {
                return false === $data['success'];
            } ) );

        $analyzer = new \PRAutoBlogger_Content_Analyzer(
            $this->mock_provider,
            $this->mock_cost_tracker
        );

        $result = $analyzer->analyze( $source );

        $this->assertNull( $result );
    }

    /**
     * Test analyze handles malformed JSON in LLM content.
     */
    public function test_analyze_handles_malformed_llm_json(): void {
        $source = new \PRAutoBlogger_Source_Data(
            'Test', 'https://example.com', 'Content.', 'rss'
        );

        $this->mock_provider->method( 'chat_completion' )
            ->willReturn( [
                'content' => 'This is not valid JSON {{{',
                'usage'   => [ 'prompt_tokens' => 100, 'completion_tokens' => 50 ],
            ] );

        $analyzer = new \PRAutoBlogger_Content_Analyzer(
            $this->mock_provider,
            $this->mock_cost_tracker
        );

        $result = $analyzer->analyze( $source );

        $this->assertNull( $result );
    }

    /**
     * Test analyze respects budget check before calling LLM.
     */
    public function test_analyze_skips_when_budget_exceeded(): void {
        $source = new \PRAutoBlogger_Source_Data(
            'Test', 'https://example.com', 'Content.', 'rss'
        );

        $this->mock_cost_tracker->method( 'is_budget_exceeded' )->willReturn( true );

        // Provider should never be called if budget is exceeded.
        $this->mock_provider->expects( $this->never() )
            ->method( 'chat_completion' );

        $analyzer = new \PRAutoBlogger_Content_Analyzer(
            $this->mock_provider,
            $this->mock_cost_tracker
        );

        $result = $analyzer->analyze( $source );

        $this->assertNull( $result );
    }
}
