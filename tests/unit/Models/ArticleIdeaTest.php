<?php
/**
 * Tests for PRAutoBlogger_Article_Idea value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class ArticleIdeaTest extends BaseTestCase {

    /**
     * Test construction with array.
     */
    public function test_constructor_with_array(): void {
        $data = $this->get_article_idea_fixture();

        $idea = new \PRAutoBlogger_Article_Idea( $data );

        $this->assertSame( 'Test Article Topic', $idea->get_topic() );
        $this->assertSame( 'guide', $idea->get_article_type() );
        $this->assertSame( 'Ultimate Guide to Test Topic', $idea->get_suggested_title() );
        $this->assertSame( 'A comprehensive guide to test topic.', $idea->get_summary() );
        $this->assertSame( 0.92, $idea->get_score() );
        $this->assertSame( 1, $idea->get_analysis_id() );
        $this->assertSame( [ 1, 2 ], $idea->get_source_ids() );
        $this->assertSame( [ 'Point 1', 'Point 2', 'Point 3' ], $idea->get_key_points() );
        $this->assertSame( [ 'test', 'keyword', 'example' ], $idea->get_target_keywords() );
    }

    /**
     * Test getters return correct types.
     */
    public function test_getters_return_correct_types(): void {
        $idea = new \PRAutoBlogger_Article_Idea(
            $this->get_article_idea_fixture()
        );

        $this->assertIsString( $idea->get_topic() );
        $this->assertIsString( $idea->get_article_type() );
        $this->assertIsString( $idea->get_suggested_title() );
        $this->assertIsString( $idea->get_summary() );
        $this->assertIsFloat( $idea->get_score() );
        $this->assertIsInt( $idea->get_analysis_id() );
        $this->assertIsArray( $idea->get_source_ids() );
        $this->assertIsArray( $idea->get_key_points() );
        $this->assertIsArray( $idea->get_target_keywords() );
    }

    /**
     * Test with empty arrays.
     */
    public function test_with_empty_arrays(): void {
        $data = $this->get_article_idea_fixture();
        $data['source_ids']      = [];
        $data['key_points']      = [];
        $data['target_keywords'] = [];

        $idea = new \PRAutoBlogger_Article_Idea( $data );

        $this->assertEmpty( $idea->get_source_ids() );
        $this->assertEmpty( $idea->get_key_points() );
        $this->assertEmpty( $idea->get_target_keywords() );
    }

    /**
     * Test with zero score.
     */
    public function test_with_zero_score(): void {
        $data = $this->get_article_idea_fixture();
        $data['score'] = 0.0;

        $idea = new \PRAutoBlogger_Article_Idea( $data );

        $this->assertSame( 0.0, $idea->get_score() );
    }

    /**
     * Test with high score.
     */
    public function test_with_high_score(): void {
        $data = $this->get_article_idea_fixture();
        $data['score'] = 1.0;

        $idea = new \PRAutoBlogger_Article_Idea( $data );

        $this->assertSame( 1.0, $idea->get_score() );
    }
}
