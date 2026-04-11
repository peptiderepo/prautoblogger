<?php
/**
 * Tests for PRAutoBlogger_Analysis_Result value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class AnalysisResultTest extends BaseTestCase {

    /**
     * Test construction with array.
     */
    public function test_constructor_with_array(): void {
        $data = $this->get_analysis_result_fixture();

        $result = new \PRAutoBlogger_Analysis_Result( $data );

        $this->assertSame( 1, $result->get_id() );
        $this->assertSame( 'trending_topic', $result->get_analysis_type() );
        $this->assertSame( 'Test Topic', $result->get_topic() );
        $this->assertSame( 'This is a summary.', $result->get_summary() );
        $this->assertSame( 10, $result->get_frequency() );
        $this->assertSame( 0.85, $result->get_relevance_score() );
        $this->assertSame( [ 1, 2, 3 ], $result->get_source_ids() );
        $this->assertSame( '2026-04-12 10:00:00', $result->get_analyzed_at() );
        $this->assertSame( [ 'key' => 'value' ], $result->get_metadata() );
    }

    /**
     * Test getters return correct types.
     */
    public function test_getters_return_correct_types(): void {
        $result = new \PRAutoBlogger_Analysis_Result(
            $this->get_analysis_result_fixture()
        );

        $this->assertIsInt( $result->get_id() );
        $this->assertIsString( $result->get_analysis_type() );
        $this->assertIsString( $result->get_topic() );
        $this->assertIsString( $result->get_summary() );
        $this->assertIsInt( $result->get_frequency() );
        $this->assertIsFloat( $result->get_relevance_score() );
        $this->assertIsArray( $result->get_source_ids() );
        $this->assertIsString( $result->get_analyzed_at() );
    }

    /**
     * Test nullable summary field.
     */
    public function test_nullable_summary(): void {
        $data = $this->get_analysis_result_fixture();
        $data['summary'] = null;

        $result = new \PRAutoBlogger_Analysis_Result( $data );

        $this->assertNull( $result->get_summary() );
    }

    /**
     * Test nullable metadata field.
     */
    public function test_nullable_metadata(): void {
        $data = $this->get_analysis_result_fixture();
        $data['metadata'] = null;

        $result = new \PRAutoBlogger_Analysis_Result( $data );

        $this->assertNull( $result->get_metadata() );
    }

    /**
     * Test to_db_row returns array.
     */
    public function test_to_db_row_returns_array(): void {
        $result = new \PRAutoBlogger_Analysis_Result(
            $this->get_analysis_result_fixture()
        );

        $row = $result->to_db_row();

        $this->assertIsArray( $row );
        $this->assertArrayHasKey( 'analysis_type', $row );
        $this->assertArrayHasKey( 'topic', $row );
        $this->assertArrayHasKey( 'frequency', $row );
    }

    /**
     * Test with minimal data.
     */
    public function test_with_minimal_data(): void {
        $data = [
            'id'              => 1,
            'analysis_type'   => 'topic',
            'topic'           => 'Test',
            'summary'         => null,
            'frequency'       => 0,
            'relevance_score' => 0.0,
            'source_ids'      => [],
            'analyzed_at'     => '2026-04-12 10:00:00',
            'metadata'        => null,
        ];

        $result = new \PRAutoBlogger_Analysis_Result( $data );

        $this->assertSame( 1, $result->get_id() );
        $this->assertSame( 0, $result->get_frequency() );
        $this->assertEmpty( $result->get_source_ids() );
    }

    /**
     * Test source_ids is always an array.
     */
    public function test_source_ids_always_array(): void {
        $data = $this->get_analysis_result_fixture();
        $data['source_ids'] = [];

        $result = new \PRAutoBlogger_Analysis_Result( $data );

        $this->assertIsArray( $result->get_source_ids() );
        $this->assertEmpty( $result->get_source_ids() );
    }
}
