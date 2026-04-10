<?php
declare(strict_types=1);

/**
 * Unit tests for PRAutoBlogger_Editorial_Review value object.
 *
 * The editorial review verdict drives the publish/draft/reject decision.
 * A null revised_content on a "revised" verdict caused a bug (now fixed) —
 * these tests ensure the value object correctly handles edge cases.
 */
class EditorialReviewTest extends \PHPUnit\Framework\TestCase {

	public function test_approved_review(): void {
		$review = new PRAutoBlogger_Editorial_Review( [
			'verdict'       => 'approved',
			'notes'         => 'Good article, ready to publish.',
			'quality_score' => 0.85,
			'seo_score'     => 0.78,
			'issues'        => [],
		] );

		$this->assertSame( 'approved', $review->get_verdict() );
		$this->assertSame( 'Good article, ready to publish.', $review->get_notes() );
		$this->assertNull( $review->get_revised_content() );
		$this->assertEqualsWithDelta( 0.85, $review->get_quality_score(), 0.01 );
		$this->assertEqualsWithDelta( 0.78, $review->get_seo_score(), 0.01 );
		$this->assertEmpty( $review->get_issues() );
	}

	public function test_revised_review_with_content(): void {
		$review = new PRAutoBlogger_Editorial_Review( [
			'verdict'          => 'revised',
			'notes'            => 'Fixed heading structure.',
			'revised_content'  => '<h1>Improved Article</h1><p>Better content.</p>',
			'quality_score'    => 0.80,
			'seo_score'        => 0.90,
			'issues'           => [ 'H2 tags were missing' ],
		] );

		$this->assertSame( 'revised', $review->get_verdict() );
		$this->assertNotNull( $review->get_revised_content() );
		$this->assertStringContainsString( 'Improved Article', $review->get_revised_content() );
		$this->assertCount( 1, $review->get_issues() );
	}

	/**
	 * Edge case: "revised" verdict but null revised_content.
	 * This happened in production when the LLM returned a malformed response.
	 * The pipeline handles this with a null-coalesce fallback.
	 */
	public function test_revised_verdict_with_null_content(): void {
		$review = new PRAutoBlogger_Editorial_Review( [
			'verdict'          => 'revised',
			'notes'            => 'Supposedly revised but no content provided.',
			'revised_content'  => null,
		] );

		$this->assertSame( 'revised', $review->get_verdict() );
		$this->assertNull( $review->get_revised_content() );
	}

	public function test_rejected_review(): void {
		$review = new PRAutoBlogger_Editorial_Review( [
			'verdict'       => 'rejected',
			'notes'         => 'Off-topic and factually inaccurate.',
			'quality_score' => 0.2,
			'seo_score'     => 0.1,
			'issues'        => [ 'Off-topic', 'Factual errors', 'Poor structure' ],
		] );

		$this->assertSame( 'rejected', $review->get_verdict() );
		$this->assertCount( 3, $review->get_issues() );
		$this->assertLessThan( 0.5, $review->get_quality_score() );
	}

	public function test_default_scores_are_zero(): void {
		$review = new PRAutoBlogger_Editorial_Review( [
			'verdict' => 'rejected',
			'notes'   => 'Minimal data.',
		] );

		$this->assertEqualsWithDelta( 0.0, $review->get_quality_score(), 0.01 );
		$this->assertEqualsWithDelta( 0.0, $review->get_seo_score(), 0.01 );
		$this->assertEmpty( $review->get_issues() );
	}
}
