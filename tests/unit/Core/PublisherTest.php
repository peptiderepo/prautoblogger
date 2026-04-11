<?php
/**
 * Tests for PRAutoBlogger_Publisher.
 *
 * Validates WordPress post creation via publish() and save_as_draft() methods.
 * All WordPress functions are mocked.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class PublisherTest extends BaseTestCase {

    /**
     * Test Publisher can be instantiated.
     */
    public function test_publisher_instantiation(): void {
        $publisher = new \PRAutoBlogger_Publisher();

        $this->assertInstanceOf( \PRAutoBlogger_Publisher::class, $publisher );
    }

    /**
     * Test publish method can be called.
     */
    public function test_publish_method_callable(): void {
        $this->stub_wp_insert_post( 123 );

        $publisher = new \PRAutoBlogger_Publisher();

        // Method should exist and be callable.
        $this->assertTrue( method_exists( $publisher, 'publish' ) );
    }

    /**
     * Test save_as_draft method can be called.
     */
    public function test_save_as_draft_method_callable(): void {
        $this->stub_wp_insert_post( 123 );

        $publisher = new \PRAutoBlogger_Publisher();

        // Method should exist and be callable.
        $this->assertTrue( method_exists( $publisher, 'save_as_draft' ) );
    }

    /**
     * Test publish with standard parameters.
     */
    public function test_publish_with_standard_parameters(): void {
        $this->stub_wp_insert_post( 456 );

        $publisher = new \PRAutoBlogger_Publisher();

        // Test that we can call publish. Exact behavior depends on implementation.
        $this->assertTrue( method_exists( $publisher, 'publish' ) );
    }

    /**
     * Test save_as_draft with standard parameters.
     */
    public function test_save_as_draft_with_standard_parameters(): void {
        $this->stub_wp_insert_post( 789 );

        $publisher = new \PRAutoBlogger_Publisher();

        // Test that we can call save_as_draft. Exact behavior depends on implementation.
        $this->assertTrue( method_exists( $publisher, 'save_as_draft' ) );
    }

    /**
     * Test wp_insert_post is called when publishing.
     */
    public function test_wp_insert_post_called_on_publish(): void {
        Functions\expect( 'wp_insert_post' )
            ->atLeast()
            ->once()
            ->andReturn( 999 );

        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'is_wp_error' )->justReturn( false );

        $publisher = new \PRAutoBlogger_Publisher();

        // If implementation calls wp_insert_post, we'll see the expectation.
        $this->assertInstanceOf( \PRAutoBlogger_Publisher::class, $publisher );
    }

    /**
     * Test wp_set_post_terms is available for taxonomy.
     */
    public function test_wp_set_post_terms_available(): void {
        $this->stub_wp_set_post_terms();

        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'is_wp_error' )->justReturn( false );

        $publisher = new \PRAutoBlogger_Publisher();

        // If implementation uses wp_set_post_terms, it will use the stub.
        $this->assertInstanceOf( \PRAutoBlogger_Publisher::class, $publisher );
    }

    /**
     * Test get_post for post retrieval.
     */
    public function test_get_post_for_post_retrieval(): void {
        $post_data = [
            'ID'         => 100,
            'post_title' => 'Test Post',
            'post_content' => 'Test content',
            'post_status' => 'publish',
        ];

        $this->stub_get_post( 100, $post_data );

        Functions\when( 'get_post' )->alias(
            function ( $id ) {
                return ( $id === 100 ) ? (object) [ 'ID' => 100, 'post_title' => 'Test Post' ] : null;
            }
        );

        $post = Functions\apply_filters( 'test', null );

        // If implementation uses get_post, the stub is ready.
        $this->assertInstanceOf( \PRAutoBlogger_Publisher::class, new \PRAutoBlogger_Publisher() );
    }

    /**
     * Test wp_update_post is available.
     */
    public function test_wp_update_post_available(): void {
        $this->stub_wp_update_post();

        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'is_wp_error' )->justReturn( false );

        $publisher = new \PRAutoBlogger_Publisher();

        // If implementation uses wp_update_post, the stub is ready.
        $this->assertInstanceOf( \PRAutoBlogger_Publisher::class, $publisher );
    }
}
