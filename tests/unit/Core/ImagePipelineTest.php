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
		Functions\when( 'sanitize_title' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
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
	 */
	public function test_generate_and_attach_images_returns_correct_structure(): void {
		$provider = $this->createMock( \PRAutoBlogger_Image_Provider_Interface::class );
		$provider->method( 'estimate_cost' )->willReturn( 0.05 );
		$provider->method( 'generate_image' )->willReturn( [
			'bytes'      => 'fake_image_data',
			'mime_type'  => 'image/png',
			'width'      => 1200,
			'height'     => 630,
			'model'      => 'flux-1-schnell',
			'seed'       => 123,
			'cost_usd'   => 0.05,
			'latency_ms' => 2000,
		] );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->method( 'would_exceed_budget' )->willReturn( false );

		$pipeline = new \PRAutoBlogger_Image_Pipeline( $provider, $cost_tracker );

		// Mock file writing for sideload.
		Functions\when( 'get_temp_dir' )->justReturn( '/tmp/' );
		Functions\when( 'media_handle_sideload' )->justReturn( 42 );

		$result = $pipeline->generate_and_attach_images( 1, [ 'post_title' => 'Test' ] );

		$this->assertArrayHasKey( 'cost_usd', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertIsFloat( $result['cost_usd'] );
		$this->assertIsArray( $result['errors'] );
	}

	/**
	 * Test that cost is accumulated when both images are generated.
	 */
	public function test_cost_is_accumulated_for_both_images(): void {
		$provider = $this->createMock( \PRAutoBlogger_Image_Provider_Interface::class );
		$provider->method( 'estimate_cost' )->willReturn( 0.05 );
		$provider->method( 'generate_image' )->willReturn( [
			'bytes'      => 'fake_image_data',
			'mime_type'  => 'image/png',
			'width'      => 1200,
			'height'     => 630,
			'model'      => 'flux-1-schnell',
			'cost_usd'   => 0.05,
			'latency_ms' => 2000,
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

		// Cost should be accumulated from both images.
		$this->assertGreaterThan( 0.05, $result['cost_usd'] );
	}

	/**
	 * Test that Image B is NOT generated when source_data is null.
	 *
	 * This is the regression test for the Image B data-handoff gap:
	 * post_assembler.php and executor.php both passed null as source_data,
	 * so the pipeline silently skipped Image B. If this test ever fails
	 * with `$this->exactly(2)`, it means someone re-broke the handoff.
	 */
	public function test_image_b_skipped_when_source_data_is_null(): void {
		$provider = $this->createMock( \PRAutoBlogger_Image_Provider_Interface::class );
		$provider->method( 'estimate_cost' )->willReturn( 0.05 );
		$provider->method( 'generate_image' )->willReturn( [
			'bytes'      => 'fake_image_data',
			'mime_type'  => 'image/png',
			'width'      => 1200,
			'height'     => 630,
			'model'      => 'flux-1-schnell',
			'cost_usd'   => 0.05,
			'latency_ms' => 2000,
		] );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->method( 'would_exceed_budget' )->willReturn( false );

		// Only ONE call to log_image_generation — Image A only.
		$cost_tracker->expects( $this->exactly( 1 ) )->method( 'log_image_generation' );

		$pipeline = new \PRAutoBlogger_Image_Pipeline( $provider, $cost_tracker );

		Functions\when( 'get_temp_dir' )->justReturn( '/tmp/' );
		Functions\when( 'media_handle_sideload' )->justReturn( 42 );

		// Pass null source_data — Image B must NOT fire.
		$result = $pipeline->generate_and_attach_images( 1, [ 'post_title' => 'Test' ], null );

		$this->assertArrayHasKey( 'image_a_id', $result );
		$this->assertArrayNotHasKey( 'image_b_id', $result );
	}

	/**
	 * Test graceful handling when image generation fails.
	 */
	public function test_graceful_failure_when_image_generation_fails(): void {
		$provider = $this->createMock( \PRAutoBlogger_Image_Provider_Interface::class );
		$provider->method( 'estimate_cost' )->willReturn( 0.05 );
		$provider->method( 'generate_image' )->willThrowException( new \Exception( 'API timeout' ) );

		$cost_tracker = $this->createMock( \PRAutoBlogger_Cost_Tracker::class );
		$cost_tracker->method( 'would_exceed_budget' )->willReturn( false );

		$pipeline = new \PRAutoBlogger_Image_Pipeline( $provider, $cost_tracker );

		$result = $pipeline->generate_and_attach_images( 1, [ 'post_title' => 'Test' ] );

		// Should record error but return valid structure.
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'API timeout', implode( ' ', $result['errors'] ) );
	}
}
