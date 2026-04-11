<?php
/**
 * Tests for PRAutoBlogger_Analysis_Result value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class AnalysisResultTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/models/class-prab-analysis-result.php';
    }

    /**
     * Test construction with valid analysis data.
     */
    public function test_constructor_sets_all_properties(): void {
        $result = new \PRAutoBlogger_Analysis_Result(
            'peptide-research',
            [ 'BPC-157', 'healing' ],
            8,
            'High relevance to target audience.',
            [ 'regenerative medicine', 'peptide therapy' ]
        );

        $this->assertSame( 'peptide-research', $result->get_category() );
        $this->assertSame( [ 'BPC-157', 'healing' ], $result->get_keywords() );
        $this->assertSame( 8, $result->get_relevance_score() );
        $this->assertSame( 'High relevance to target audience.', $result->get_summary() );
        $this->assertSame( [ 'regenerative medicine', 'peptide therapy' ], $result->get_topics() );
    }

    /**
     * Test relevance score is clamped between 0 and 10.
     */
    public function test_relevance_score_boundaries(): void {
        $high = new \PRAutoBlogger_Analysis_Result( 'cat', [], 10, 'Max score.', [] );
        $this->assertSame( 10, $high->get_relevance_score() );

        $low = new \PRAutoBlogger_Analysis_Result( 'cat', [], 0, 'Min score.', [] );
        $this->assertSame( 0, $low->get_relevance_score() );
    }

    /**
     * Test to_array returns complete representation.
     */
    public function test_to_array_completeness(): void {
        $result = new \PRAutoBlogger_Analysis_Result(
            'news',
            [ 'test' ],
            5,
            'Summary text.',
            [ 'topic1' ]
        );

        $array = $result->to_array();
        $this->assertIsArray( $array );
        $this->assertArrayHasKey( 'category', $array );
        $this->assertArrayHasKey( 'keywords', $array );
        $this->assertArrayHasKey( 'relevance_score', $array );
        $this->assertArrayHasKey( 'summary', $array );
        $this->assertArrayHasKey( 'topics', $array );
    }
}
