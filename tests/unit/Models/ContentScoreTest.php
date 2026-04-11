<?php
/**
 * Tests for PRAutoBlogger_Content_Score value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class ContentScoreTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/models/class-prab-content-score.php';
    }

    /**
     * Test construction with scoring dimensions.
     */
    public function test_constructor_sets_all_dimensions(): void {
        $score = new \PRAutoBlogger_Content_Score(
            8.0,  // relevance
            7.5,  // quality
            9.0,  // originality
            6.5,  // seo
            7.75  // overall
        );

        $this->assertSame( 8.0, $score->get_relevance() );
        $this->assertSame( 7.5, $score->get_quality() );
        $this->assertSame( 9.0, $score->get_originality() );
        $this->assertSame( 6.5, $score->get_seo() );
        $this->assertSame( 7.75, $score->get_overall() );
    }

    /**
     * Test boundary scores (0 and 10).
     */
    public function test_boundary_scores(): void {
        $min = new \PRAutoBlogger_Content_Score( 0.0, 0.0, 0.0, 0.0, 0.0 );
        $this->assertSame( 0.0, $min->get_overall() );

        $max = new \PRAutoBlogger_Content_Score( 10.0, 10.0, 10.0, 10.0, 10.0 );
        $this->assertSame( 10.0, $max->get_overall() );
    }

    /**
     * Test to_array completeness.
     */
    public function test_to_array_returns_all_dimensions(): void {
        $score = new \PRAutoBlogger_Content_Score( 5.0, 5.0, 5.0, 5.0, 5.0 );

        $array = $score->to_array();
        $this->assertArrayHasKey( 'relevance', $array );
        $this->assertArrayHasKey( 'quality', $array );
        $this->assertArrayHasKey( 'originality', $array );
        $this->assertArrayHasKey( 'seo', $array );
        $this->assertArrayHasKey( 'overall', $array );
    }
}
