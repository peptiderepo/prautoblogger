<?php
declare(strict_types=1);

/**
 * Unit tests for Autoblogger_Article_Idea value object.
 *
 * Article ideas flow from the scorer to the generator to the editor —
 * data must survive the full pipeline without mutation or loss.
 */
class ArticleIdeaTest extends \PHPUnit\Framework\TestCase {

	public function test_constructor_sets_all_fields(): void {
		$data = [
			'topic'            => 'BPC-157 dosage protocols',
			'article_type'     => 'question',
			'suggested_title'  => 'BPC-157 Dosage Guide: What the Research Says',
			'summary'          => 'Recurring questions about optimal dosing.',
			'score'            => 0.85,
			'analysis_id'      => 7,
			'source_ids'       => [ 101, 102, 103 ],
			'key_points'       => [ 'Dosing ranges', 'Cycling protocols', 'Stacking' ],
			'target_keywords'  => [ 'bpc-157 dosage', 'bpc-157 protocol' ],
		];

		$idea = new Autoblogger_Article_Idea( $data );

		$this->assertSame( 'BPC-157 dosage protocols', $idea->get_topic() );
		$this->assertSame( 'question', $idea->get_article_type() );
		$this->assertSame( 'BPC-157 Dosage Guide: What the Research Says', $idea->get_suggested_title() );
		$this->assertSame( 'Recurring questions about optimal dosing.', $idea->get_summary() );
		$this->assertEqualsWithDelta( 0.85, $idea->get_score(), 0.001 );
		$this->assertSame( 7, $idea->get_analysis_id() );
		$this->assertSame( [ 101, 102, 103 ], $idea->get_source_ids() );
		$this->assertCount( 3, $idea->get_key_points() );
		$this->assertCount( 2, $idea->get_target_keywords() );
	}

	public function test_optional_fields_default_to_empty(): void {
		$idea = new Autoblogger_Article_Idea( [
			'topic'           => 'Test',
			'article_type'    => 'comparison',
			'suggested_title' => 'Test Title',
			'summary'         => 'Test summary',
		] );

		$this->assertEqualsWithDelta( 0.0, $idea->get_score(), 0.001 );
		$this->assertSame( 0, $idea->get_analysis_id() );
		$this->assertSame( [], $idea->get_source_ids() );
		$this->assertSame( [], $idea->get_key_points() );
		$this->assertSame( [], $idea->get_target_keywords() );
	}

	public function test_score_cast_from_string(): void {
		$idea = new Autoblogger_Article_Idea( [
			'topic'           => 'Test',
			'article_type'    => 'question',
			'suggested_title' => 'Test',
			'summary'         => 'Test',
			'score'           => '0.75',
		] );

		$this->assertIsFloat( $idea->get_score() );
		$this->assertEqualsWithDelta( 0.75, $idea->get_score(), 0.001 );
	}
}
