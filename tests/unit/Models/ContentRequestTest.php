<?php
/**
 * Tests for PRAutoBlogger_Content_Request value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class ContentRequestTest extends BaseTestCase {

    /**
     * Test construction with Article Idea and parameters.
     */
    public function test_constructor_with_idea(): void {
        $idea_data = $this->get_article_idea_fixture();
        $idea      = new \PRAutoBlogger_Article_Idea( $idea_data );

        $request = new \PRAutoBlogger_Content_Request(
            $idea,
            'auto',
            'professional',
            1000,
            2500,
            'Technology and innovation',
            [ 'politics', 'religion' ]
        );

        $this->assertSame( $idea, $request->get_idea() );
        $this->assertSame( 'auto', $request->get_pipeline_mode() );
        $this->assertSame( 'professional', $request->get_tone() );
        $this->assertSame( 1000, $request->get_min_word_count() );
        $this->assertSame( 2500, $request->get_max_word_count() );
        $this->assertSame( 'Technology and innovation', $request->get_niche_description() );
        $this->assertSame( [ 'politics', 'religion' ], $request->get_topic_exclusions() );
    }

    /**
     * Test getters return correct types.
     */
    public function test_getters_return_correct_types(): void {
        $idea_data = $this->get_article_idea_fixture();
        $idea      = new \PRAutoBlogger_Article_Idea( $idea_data );

        $request = new \PRAutoBlogger_Content_Request(
            $idea,
            'auto',
            'casual',
            500,
            1500,
            'Niche',
            []
        );

        $this->assertInstanceOf( \PRAutoBlogger_Article_Idea::class, $request->get_idea() );
        $this->assertIsString( $request->get_pipeline_mode() );
        $this->assertIsString( $request->get_tone() );
        $this->assertIsInt( $request->get_min_word_count() );
        $this->assertIsInt( $request->get_max_word_count() );
        $this->assertIsString( $request->get_niche_description() );
        $this->assertIsArray( $request->get_topic_exclusions() );
    }

    /**
     * Test with empty topic exclusions.
     */
    public function test_with_empty_exclusions(): void {
        $idea_data = $this->get_article_idea_fixture();
        $idea      = new \PRAutoBlogger_Article_Idea( $idea_data );

        $request = new \PRAutoBlogger_Content_Request(
            $idea,
            'auto',
            'professional',
            1000,
            2500,
            'Tech',
            []
        );

        $this->assertEmpty( $request->get_topic_exclusions() );
    }

    /**
     * Test with different pipeline modes.
     */
    public function test_different_pipeline_modes(): void {
        $idea_data = $this->get_article_idea_fixture();
        $idea      = new \PRAutoBlogger_Article_Idea( $idea_data );

        $auto = new \PRAutoBlogger_Content_Request( $idea, 'auto', 'professional', 1000, 2500, 'niche', [] );
        $this->assertSame( 'auto', $auto->get_pipeline_mode() );

        $manual = new \PRAutoBlogger_Content_Request( $idea, 'manual', 'casual', 1000, 2500, 'niche', [] );
        $this->assertSame( 'manual', $manual->get_pipeline_mode() );
    }

    /**
     * Test with different tones.
     */
    public function test_different_tones(): void {
        $idea_data = $this->get_article_idea_fixture();
        $idea      = new \PRAutoBlogger_Article_Idea( $idea_data );

        $professional = new \PRAutoBlogger_Content_Request( $idea, 'auto', 'professional', 1000, 2500, 'niche', [] );
        $this->assertSame( 'professional', $professional->get_tone() );

        $casual = new \PRAutoBlogger_Content_Request( $idea, 'auto', 'casual', 1000, 2500, 'niche', [] );
        $this->assertSame( 'casual', $casual->get_tone() );
    }
}
