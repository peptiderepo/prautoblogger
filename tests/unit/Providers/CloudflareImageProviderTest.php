<?php
/**
 * Tests for PRAutoBlogger_Cloudflare_Image_Provider.
 *
 * Validates the image provider interface implementation methods.
 * All HTTP calls are mocked — no real API calls.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class CloudflareImageProviderTest extends BaseTestCase {

	/**
	 * Fake PNG payload — bytes don't need to be a real PNG, just non-empty.
	 * Using a short string keeps failure messages readable.
	 */
	private const FAKE_IMAGE_BYTES = 'FAKE_PNG_BYTES';

	protected function setUp(): void {
		parent::setUp();

		// PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL constant is defined in
		// prautoblogger.php — define here for test isolation.
		if ( ! defined( 'PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL' ) ) {
			define( 'PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL', 'flux-1-schnell' );
		}

		$this->stub_get_option( [
			'prautoblogger_cloudflare_ai_token'  => '',
			'prautoblogger_cloudflare_account_id' => 'test-account-uuid',
			'prautoblogger_image_model'          => 'flux-1-schnell',
			'prautoblogger_log_level'            => 'info',
		] );

		Functions\when( 'wp_salt' )->justReturn( 'test_salt_key_for_unit_tests' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
	}

	/**
	 * Helper: configure the plaintext API token the provider will eventually
	 * see after decryption. We round-trip through the real Encryption class
	 * (rather than stubbing it) so this test catches any regressions in the
	 * encrypt → option → decrypt flow at the same time.
	 */
	private function with_token( string $plaintext ): void {
		$ciphertext = \PRAutoBlogger_Encryption::encrypt( $plaintext );
		$this->stub_get_option( [
			'prautoblogger_cloudflare_ai_token'   => $ciphertext,
			'prautoblogger_cloudflare_account_id' => 'test-account-uuid',
			'prautoblogger_image_model'           => 'flux-1-schnell',
			'prautoblogger_log_level'             => 'info',
		] );
	}

	/**
	 * Provider implements the interface.
	 */
	public function test_provider_implements_interface(): void {
		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$this->assertInstanceOf( \PRAutoBlogger_Image_Provider_Interface::class, $provider );
	}

	/**
	 * get_provider_name returns the stable machine identifier.
	 */
	public function test_provider_name_is_stable_identifier(): void {
		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$this->assertSame( 'cloudflare_workers_ai', $provider->get_provider_name() );
	}

	/**
	 * Dimension validation rejects inputs outside the [64, 2048] envelope
	 * before any HTTP call is made.
	 */
	public function test_generate_image_rejects_out_of_range_dimensions(): void {
		$this->with_token( 'test-token' );
		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();

		$this->expectException( \InvalidArgumentException::class );
		$provider->generate_image( 'a prompt', 32, 1080 );
	}

	/**
	 * Empty prompts are rejected — an empty string burns budget on
	 * Workers AI for a deterministic "bad request" result.
	 */
	public function test_generate_image_rejects_empty_prompt(): void {
		$this->with_token( 'test-token' );
		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();

		$this->expectException( \InvalidArgumentException::class );
		$provider->generate_image( '   ', 1080, 1080 );
	}

	/**
	 * Happy path — raw image bytes come back with expected metadata shape.
	 */
	public function test_generate_image_returns_raw_bytes_and_metadata(): void {
		$this->with_token( 'test-token' );

		Functions\when( 'wp_remote_post' )->justReturn( [
			'body'     => self::FAKE_IMAGE_BYTES,
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'image/png' ],
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( self::FAKE_IMAGE_BYTES );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $_response, $name ) {
				return 'content-type' === strtolower( (string) $name ) ? 'image/png' : '';
			}
		);

		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$result   = $provider->generate_image( 'a prompt', 1080, 1080, [ 'seed' => 42 ] );

		$this->assertSame( self::FAKE_IMAGE_BYTES, $result['bytes'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 1080, $result['width'] );
		$this->assertSame( 1080, $result['height'] );
		$this->assertSame( 42, $result['seed'] );
		$this->assertSame( '@cf/black-forest-labs/flux-1-schnell', $result['model'] );
		$this->assertGreaterThan( 0.0, $result['cost_usd'] );
		$this->assertGreaterThanOrEqual( 0, $result['latency_ms'] );
	}

	/**
	 * Happy path where Workers AI wraps the image in a JSON envelope with
	 * base64-encoded bytes under `result.image`. The provider must decode
	 * and return the raw bytes so the pipeline never has to branch.
	 */
	public function test_generate_image_decodes_json_envelope(): void {
		$this->with_token( 'test-token' );

		$encoded = base64_encode( self::FAKE_IMAGE_BYTES );
		$body    = wp_json_encode( [ 'result' => [ 'image' => $encoded ], 'success' => true ] );

		Functions\when( 'wp_remote_post' )->justReturn( [
			'body'     => $body,
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'application/json' ],
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $_response, $name ) {
				return 'content-type' === strtolower( (string) $name ) ? 'application/json' : '';
			}
		);

		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$result   = $provider->generate_image( 'a prompt', 600, 600 );

		$this->assertSame( self::FAKE_IMAGE_BYTES, $result['bytes'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
	}

	/**
	 * A 401 must NOT retry — auth errors are deterministic and retrying
	 * burns budget. The provider should throw on the first response.
	 *
	 * We can't assert "exactly one HTTP call" without injecting a call
	 * counter, so we assert that the throw message contains the status.
	 */
	public function test_generate_image_fails_loudly_on_401(): void {
		$this->with_token( 'test-token' );

		Functions\when( 'wp_remote_post' )->justReturn( [
			'body'     => '{"errors":[{"message":"bad token"}]}',
			'response' => [ 'code' => 401 ],
			'headers'  => [],
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"errors":[{"message":"bad token"}]}' );

		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();

		try {
			$provider->generate_image( 'a prompt', 1080, 1080 );
			$this->fail( 'Expected RuntimeException for HTTP 401.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '401', $e->getMessage() );
		}
	}

	/**
	 * Response shape must be one of: image/* bytes OR JSON envelope with
	 * base64 image. Anything else is a contract breach — fail loudly.
	 */
	public function test_generate_image_throws_on_unexpected_response_shape(): void {
		$this->with_token( 'test-token' );

		Functions\when( 'wp_remote_post' )->justReturn( [
			'body'     => '<!doctype html><html><body>oops</body></html>',
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'text/html' ],
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '<!doctype html><html><body>oops</body></html>' );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $_response, $name ) {
				return 'content-type' === strtolower( (string) $name ) ? 'text/html' : '';
			}
		);

		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$this->expectException( \RuntimeException::class );
		$provider->generate_image( 'a prompt', 1080, 1080 );
	}

	/**
	 * Cost estimation is positive for in-range dimensions and scales with
	 * area — a 1080² image costs more than a 600² image on the same model.
	 */
	public function test_estimate_cost_scales_with_area(): void {
		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();

		$small = $provider->estimate_cost( 600, 600, [ 'model' => 'flux-1-schnell' ] );
		$large = $provider->estimate_cost( 1080, 1080, [ 'model' => 'flux-1-schnell' ] );

		$this->assertGreaterThan( 0.0, $small );
		$this->assertGreaterThan( $small, $large );
	}

	/**
	 * [dev] is meaningfully pricier than [schnell] at the same dimensions —
	 * the ~4x multiplier is the reason we default to schnell.
	 */
	public function test_estimate_cost_dev_more_expensive_than_schnell(): void {
		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();

		$schnell = $provider->estimate_cost( 1080, 1080, [ 'model' => 'flux-1-schnell' ] );
		$dev     = $provider->estimate_cost( 1080, 1080, [ 'model' => 'flux-1-dev' ] );

		$this->assertGreaterThan( $schnell, $dev );
	}

	/**
	 * validate_credentials_detailed reports 'token_empty' debug tag when
	 * no token is configured, without making an HTTP call.
	 */
	public function test_validate_credentials_reports_missing_token(): void {
		// Default setUp leaves token empty.
		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$result   = $provider->validate_credentials_detailed();

		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 'token_empty', $result['debug'] );
	}

	/**
	 * validate_credentials_detailed reports 'account_id_empty' when the
	 * account ID option is blank, without touching the network.
	 */
	public function test_validate_credentials_reports_missing_account_id(): void {
		$this->stub_get_option( [
			'prautoblogger_cloudflare_ai_token'   => 'not-empty',
			'prautoblogger_cloudflare_account_id' => '',
			'prautoblogger_log_level'             => 'info',
		] );
		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$result   = $provider->validate_credentials_detailed();

		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 'account_id_empty', $result['debug'] );
	}

	/**
	 * validate_credentials_detailed must never invoke the generation endpoint.
	 * Asserted by tracking every wp_remote_post call: a real-image call is
	 * the only thing that POSTs to `/ai/run/...`. Validator uses GET on
	 * `/ai/models/search`, which is the free read-only path.
	 */
	public function test_validate_credentials_never_calls_generate_endpoint(): void {
		$this->with_token( 'test-token' );

		$post_calls = [];
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$post_calls ) {
				$post_calls[] = $url;
				return [ 'body' => '', 'response' => [ 'code' => 500 ] ];
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn( [
			'body'     => '{"result":[]}',
			'response' => [ 'code' => 200 ],
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"result":[]}' );

		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$provider->validate_credentials_detailed();

		$this->assertSame( [], $post_calls, 'validate_credentials_detailed() must not POST to /ai/run/ endpoints.' );
	}

	/**
	 * Retry path: a 500 followed by a 200 must yield the 200 payload.
	 * Exercises the exponential-backoff loop end-to-end without sleeping
	 * (bootstrap sets PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS to 0).
	 */
	public function test_generate_image_retries_on_5xx_then_succeeds(): void {
		$this->with_token( 'test-token' );

		$codes = [ 500, 200 ];
		Functions\when( 'wp_remote_post' )->alias(
			function () use ( &$codes ) {
				$code = array_shift( $codes ) ?? 200;
				return [
					'body'     => 500 === $code ? 'upstream hiccup' : self::FAKE_IMAGE_BYTES,
					'response' => [ 'code' => $code ],
					'headers'  => [ 'content-type' => 500 === $code ? 'text/plain' : 'image/png' ],
				];
			}
		);

		$response_codes = [ 500, 200 ];
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$response_codes ) {
				return array_shift( $response_codes ) ?? 200;
			}
		);
		$response_bodies = [ 'upstream hiccup', self::FAKE_IMAGE_BYTES ];
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function () use ( &$response_bodies ) {
				return array_shift( $response_bodies ) ?? self::FAKE_IMAGE_BYTES;
			}
		);
		$mime_types = [ 'text/plain', 'image/png' ];
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $_resp, $name ) use ( &$mime_types ) {
				if ( 'content-type' === strtolower( (string) $name ) ) {
					return array_shift( $mime_types ) ?? 'image/png';
				}
				return '';
			}
		);

		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$result   = $provider->generate_image( 'a prompt', 1080, 1080 );

		$this->assertSame( self::FAKE_IMAGE_BYTES, $result['bytes'] );
	}

	/**
	 * Retry exhaustion: persistent 502s must throw after MAX_RETRIES attempts
	 * rather than hanging indefinitely.
	 */
	public function test_generate_image_fails_after_max_retries_on_persistent_5xx(): void {
		$this->with_token( 'test-token' );

		$call_count = 0;
		Functions\when( 'wp_remote_post' )->alias(
			function () use ( &$call_count ) {
				++$call_count;
				return [
					'body'     => 'bad gateway',
					'response' => [ 'code' => 502 ],
					'headers'  => [],
				];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 502 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'bad gateway' );

		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();

		try {
			$provider->generate_image( 'a prompt', 1080, 1080 );
			$this->fail( 'Expected RuntimeException after retry exhaustion.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '502', $e->getMessage() );
		}
		$this->assertSame(
			PRAUTOBLOGGER_MAX_RETRIES,
			$call_count,
			'Provider must attempt exactly PRAUTOBLOGGER_MAX_RETRIES times before giving up.'
		);
	}

	/**
	 * 429 triggers the same retry loop as 5xx (rate-limited, not deterministic
	 * error). The provider must retry and eventually succeed if the second
	 * attempt returns 200.
	 */
	public function test_generate_image_retries_on_429(): void {
		$this->with_token( 'test-token' );

		$codes = [ 429, 200 ];
		Functions\when( 'wp_remote_post' )->alias(
			function () use ( &$codes ) {
				$code = array_shift( $codes ) ?? 200;
				return [
					'body'     => 429 === $code ? 'slow down' : self::FAKE_IMAGE_BYTES,
					'response' => [ 'code' => $code ],
					'headers'  => [ 'content-type' => 429 === $code ? 'text/plain' : 'image/png' ],
				];
			}
		);
		$response_codes = [ 429, 200 ];
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$response_codes ) {
				return array_shift( $response_codes ) ?? 200;
			}
		);
		$response_bodies = [ 'slow down', self::FAKE_IMAGE_BYTES ];
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function () use ( &$response_bodies ) {
				return array_shift( $response_bodies ) ?? self::FAKE_IMAGE_BYTES;
			}
		);
		$mime_types = [ 'text/plain', 'image/png' ];
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $_resp, $name ) use ( &$mime_types ) {
				if ( 'content-type' === strtolower( (string) $name ) ) {
					return array_shift( $mime_types ) ?? 'image/png';
				}
				return '';
			}
		);

		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$result   = $provider->generate_image( 'a prompt', 1080, 1080 );

		$this->assertSame( self::FAKE_IMAGE_BYTES, $result['bytes'] );
	}

	/**
	 * validate_credentials_detailed returns 'ok' on a 200 from the models
	 * listing endpoint.
	 */
	public function test_validate_credentials_ok_on_200(): void {
		$this->with_token( 'test-token' );
		Functions\when( 'wp_remote_get' )->justReturn( [
			'body'     => '{"result":[{"id":"@cf/black-forest-labs/flux-1-schnell"}]}',
			'response' => [ 'code' => 200 ],
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"result":[]}' );

		$provider = new \PRAutoBlogger_Cloudflare_Image_Provider();
		$result   = $provider->validate_credentials_detailed();

		$this->assertSame( 'ok', $result['status'] );
	}
}
