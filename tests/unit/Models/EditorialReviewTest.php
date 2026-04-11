<?php
/**
 * Tests for PRAutoBlogger_Editorial_Review value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class EditorialReviewTest extends BaseTestCase {

    /**
     * Test construction with array.
     */
    public function test_constructor_with_array(): void {
        $data = $this->get_editorial_review_fixture();

        $review = new \PRAutoBlogger_Editorial_Review( $data );

        $this->assertSame( 'approved', $review->get_verdict() );
        $this->assertSame( 'Article is well-structured.', $review->get_notes() );
        $this->assertNull( $review->get_revised_content() );
        $this->assertSame( 0.88, $review->get_quality_score() );
        $this->assertSame( 0.92, $review->get_seo_score() );
        $this->assertEmpty( $review->get_issues() );
    }

    /**
     * Test getters return correct types.
     */
    public function test_getters_return_correct_types(): void {
        $review = new \PRAutoBlogger_Editorial_Review(
            $this->get_editorial_review_fixture()
        );

        $this->assertIsString( $review->get_verdict() );
        $this->assertIsString( $review->get_notes() );
        $this->assertIsFloat( $review->get_quality_score() );
        $this->assertIsFloat( $review->get_seo_score() );
        $this->assertIsArray( $review->get_issues() );
    }

    /**
     * Test with revision notes.
     */
    public function test_with_revised_content(): void {
        $data = $this->get_editorial_review_fixture();
        $data['revised_content'] = 'Here is the revised article content.';

        $review = new \PRAutoBlogger_Editorial_Review( $data );

        $this->assertSame( 'Here is the revised article content.', $review->get_revised_content() );
    }

    /**
     * Test with issues.
     */
    public function test_with_issues(): void {
        $data = $this->get_editorial_review_fixture();
        $data['verdict'] = 'revision_requested';
        $data['issues']  = [ 'Grammar issue on line 5', 'Missing citation for claim' ];

        $review = new \PRAutoBlogger_Editorial_Review( $data );

        $this->assertSame( 'revision_requested', $review->get_verdict() );
        $this->assertCount( 2, $review->get_issues() );
        $this->assertStringContainsString( 'Grammar', $review->get_issues()[0] );
    }

    /**
     * Test with rejected verdict.
     */
    public function test_with_rejected_verdict(): void {
        $data = $this->get_editorial_review_fixture();
        $data['verdict']        = 'rejected';
        $data['quality_score']  = 0.35;
        $data['seo_score']      = 0.25;
        $data['issues']         = [ 'Content is off-topic', 'Poor quality' ];

        $review = new \PRAutoBlogger_Editorial_Review( $data );

        $this->assertSame( 'rejected', $review->get_verdict() );
        $this->assertLessThan( 0.5, $review->get_quality_score() );
    }

    /**
     * Test with perfect scores.
     */
    public function test_with_perfect_scores(): void {
        $data = $this->get_editorial_review_fixture();
        $data['quality_score'] = 1.0;
        $data['seo_score']     = 1.0;

        $review = new \PRAutoBlogger_Editorial_Review( $data );

        $this->assertSame( 1.0, $review->get_quality_score() );
        $this->assertSame( 1.0, $review->get_seo_score() );
    }

    /**
     * Test with zero scores.
     */
    public function test_with_zero_scores(): void {
        $data = $this->get_editorial_review_fixture();
        $data['quality_score'] = 0.0;
        $data['seo_score']     = 0.0;

        $review = new \PRAutoBlogger_Editorial_Review( $data );

        $this->assertSame( 0.0, $review->get_quality_score() );
        $this->assertSame( 0.0, $review->get_seo_score() );
    }
}
