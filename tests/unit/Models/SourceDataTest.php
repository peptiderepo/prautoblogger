<?php
/**
 * Tests for PRAutoBlogger_Source_Data value object.
 *
 * @package PRAutoBlogger\Tests\Models
 */

namespace PRAutoBlogger\Tests\Models;

use PRAutoBlogger\Tests\BaseTestCase;

class SourceDataTest extends BaseTestCase {

    /**
     * Test construction with minimal required fields.
     */
    public function test_constructor_with_array(): void {
        $this->stub_current_time( '2026-04-12 10:00:00' );

        $data = [
            'id'              => 1,
            'source_type'     => 'reddit',
            'source_id'       => 'post_123',
            'subreddit'       => 'test_subreddit',
            'title'           => 'Test Post Title',
            'content'         => 'This is the post content.',
            'author'          => 'test_author',
            'score'           => 100,
            'comment_count'   => 50,
            'permalink'       => '/r/test/comments/123',
            'collected_at'    => '2026-04-12 10:00:00',
            'metadata'        => [ 'key' => 'value' ],
        ];

        $source = new \PRAutoBlogger_Source_Data( $data );

        $this->assertSame( 1, $source->get_id() );
        $this->assertSame( 'reddit', $source->get_source_type() );
        $this->assertSame( 'post_123', $source->get_source_id() );
        $this->assertSame( 'test_subreddit', $source->get_subreddit() );
        $this->assertSame( 'Test Post Title', $source->get_title() );
        $this->assertSame( 'This is the post content.', $source->get_content() );
        $this->assertSame( 'test_author', $source->get_author() );
        $this->assertSame( 100, $source->get_score() );
        $this->assertSame( 50, $source->get_comment_count() );
        $this->assertSame( '/r/test/comments/123', $source->get_permalink() );
        $this->assertSame( '2026-04-12 10:00:00', $source->get_collected_at() );
        $this->assertSame( [ 'key' => 'value' ], $source->get_metadata() );
    }

    /**
     * Test getters return correct types.
     */
    public function test_getters_return_correct_types(): void {
        $this->stub_current_time( '2026-04-12 10:00:00' );

        $source = new \PRAutoBlogger_Source_Data(
            $this->get_source_data_fixture()
        );

        $this->assertIsInt( $source->get_id() );
        $this->assertIsString( $source->get_source_type() );
        $this->assertIsString( $source->get_source_id() );
        $this->assertIsString( $source->get_title() );
        $this->assertIsString( $source->get_content() );
        $this->assertIsInt( $source->get_score() );
        $this->assertIsInt( $source->get_comment_count() );
        $this->assertIsString( $source->get_collected_at() );
    }

    /**
     * Test nullable fields return null when absent.
     */
    public function test_nullable_fields_return_null(): void {
        $this->stub_current_time( '2026-04-12 10:00:00' );

        $data = [
            'id'              => 1,
            'source_type'     => 'reddit',
            'source_id'       => 'post_123',
            'subreddit'       => null,
            'title'           => null,
            'content'         => null,
            'author'          => null,
            'score'           => 0,
            'comment_count'   => 0,
            'permalink'       => null,
            'collected_at'    => '2026-04-12 10:00:00',
            'metadata'        => null,
        ];

        $source = new \PRAutoBlogger_Source_Data( $data );

        $this->assertNull( $source->get_subreddit() );
        $this->assertNull( $source->get_title() );
        $this->assertNull( $source->get_content() );
        $this->assertNull( $source->get_author() );
        $this->assertNull( $source->get_permalink() );
        $this->assertNull( $source->get_metadata() );
    }

    /**
     * Test to_db_row returns array representation.
     */
    public function test_to_db_row_returns_array(): void {
        $this->stub_current_time( '2026-04-12 10:00:00' );

        $source = new \PRAutoBlogger_Source_Data(
            $this->get_source_data_fixture()
        );

        $row = $source->to_db_row();

        $this->assertIsArray( $row );
        $this->assertArrayHasKey( 'source_type', $row );
        $this->assertArrayHasKey( 'source_id', $row );
        $this->assertArrayHasKey( 'title', $row );
        $this->assertArrayHasKey( 'content', $row );
    }

    /**
     * Test with empty/zero values.
     */
    public function test_with_empty_values(): void {
        $this->stub_current_time( '2026-04-12 10:00:00' );

        $data = [
            'id'              => 0,
            'source_type'     => '',
            'source_id'       => '',
            'subreddit'       => '',
            'title'           => '',
            'content'         => '',
            'author'          => '',
            'score'           => 0,
            'comment_count'   => 0,
            'permalink'       => '',
            'collected_at'    => '2026-04-12 10:00:00',
            'metadata'        => [],
        ];

        $source = new \PRAutoBlogger_Source_Data( $data );

        $this->assertSame( 0, $source->get_id() );
        $this->assertSame( 0, $source->get_score() );
        $this->assertSame( 0, $source->get_comment_count() );
        $this->assertIsArray( $source->get_metadata() );
    }
}
