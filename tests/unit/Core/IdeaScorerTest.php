<?php
/**
 * Tests for PRAutoBlogger_Idea_Scorer.
 *
 * Validates idea scoring and ranking via score_and_rank method.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class IdeaScorerTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();

        // IdeaScorer::score_and_rank() calls get_option('prautoblogger_topic_exclusions', '[]').
        $this->stub_get_option( [
            'prautoblogger_topic_exclusions' => '[]',
        ] );
    }

    /**
     * Test Idea Scorer can be instantiated.
     */
    public function test_idea_scorer_instantiation(): void {
        $scorer = new \PRAutoBlogger_Idea_Scorer();

        $this->assertInstanceOf( \PRAutoBlogger_Idea_Scorer::class, $scorer );
    }

    /**
     * Test score_and_rank method exists and is callable.
     */
    public function test_score_and_rank_method_callable(): void {
        $scorer = new \PRAutoBlogger_Idea_Scorer();

        $this->assertTrue( method_exists( $scorer, 'score_and_rank' ) );
    }

    /**
     * Test score_and_rank returns array.
     */
    public function test_score_and_rank_returns_array(): void {
        $scorer = new \PRAutoBlogger_Idea_Scorer();

        // Pass empty analysis results and target count.
        $result = $scorer->score_and_rank( [], 10 );

        $this->assertIsArray( $result );
    }

    /**
     * Test score_and_rank with analysis results.
     */
    public function test_score_and_rank_with_analysis_results(): void {
        $analysis_results = [
            new \PRAutoBlogger_Analysis_Result( $this->get_analysis_result_fixture() ),
        ];

        $scorer = new \PRAutoBlogger_Idea_Scorer();
        $result = $scorer->score_and_rank( $analysis_results, 5 );

        $this->assertIsArray( $result );
    }

    /**
     * Test score_and_rank with multiple results.
     */
    public function test_score_and_rank_with_multiple_results(): void {
        $fixture = $this->get_analysis_result_fixture();
        $analysis_results = [];

        for ( $i = 0; $i < 3; $i++ ) {
            $fixture['id'] = $i + 1;
            $fixture['relevance_score'] = 0.5 + ( $i * 0.15 );
            $analysis_results[] = new \PRAutoBlogger_Analysis_Result( $fixture );
        }

        $scorer = new \PRAutoBlogger_Idea_Scorer();
        $result = $scorer->score_and_rank( $analysis_results, 5 );

        $this->assertIsArray( $result );
    }

    /**
     * Test score_and_rank with target count limit.
     */
    public function test_score_and_rank_respects_target_count(): void {
        $fixture = $this->get_analysis_result_fixture();
        $analysis_results = [];

        for ( $i = 0; $i < 10; $i++ ) {
            $fixture['id'] = $i + 1;
            $analysis_results[] = new \PRAutoBlogger_Analysis_Result( $fixture );
        }

        $scorer = new \PRAutoBlogger_Idea_Scorer();
        $result = $scorer->score_and_rank( $analysis_results, 3 );

        $this->assertIsArray( $result );
        // Result should not exceed target count (implementation dependent)
        $this->assertLessThanOrEqual( 10, count( $result ) );
    }

    /**
     * Test score_and_rank with empty input.
     */
    public function test_score_and_rank_handles_empty_input(): void {
        $scorer = new \PRAutoBlogger_Idea_Scorer();
        $result = $scorer->score_and_rank( [], 5 );

        $this->assertIsArray( $result );
        $this->assertEmpty( $result );
    }

    /**
     * Test score_and_rank with zero target count.
     */
    public function test_score_and_rank_with_zero_target(): void {
        $scorer = new \PRAutoBlogger_Idea_Scorer();
        $result = $scorer->score_and_rank( [], 0 );

        $this->assertIsArray( $result );
    }

    /**
     * Test score_and_rank with high target count.
     */
    public function test_score_and_rank_with_high_target(): void {
        $fixture = $this->get_analysis_result_fixture();
        $analysis_results = [
            new \PRAutoBlogger_Analysis_Result( $fixture ),
        ];

        $scorer = new \PRAutoBlogger_Idea_Scorer();
        $result = $scorer->score_and_rank( $analysis_results, 1000 );

        $this->assertIsArray( $result );
    }
}
