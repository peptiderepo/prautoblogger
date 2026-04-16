<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Validator.
 *
 * Validates credential checking logic including encryption, format validation,
 * and HTTP connectivity probes.
 * All HTTP calls are mocked — no real API calls.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterValidatorTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Stub get_option with default empty state.
		$this->stub_get_option( [
			'prautoblogger_openrouter_api_key'   => '',
			'prautoblogger_ai_gateway_base_url'  => '',
			'prautoblogger_ai_gateway_cache_ttl' => 0,
			'prautoblogger_log_level'            => 'info',
		] );

		// Stub wp_salt for encryption.
		Functions\when( 'wp_salt' )->justReturn( 'test_salt_key_for_unit_tests' );

		// Stub URL parsing.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		// Stub HTTP functions.
		Functions\when( 'wp_remote_get' )->justReturn( [
			'body'     => '{}',
			'response' => [ 'code' => 200 ],
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	/**
	 * Test validator can be instantiated.
	 */
	public function test_validator_instantiation(): void {
		$validator = new \PRAutoBlogger_OpenRouter_Validator();
		$this->assertInstanceOf( \PRAutoBlogger_OpenRouter_Validator::class, $validator );
	}

	/**
	 * Test run() returns array with expected keys.
	 */
	public function test_run_returns_array_with_status(): void {
		$validator = new \PRAutoBlogger_OpenRouter_Validator();
		$result    = $validator->run();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Test validation fails when no API key is configured.
	 */
	public function test_run_fails_with_no_api_key(): void {
		$validator = new \PRAutoBlogger_OpenRouter_Validator();
		$result    = $validator->run();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'No API key', $result['message'] );
	}

	/**
	 * Test validation succeeds with a valid decrypted key.
	 */
	public function test_run_succeeds_with_valid_key(): void {
		// Round-trip through encryption to get a valid ciphertext.
		$plaintext  = 'sk-or-test-key-1234567890';
		$ciphertext = \PRAutoBlogger_Encryption::encrypt( $plaintext );

		$this->stub_get_option( [
			'prautoblogger_openrouter_api_key'   => $ciphertext,
			'prautoblogger_ai_gateway_base_url'  => '',
			'prautoblogger_ai_gateway_cache_ttl' => 0,
			'prautoblogger_log_level'            => 'info',
		] );

		$validator = new \PRAutoBlogger_OpenRouter_Validator();
		$result    = $validator->run();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertStringContainsString( 'connected', strtolower( $result['message'] ) );
	}

	/**
	 * Test validation fails when decryption fails.
	 */
	public function test_run_fails_with_bad_ciphertext(): void {
		// Stub get_option to return invalid ciphertext.
		$this->stub_get_option( [
			'prautoblogger_openrouter_api_key'   => 'corrupted_base64_data_that_is_not_valid',
			'prautoblogger_ai_gateway_base_url'  => '',
			'prautoblogger_ai_gateway_cache_ttl' => 0,
			'prautoblogger_log_level'            => 'info',
		] );

		$validator = new \PRAutoBlogger_OpenRouter_Validator();
		$result    = $validator->run();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'decryption', strtolower( $result['message'] ) );
	}

	/**
	 * Test validation fails when decrypted key has wrong format.
	 */
	public function test_run_fails_with_bad_key_format(): void {
		// Encrypt a key that doesn't start with "sk-or-".
		$plaintext  = 'invalid-key-format';
		$ciphertext = \PRAutoBlogger_Encryption::encrypt( $plaintext );

		$this->stub_get_option( [
			'prautoblogger_openrouter_api_key'   => $ciphertext,
			'prautoblogger_ai_gateway_base_url'  => '',
			'prautoblogger_ai_gateway_cache_ttl' => 0,
			'prautoblogger_log_level'            => 'info',
		] );

		$validator = new \PRAutoBlogger_OpenRouter_Validator();
		$result    = $validator->run();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'corrupted', strtolower( $result['message'] ) );
	}

	/**
	 * Test validation fails when HTTP request fails.
	 */
	public function test_run_fails_with_wp_error(): void {
		$plaintext  = 'sk-or-test-key-1234567890';
		$ciphertext = \PRAutoBlogger_Encryption::encrypt( $plaintext );

		$this->stub_get_option( [
			'prautoblogger_openrouter_api_key'   => $ciphertext,
			'prautoblogger_ai_gateway_base_url'  => '',
			'prautoblogger_ai_gateway_cache_ttl' => 0,
			'prautoblogger_log_level'            => 'info',
		] );

		// Stub wp_remote_get to return an error.
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->alias(
			function () {
				$error = new \WP_Error( 'http_error', 'Connection refused' );
				return $error;
			}
		);

		$validator = new \PRAutoBlogger_OpenRouter_Validator();
		$result    = $validator->run();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'Network error', $result['message'] );
	}

	/**
	 * Test validation fails when API returns non-200 status.
	 */
	public function test_run_fails_with_http_error(): void {
		$plaintext  = 'sk-or-test-key-1234567890';
		$ciphertext = \PRAutoBlogger_Encryption::encrypt( $plaintext );

		$this->stub_get_option( [
			'prautoblogger_openrouter_api_key'   => $ciphertext,
			'prautoblogger_ai_gateway_base_url'  => '',
			'prautoblogger_ai_gateway_cache_ttl' => 0,
			'prautoblogger_log_level'            => 'info',
		] );

		// Stub to return 401 error.
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( [
			'body'     => '{"error":{"message":"Unauthorized"}}',
			'response' => [ 'code' => 401 ],
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":{"message":"Unauthorized"}}' );

		$validator = new \PRAutoBlogger_OpenRouter_Validator();
		$result    = $validator->run();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'HTTP 401', $result['message'] );
	}
}
