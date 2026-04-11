<?php
/**
 * Tests for PRAutoBlogger_Editorial_Review value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class EditorialReviewTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/models/class-prab-editorial-review.php';
    }

    /**
     * Test construction with approval.
     */
    public function test_approved_review(): void {
        $review = new \PRAutoBlogger_Editorial_Review(
            true,
            'Content meets quality standards.',
            8.5,
            [ 'Good structure', 'Clear language' ],
            []
        );

        $this->assertTrue( $review->is_approved() );
        $this->assertSame( 'Content meets quality standards.', $review->get_feedback() );
        $this->assertSame( 8.5, $review->get_quality_score() );
        $this->assertSame( [ 'Good structure', 'Clear language' ], $review->get_strengths() );
        $this->assertEmpty( $review->get_issues() );
    }

    /**
     * Test construction with rejection and issues.
     */
    public function test_rejected_review_with_issues(): void {
        $review = new \PRAutoBlogger_Editorial_Review(
            false,
            'Content needs improvement.',
            4.0,
            [],
            [ 'Too short', 'Missing citations', 'Unclear conclusion' ]
        );

        $this->assertFalse( $review->is_approved() );
        $this->assertCount( 3, $review->get_issues() );
        $this->assertSame( 4.0, $review->get_quality_score() );
    }

    /**
     * Test to_array completeness.
     */
    public function test_to_array_returns_all_fields(): void {
        $review = new \PRAutoBlogger_Editorial_Review(
            true, 'OK', 7.0, [ 'Good' ], []
        );

        $array = $review->to_array();
        $this->assertArrayHasKey( 'approved', $array );
        $this->assertArrayHasKey( 'feedback', $array );
        $this->assertArrayHasKey( 'quality_score', $array );
        $this->assertArrayHasKey( 'strengths', $array );
        $this->assertArrayHasKey( 'issues', $array );
    }
}
