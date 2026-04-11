<?php
/**
 * Tests for PRAutoBlogger_Article_Idea value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class ArticleIdeaTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/models/class-prab-article-idea.php';
    }

    /**
     * Test construction with full data.
     */
    public function test_constructor_sets_all_properties(): void {
        $idea = new \PRAutoBlogger_Article_Idea(
            'The Future of BPC-157 Research',
            'An in-depth look at recent clinical trials.',
            [ 'BPC-157', 'clinical trials', 'peptides' ],
            'peptide-research',
            85.5
        );

        $this->assertSame( 'The Future of BPC-157 Research', $idea->get_title() );
        $this->assertSame( 'An in-depth look at recent clinical trials.', $idea->get_description() );
        $this->assertSame( [ 'BPC-157', 'clinical trials', 'peptides' ], $idea->get_keywords() );
        $this->assertSame( 'peptide-research', $idea->get_category() );
        $this->assertSame( 85.5, $idea->get_score() );
    }

    /**
     * Test to_array returns all fields.
     */
    public function test_to_array_returns_complete_data(): void {
        $idea = new \PRAutoBlogger_Article_Idea(
            'Test Title',
            'Test description.',
            [ 'kw1' ],
            'general',
            50.0
        );

        $array = $idea->to_array();
        $this->assertIsArray( $array );
        $this->assertArrayHasKey( 'title', $array );
        $this->assertArrayHasKey( 'description', $array );
        $this->assertArrayHasKey( 'keywords', $array );
        $this->assertArrayHasKey( 'category', $array );
        $this->assertArrayHasKey( 'score', $array );
    }

    /**
     * Test score can be zero.
     */
    public function test_allows_zero_score(): void {
        $idea = new \PRAutoBlogger_Article_Idea(
            'Low Score Idea',
            'Not great.',
            [],
            'general',
            0.0
        );

        $this->assertSame( 0.0, $idea->get_score() );
    }
}
