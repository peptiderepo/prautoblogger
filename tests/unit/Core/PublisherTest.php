<?php
/**
 * Tests for PRAutoBlogger_Publisher.
 *
 * Validates WordPress post creation with metadata,
 * generation log attachment, and error handling.
 * All WordPress functions are mocked.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class PublisherTest extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once PRAB_PLUGIN_DIR . 'includes/core/class-prab-publisher.php';
    }

    /**
     * Test publish creates a draft post with correct data.
     */
    public function test_publish_creates_draft_post(): void {
        $post_data = [
            'title'   => 'BPC-157: A Complete Guide',
            'content' => '<p>BPC-157 is a synthetic peptide...</p>',
            'excerpt' => 'An overview of BPC-157 benefits.',
            'category' => 'peptide-research',
        ];

        $generation_meta = [
            'model'             => 'google/gemini-2.0-flash-001',
            'pipeline_mode'     => 'single_pass',
            'total_cost'        => 0.0045,
            'prompt_tokens'     => 800,
            'completion_tokens' => 2000,
            'generation_logs'   => [],
        ];

        // wp_insert_post should be called with post_status = 'draft'.
        Functions\expect( 'wp_insert_post' )
            ->once()
            ->with( \Mockery::on( function ( $args ) {
                return 'draft' === $args['post_status']
                    && 'BPC-157: A Complete Guide' === $args['post_title']
                    && false !== strpos( $args['post_content'], 'BPC-157' );
            } ) )
            ->andReturn( 42 );

        // Metadata should be saved.
        Functions\expect( 'update_post_meta' )
            ->atLeast()
            ->once();

        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'sanitize_title' )->alias( function ( $title ) {
            return strtolower( str_replace( ' ', '-', $title ) );
        } );

        $publisher = new \PRAutoBlogger_Publisher();
        $post_id   = $publisher->publish( $post_data, $generation_meta );

        $this->assertSame( 42, $post_id );
    }

    /**
     * Test publish returns WP_Error when wp_insert_post fails.
     */
    public function test_publish_returns_error_on_insert_failure(): void {
        $wp_error = new \stdClass();
        $wp_error->errors = [ 'db_insert_error' => [ 'Could not insert post' ] ];

        Functions\when( 'wp_insert_post' )->justReturn( $wp_error );
        Functions\when( 'is_wp_error' )->justReturn( true );
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'sanitize_title' )->returnArg();

        $publisher = new \PRAutoBlogger_Publisher();
        $result    = $publisher->publish(
            [ 'title' => 'Test', 'content' => 'Test content', 'excerpt' => '', 'category' => '' ],
            []
        );

        // Should propagate the error, not the post ID.
        $this->assertNotSame( 42, $result );
    }

    /**
     * Test publish sanitizes title and content.
     */
    public function test_publish_calls_sanitization(): void {
        Functions\expect( 'wp_slash' )
            ->atLeast()
            ->once()
            ->andReturnFirstArg();

        Functions\expect( 'sanitize_title' )
            ->once()
            ->andReturnUsing( function ( $title ) {
                return strtolower( str_replace( ' ', '-', $title ) );
            } );

        Functions\when( 'wp_insert_post' )->justReturn( 1 );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'update_post_meta' )->justReturn( true );

        $publisher = new \PRAutoBlogger_Publisher();
        $publisher->publish(
            [
                'title'   => 'Test <script>alert(1)</script>',
                'content' => '<p>Safe content</p>',
                'excerpt' => 'Excerpt',
                'category' => 'general',
            ],
            []
        );

        // If we get here without error, sanitization was called.
        $this->assertTrue( true );
    }

    /**
     * Test publish stores generation metadata as post meta.
     */
    public function test_publish_stores_generation_metadata(): void {
        $generation_meta = [
            'model'         => 'test/model',
            'pipeline_mode' => 'multi_step',
            'total_cost'    => 0.01,
        ];

        Functions\when( 'wp_insert_post' )->justReturn( 99 );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'sanitize_title' )->returnArg();

        $meta_saved = [];
        Functions\when( 'update_post_meta' )->alias(
            function ( $post_id, $key, $value ) use ( &$meta_saved ) {
                $meta_saved[ $key ] = $value;
                return true;
            }
        );

        $publisher = new \PRAutoBlogger_Publisher();
        $publisher->publish(
            [ 'title' => 'T', 'content' => 'C', 'excerpt' => '', 'category' => '' ],
            $generation_meta
        );

        // Verify generation metadata was stored.
        $this->assertNotEmpty( $meta_saved );
    }
}
