<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Image_Provider.
 *
 * Validates provider interface compliance, response parsing, and error handling.
 * All HTTP calls are mocked — no real API calls.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterImageProviderTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Provide a valid encrypted API key.
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			static $options = [
				'prautoblogger_openrouter_api_key' => 'enc:fake-encrypted-key',
				'prautoblogger_image_model'        => 'black-forest-labs/flux-2-pro',
				'prautoblogger_ai_gateway_base_url' => '',
			];
			return $options[ $key ] ?? $default;
		} );

		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		// Mock encryption: decrypt returns a valid sk-or- key.
		if ( ! class_exists( 'PRAutoBlogger_Encryption' ) ) {
			eval( '
				class PRAutoBlogger_Encryption {
					public static function is_encrypted( $v ) { return 0 === strpos( $v, "enc:" ); }
					public static function decrypt( $v ) { return "sk-or-test-key-123"; }
					public static function encrypt( $v ) { return "enc:" . $v; }
				}
			' );
		}
	}

	/** Provider name matches expected identifier. */
	public function test_get_provider_name(): void {
		$provider = new \PRAutoBlogger_OpenRouter_Image_Provider();
		$this->assertSame( 'openrouter', $provider->get_provider_name() );
	}

	/** estimate_cost returns a positive float. */
	public function test_estimate_cost_returns_float(): void {
		$provider = new \PRAutoBlogger_OpenRouter_Image_Provider();
		$cost     = $provider->estimate_cost( 1200, 632 );
		$this->assertIsFloat( $cost );
		$this->assertGreaterThan( 0, $cost );
	}

	/** Empty prompt throws InvalidArgumentException. */
	public function test_generate_image_empty_prompt_throws(): void {
		$provider = new \PRAutoBlogger_OpenRouter_Image_Provider();
		$this->expectException( \InvalidArgumentException::class );
		$provider->generate_image( '', 1200, 632 );
	}

	/** Dimension below minimum throws InvalidArgumentException. */
	public function test_generate_image_tiny_dimensions_throws(): void {
		$provider = new \PRAutoBlogger_OpenRouter_Image_Provider();
		$this->expectException( \InvalidArgumentException::class );
		$provider->generate_image( 'A test prompt', 10, 10 );
	}

	/** Dimension above maximum throws InvalidArgumentException. */
	public function test_generate_image_huge_dimensions_throws(): void {
		$provider = new \PRAutoBlogger_OpenRouter_Image_Provider();
		$this->expectException( \InvalidArgumentException::class );
		$provider->generate_image( 'A test prompt', 5000, 5000 );
	}

	/** Successful generation returns correct array structure. */
	public function test_generate_image_success(): void {
		// Build a fake OpenRouter response with base64 image data.
		$fake_bytes = 'fake-image-data';
		$b64        = base64_encode( $fake_bytes );
		$data_url   = 'data:image/png;base64,' . $b64;
		$body       = wp_json_encode( [
			'choices' => [ [
				'message' => [
					'role'    => 'assistant',
					'content' => '',
					'images'  => [ [
						'type'      => 'image_url',
						'image_url' => [ 'url' => $data_url ],
					] ],
				],
			] ],
		] );

		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'headers'  => [],
			'body'     => $body,
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) {
			return parse_url( $url, $component );
		} );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://peptiderepo.com' );

		$provider = new \PRAutoBlogger_OpenRouter_Image_Provider();
		$result   = $provider->generate_image( 'A peptide research infographic', 1200, 632 );

		$this->assertArrayHasKey( 'bytes', $result );
		$this->assertArrayHasKey( 'mime_type', $result );
		$this->assertArrayHasKey( 'model', $result );
		$this->assertArrayHasKey( 'cost_usd', $result );
		$this->assertArrayHasKey( 'latency_ms', $result );
		$this->assertSame( $fake_bytes, $result['bytes'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 1200, $result['width'] );
		$this->assertSame( 632, $result['height'] );
	}

	/** 4xx error throws RuntimeException. */
	public function test_generate_image_4xx_throws(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 401 ],
			'headers'  => [],
			'body'     => '{"error":"unauthorized"}',
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"unauthorized"}' );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) {
			return parse_url( $url, $component );
		} );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://peptiderepo.com' );

		$provider = new \PRAutoBlogger_OpenRouter_Image_Provider();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'HTTP 401' );
		$provider->generate_image( 'A test prompt', 1200, 632 );
	}
}
