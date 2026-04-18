<?php
/**
 * Tests for PRAutoBlogger_Image_Pipeline.
 *
 * Validates image generation orchestration, including A/B generation,
 * cost tracking, and graceful failure handling.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ImagePipelineTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Mock WordPress functions used by the pipeline and its dependencies.
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid' );
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			static $options = [
				'prautoblogger_image_enabled'      => '1',
				'prautoblogger_image_style_suffix' => 'Style: test style',
			];
			return $options[ $key ] ?? $default;
		} );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( function ( $str ) {
			return trim( strip_tags( $str ) );
		} );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'set_post_thumbnail' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post' )->justReturn( (object) [ 'ID' => 1, 'post_content' => '<p>Test content</p>' ] );
		Functions\when( 'wp_update_post' )->justReturn( 1 );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/img.png' );
		Functions\when( 'sanitize_title' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	/**
	 * Test generate_and_attach_images() returns early if disabled.
	 */
	public function test_generate_and_attach_images_skipped_if_disabled(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			static $options = [ 'prautoblogger_image_enabled' => '0' ];
			return $options[ $key ] ?? $default;
		} );

		$provider = $this->createMock( \PRAutoBlogger_Image_Provider_Interface::class );
		$provider->expects( $this->never() )->method( 'generate_image' );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );

		$pipeline = new \PRAutoBlogger_Image_Pipeline( $provider, $cost_tracker );
		$result   = $pipeline->generate_and_attach_images( 1, [ 'post_title' => 'Test' ] );

		$this->assertArrayHasKey( 'cost_usd', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test generate_and_attach_images() returns cost and error array structure.
	 *
	 * Pipeline now calls generate_image_batch() with keyed requests.
	 */
	public function test_generate_and_attach_images_returns_correct_structure(): void {
		$image_result = [
			'bytes'      => 'fake_image_data',
			'mime_type'  => 'image/png',
			'width'      => 1200,
			'height'     => 630,
			'model'      => 'test-model',
			'seed'       => 123,
			'cost_usd'   => 0.05,
			'latency_ms' => 2000,
		];

		$provider = $this->createMock( \PRAutoBlogger_Image_Provider_Interface::class );
		$provider->method( 'estimate_cost' )->willReturn( 0.05 );
		$provider->method( 'generate_image_batch' )->willReturn( [
			'image_a' => $image_result,
		] );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->method( 'would_exceed_budget' )->willReturn( false );

		$pipeline = new \PRAutoBlogger_Image_Pipeline( $provider, $cost_tracker );

		Functions\when( 'get_temp_dir' )->justReturn( '/tmp/' );
		Functions\when( 'media_handle_sideload' )->justReturn( 42 );

		$result = $pipeline->generate_and_attach_images( 1, [ 'post_title' => 'Test' ] );

		$this->assertArrayHasKey( 'cost_usd', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertIsFloat( $result['cost_usd'] );
		$this->assertIsArray( $result['errors'] );
	}

	/**
	 * Test that cost is accumulated when both images are generated via batch.
	 */
	public function test_cost_is_accumulated_for_both_images(): void {
		$image_result = [
			'bytes'      => 'fake_image_data',
			'mime_type'  => 'image/png',
			'width'      => 1200,
			'height'     => 630,
			'model'      => 'test-model',
			'cost_usd'   => 0.05,
			'latency_ms' => 2000,
		];

		$provider = $this->createMock( \PRAutoBlogger_Image_Provider_Interface::class );
		$provider->method( 'estimate_cost' )->willReturn( 0.05 );
		$provider->method( 'generate_image_batch' )->willReturn( [
			'image_a' => $image_result,
			'image_b' => $image_result,
		] );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->method( 'would_exceed_budget' )->willReturn( false );
		$cost_tracker->expects( $this->exactly( 2 ) )->method( 'log_image_generation' );

		$pipeline = new \PRAutoBlogger_Image_Pipeline( $provider, $cost_tracker );

		Functions\when( 'get_temp_dir' )->justReturn( '/tmp/' );
		Functions\when( 'media_handle_sideload' )->justReturn( 42 );

		$result = $pipeline->generate_and_attach_images(
			1,
			[ 'post_title' => 'Test' ],
			[ 'title' => 'Source' ]
		);

		// Cost should be accumulated from both images ($0.05 × 2 = $0.10).
		$this->assertEqualsWithDelta( 0.10, $result['cost_usd'], 0.001 );
	}

	/**
	 * Test that Image B is NOT included in the batch when source_data is null.
	 *
	 * Regression test: the pipeline must only send 'image_a' in the batch
	 * request when no source data is provided.
	 */
	public function test_image_b_skipped_when_source_data_is_null(): void {
		$image_result = [
			'bytes'      => 'fake_image_data',
			'mime_type'  => 'image/png',
			'width'      => 1200,
			'height'     => 630,
			'model'      => 'test-model',
			'cost_usd'   => 0.05,
			'latency_ms' => 2000,
		];

		$provider = $this->createMock( \PRAutoBlogger_Image_Provider_Interface::class );
		$provider->method( 'estimate_cost' )->willReturn( 0.05 );
		// Verify batch only contains 'image_a'.
		$provider->expects( $this->once() )
			->method( 'generate_image_batch' )
			->with( $this->callback( function ( $requests ) {
				return array_key_exists( 'image_a', $requests )
					&& ! array_key_exists( 'image_b', $requests );
			} ) )
			->willReturn( [ 'image_a' => $image_result ] );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->method( 'would_exceed_budget' )->willReturn( false );
		$cost_tracker->expects( $this->exactly( 1 ) )->method( 'log_image_generation' );

		$pipeline = new \PRAutoBlogger_Image_Pipeline( $provider, $cost_tracker );

		Functions\when( 'get_temp_dir' )->justReturn( '/tmp/' );
		Functions\when( 'media_handle_sideload' )->justReturn( 42 );

		$result = $pipeline->generate_and_attach_images( 1, [ 'post_title' => 'Test' ], null );

		$this->assertArrayHasKey( 'image_a_id', $result );
		$this->assertArrayNotHasKey( 'image_b_id', $result );
	}

	/**
	 * Test graceful handling when batch generation throws.
	 */
	public function test_graceful_failure_when_batch_generation_throws(): void {
		$provider = $this->createMock( \PRAutoBlogger_Image_Provider_Interface::class );
		$provider->method( 'estimate_cost' )->willReturn( 0.05 );
		$provider->method( 'generate_image_batch' )->willThrowException( new \Exception( 'API timeout' ) );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->method( 'would_exceed_budget' )->willReturn( false );

		$pipeline = new \PRAutoBlogger_Image_Pipeline( $provider, $cost_tracker );

		$result = $pipeline->generate_and_attach_images( 1, [ 'post_title' => 'Test' ] );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'API timeout', implode( ' ', $result['errors'] ) );
	}

	/**
	 * Test graceful handling when one image in the batch returns an error.
	 */
	public function test_partial_batch_failure_handled_gracefully(): void {
		$provider = $this->createMock( \PRAutoBlogger_Image_Provider_Interface::class );
		$provider->method( 'estimate_cost' )->willReturn( 0.05 );
		$provider->method( 'generate_image_batch' )->willReturn( [
			'image_a' => [
				'bytes'      => 'fake_image_data',
				'mime_type'  => 'image/png',
				'width'      => 1200,
				'height'     => 630,
				'model'      => 'test-model',
				'cost_usd'   => 0.05,
				'latency_ms' => 2000,
			],
			'image_b' => [ 'error' => 'HTTP 429: rate limited' ],
		] );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->method( 'would_exceed_budget' )->willReturn( false );
		// Only Image A gets logged — Image B failed.
		$cost_tracker->expects( $this->exactly( 1 ) )->method( 'log_image_generation' );

		$pipeline = new \PRAutoBlogger_Image_Pipeline( $provider, $cost_tracker );

		Functions\when( 'get_temp_dir' )->justReturn( '/tmp/' );
		Functions\when( 'media_handle_sideload' )->justReturn( 42 );

		$result = $pipeline->generate_and_attach_images(
			1,
			[ 'post_title' => 'Test' ],
			[ 'title' => 'Source' ]
		);

		// Image A succeeds, Image B records an error.
		$this->assertArrayHasKey( 'image_a_id', $result );
		$this->assertArrayNotHasKey( 'image_b_id', $result );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( '429', implode( ' ', $result['errors'] ) );
	}
}
