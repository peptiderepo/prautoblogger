<?php
/**
 * Tests for PRAutoBlogger_Runware_Image_Provider.
 *
 * Validates interface compliance, two-step POST-then-GET flow, retry
 * behavior on 5xx, immediate-fail on 4xx, and dimension/prompt guards.
 * All HTTP calls are mocked — no real API traffic.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class RunwareImageProviderTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			static $options = [
				'prautoblogger_runware_api_key' => 'enc:fake-key',
				'prautoblogger_image_model'     => 'runware:100@1',
			];
			return $options[ $key ] ?? $default;
		} );

		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );

		Functions\when( 'wp_generate_uuid4' )->justReturn( '11111111-2222-3333-4444-555555555555' );

		if ( ! class_exists( 'PRAutoBlogger_Encryption' ) ) {
			eval( '
				class PRAutoBlogger_Encryption {
					public static function is_encrypted( $v ) { return 0 === strpos( $v, "enc:" ); }
					public static function decrypt( $v ) { return "rw-test-key-123"; }
					public static function encrypt( $v ) { return "enc:" . $v; }
				}
			' );
		}
	}

	/** Provider name is 'runware'. */
	public function test_get_provider_name(): void {
		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$this->assertSame( 'runware', $provider->get_provider_name() );
	}

	/** estimate_cost returns a positive float for schnell. */
	public function test_estimate_cost_schnell(): void {
		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$cost     = $provider->estimate_cost( 1216, 640, [ 'model' => 'runware:100@1' ] );
		$this->assertIsFloat( $cost );
		$this->assertEqualsWithDelta( 0.0006, $cost, 0.000001 );
	}

	/** estimate_cost returns higher value for dev. */
	public function test_estimate_cost_dev_higher_than_schnell(): void {
		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$schnell  = $provider->estimate_cost( 1216, 640, [ 'model' => 'runware:100@1' ] );
		$dev      = $provider->estimate_cost( 1216, 640, [ 'model' => 'runware:101@1' ] );
		$this->assertGreaterThan( $schnell, $dev );
	}

	/** Empty prompt throws InvalidArgumentException. */
	public function test_generate_image_empty_prompt_throws(): void {
		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$this->expectException( \InvalidArgumentException::class );
		$provider->generate_image( '', 1200, 640 );
	}

	/** Whitespace-only prompt throws InvalidArgumentException. */
	public function test_generate_image_whitespace_prompt_throws(): void {
		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$this->expectException( \InvalidArgumentException::class );
		$provider->generate_image( "   \t\n  ", 1200, 640 );
	}

	/** Dimension below minimum throws. */
	public function test_generate_image_tiny_dimensions_throws(): void {
		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$this->expectException( \InvalidArgumentException::class );
		$provider->generate_image( 'A test prompt', 10, 10 );
	}

	/** Dimension above maximum throws. */
	public function test_generate_image_huge_dimensions_throws(): void {
		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$this->expectException( \InvalidArgumentException::class );
		$provider->generate_image( 'A test prompt', 5000, 5000 );
	}

	/** Happy path: POST returns URL, GET returns bytes, result shape correct. */
	public function test_generate_image_success(): void {
		$image_url  = 'https://im.runware.ai/image/abc.png';
		$post_body  = wp_json_encode( [
			'data' => [
				[ 'taskType' => 'imageInference', 'imageURL' => $image_url ],
			],
		] );
		$fake_bytes = 'fake-png-bytes';

		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'headers'  => [],
			'body'     => $post_body,
		] );
		Functions\when( 'wp_remote_get' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => $fake_bytes,
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) {
			return isset( $r['response']['code'] ) ? (int) $r['response']['code'] : 0;
		} );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) {
			return isset( $r['body'] ) ? (string) $r['body'] : '';
		} );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$result   = $provider->generate_image( 'A gym bro holding a vial', 1200, 632 );

		$this->assertArrayHasKey( 'bytes', $result );
		$this->assertArrayHasKey( 'mime_type', $result );
		$this->assertArrayHasKey( 'width', $result );
		$this->assertArrayHasKey( 'height', $result );
		$this->assertArrayHasKey( 'model', $result );
		$this->assertArrayHasKey( 'seed', $result );
		$this->assertArrayHasKey( 'cost_usd', $result );
		$this->assertArrayHasKey( 'latency_ms', $result );

		$this->assertSame( $fake_bytes, $result['bytes'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		// Snapped to multiples of 64: 1200 → 1216, 632 → 640.
		$this->assertSame( 1216, $result['width'] );
		$this->assertSame( 640, $result['height'] );
		$this->assertSame( 'runware:100@1', $result['model'] );
		$this->assertGreaterThan( 0, $result['seed'] );
	}

	/** 4xx (non-429) fails immediately with RuntimeException. */
	public function test_generate_image_4xx_throws_immediately(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 401 ],
			'headers'  => [],
			'body'     => '{"errors":[{"message":"invalid key"}]}',
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"errors":[{"message":"invalid key"}]}' );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'HTTP 401' );
		$provider->generate_image( 'A test prompt', 1200, 632 );
	}

	/** 5xx triggers retry loop and eventually throws after PRAUTOBLOGGER_MAX_RETRIES. */
	public function test_generate_image_5xx_retries_then_throws(): void {
		$call_count = 0;
		Functions\when( 'wp_remote_post' )->alias( function () use ( &$call_count ) {
			$call_count++;
			return [
				'response' => [ 'code' => 503 ],
				'headers'  => [],
				'body'     => '{"errors":[{"message":"temporarily unavailable"}]}',
			];
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"errors":[{"message":"temporarily unavailable"}]}' );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		try {
			$provider->generate_image( 'A test prompt', 1200, 632 );
			$this->fail( 'Expected RuntimeException after max retries' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame(
				PRAUTOBLOGGER_MAX_RETRIES,
				$call_count,
				'Provider must attempt exactly PRAUTOBLOGGER_MAX_RETRIES times before giving up.'
			);
		}
	}

	/** errors[] in a 200 body is treated as an upstream error. */
	public function test_generate_image_errors_in_200_body_throws(): void {
		$body = wp_json_encode( [
			'errors' => [ [ 'message' => 'moderation rejected' ] ],
		] );
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'headers'  => [],
			'body'     => $body,
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$this->expectException( \RuntimeException::class );
		$provider->generate_image( 'A test prompt', 1200, 632 );
	}

	/** validate_credentials_detailed returns ok for a 200 response with no errors. */
	public function test_validate_credentials_detailed_ok(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => '{"data":[{"taskType":"authentication"}]}',
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":[{"taskType":"authentication"}]}' );

		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$result   = $provider->validate_credentials_detailed();
		$this->assertSame( 'ok', $result['status'] );
	}

	/** validate_credentials_detailed returns error when key missing. */
	public function test_validate_credentials_detailed_missing_key(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			return $default;
		} );

		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$result   = $provider->validate_credentials_detailed();
		$this->assertSame( 'error', $result['status'] );
	}

	/** validate_credentials_detailed returns error when response contains errors[]. */
	public function test_validate_credentials_detailed_rejected_key(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => '{"errors":[{"message":"invalid key"}]}',
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"errors":[{"message":"invalid key"}]}' );

		$provider = new \PRAutoBlogger_Runware_Image_Provider();
		$result   = $provider->validate_credentials_detailed();
		$this->assertSame( 'error', $result['status'] );
	}
}
