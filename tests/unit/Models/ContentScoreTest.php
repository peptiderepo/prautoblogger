<?php
/**
 * Tests for PRAutoBlogger_Content_Score value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class ContentScoreTest extends BaseTestCase {

    /**
     * Test construction with array.
     */
    public function test_constructor_with_array(): void {
        $data = $this->get_content_score_fixture();

        $score = new \PRAutoBlogger_Content_Score( $data );

        $this->assertSame( 1, $score->get_id() );
        $this->assertSame( 123, $score->get_post_id() );
        $this->assertSame( 500, $score->get_pageviews() );
        $this->assertSame( 3.5, $score->get_avg_time_on_page() );
        $this->assertSame( 0.25, $score->get_bounce_rate() );
        $this->assertSame( 15, $score->get_comment_count() );
        $this->assertSame( 0.82, $score->get_composite_score() );
        $this->assertIsArray( $score->get_score_factors() );
        $this->assertSame( '2026-04-12 10:00:00', $score->get_measured_at() );
    }

    /**
     * Test getters return correct types.
     */
    public function test_getters_return_correct_types(): void {
        $score = new \PRAutoBlogger_Content_Score(
            $this->get_content_score_fixture()
        );

        $this->assertIsInt( $score->get_id() );
        $this->assertIsInt( $score->get_post_id() );
        $this->assertIsInt( $score->get_pageviews() );
        $this->assertIsFloat( $score->get_avg_time_on_page() );
        $this->assertIsFloat( $score->get_bounce_rate() );
        $this->assertIsInt( $score->get_comment_count() );
        $this->assertIsFloat( $score->get_composite_score() );
        $this->assertIsArray( $score->get_score_factors() );
        $this->assertIsString( $score->get_measured_at() );
    }

    /**
     * Test with zero pageviews.
     */
    public function test_with_zero_pageviews(): void {
        $data = $this->get_content_score_fixture();
        $data['pageviews'] = 0;

        $score = new \PRAutoBlogger_Content_Score( $data );

        $this->assertSame( 0, $score->get_pageviews() );
    }

    /**
     * Test with zero composite score.
     */
    public function test_with_zero_composite_score(): void {
        $data = $this->get_content_score_fixture();
        $data['composite_score'] = 0.0;

        $score = new \PRAutoBlogger_Content_Score( $data );

        $this->assertSame( 0.0, $score->get_composite_score() );
    }

    /**
     * Test with perfect composite score.
     */
    public function test_with_perfect_composite_score(): void {
        $data = $this->get_content_score_fixture();
        $data['composite_score'] = 1.0;

        $score = new \PRAutoBlogger_Content_Score( $data );

        $this->assertSame( 1.0, $score->get_composite_score() );
    }

    /**
     * Test with zero bounce rate.
     */
    public function test_with_zero_bounce_rate(): void {
        $data = $this->get_content_score_fixture();
        $data['bounce_rate'] = 0.0;

        $score = new \PRAutoBlogger_Content_Score( $data );

        $this->assertSame( 0.0, $score->get_bounce_rate() );
    }

    /**
     * Test with high bounce rate.
     */
    public function test_with_high_bounce_rate(): void {
        $data = $this->get_content_score_fixture();
        $data['bounce_rate'] = 0.95;

        $score = new \PRAutoBlogger_Content_Score( $data );

        $this->assertSame( 0.95, $score->get_bounce_rate() );
    }

    /**
     * Test with empty score factors.
     */
    public function test_with_empty_score_factors(): void {
        $data = $this->get_content_score_fixture();
        $data['score_factors'] = [];

        $score = new \PRAutoBlogger_Content_Score( $data );

        $this->assertEmpty( $score->get_score_factors() );
    }

    /**
     * Test to_db_row returns array.
     */
    public function test_to_db_row_returns_array(): void {
        $score = new \PRAutoBlogger_Content_Score(
            $this->get_content_score_fixture()
        );

        $row = $score->to_db_row();

        $this->assertIsArray( $row );
        $this->assertArrayHasKey( 'post_id', $row );
        $this->assertArrayHasKey( 'pageviews', $row );
        $this->assertArrayHasKey( 'composite_score', $row );
    }
}
