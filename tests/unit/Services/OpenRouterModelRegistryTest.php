<?php
/**
 * Tests for PRAutoBlogger_OpenRouter_Model_Registry and its normalizer.
 *
 * Validates registry caching, idempotency, retry logic, stale fallback,
 * capability filtering, and the normalize → find_model → get_models flow.
 *
 * @package PRAutoBlogger\Tests\Services
 */

namespace PRAutoBlogger\Tests\Services;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class OpenRouterModelRegistryTest extends BaseTestCase {

	private const OPTION    = 'test_openrouter_model_registry';
	private const TRANSIENT = 'test_openrouter_model_registry_cache';

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":[]}' );
	}

	// ── Normalizer tests ────────────────────────────────────────

	/**
	 * Normalizer correctly maps input+output modalities → capability strings.
	 */
	public function test_normalize_maps_capabilities(): void {
		$normalizer = new \PRAutoBlogger_OpenRouter_Model_Normalizer();
		$raw        = [
			[
				'id'            => 'anthropic/claude-3.5-haiku',
				'name'          => 'Claude 3.5 Haiku',
				'context_length' => 200000,
				'pricing'       => [ 'prompt' => '0.0000008', 'completion' => '0.000004' ],
				'architecture'  => [
					'input_modalities'  => [ 'text', 'image' ],
					'output_modalities' => [ 'text' ],
				],
				'created'       => 1700000000,
			],
		];

		$result = $normalizer->normalize( $raw );

		$this->assertCount( 1, $result );
		$model = $result[0];
		$this->assertSame( 'anthropic/claude-3.5-haiku', $model['id'] );
		$this->assertSame( 'Claude 3.5 Haiku', $model['name'] );
		$this->assertSame( 'anthropic', $model['provider'] );
		$this->assertSame( 200000, $model['context_length'] );
		$this->assertEqualsWithDelta( 0.80, $model['input_price_per_m'], 0.01 );
		$this->assertEqualsWithDelta( 4.00, $model['output_price_per_m'], 0.01 );
		$this->assertContains( 'text→text', $model['capabilities'] );
		$this->assertContains( 'text+image→text', $model['capabilities'] );
	}

	/**
	 * Unknown modalities degrade to text→text, never empty capabilities.
	 */
	public function test_normalize_unknown_modalities_degrade_to_text(): void {
		$normalizer = new \PRAutoBlogger_OpenRouter_Model_Normalizer();
		$raw        = [
			[
				'id'           => 'custom/mystery-model',
				'architecture' => [
					'input_modalities'  => [ 'brainwaves' ],
					'output_modalities' => [ 'hologram' ],
				],
			],
		];

		$result = $normalizer->normalize( $raw );
		$this->assertSame( [ 'text→text' ], $result[0]['capabilities'] );
	}

	/**
	 * Records without 'id' are silently skipped.
	 */
	public function test_normalize_skips_invalid_records(): void {
		$normalizer = new \PRAutoBlogger_OpenRouter_Model_Normalizer();
		$raw        = [
			[ 'name' => 'orphan model without id' ],
			'not an array',
			[ 'id' => 'valid/model', 'name' => 'Valid' ],
		];

		$this->assertCount( 1, $normalizer->normalize( $raw ) );
	}

	/**
	 * Results are sorted alphabetically by name.
	 */
	public function test_normalize_sorts_by_name(): void {
		$normalizer = new \PRAutoBlogger_OpenRouter_Model_Normalizer();
		$raw        = [
			[ 'id' => 'z/zebra', 'name' => 'Zebra Model' ],
			[ 'id' => 'a/alpha', 'name' => 'Alpha Model' ],
			[ 'id' => 'm/mid', 'name' => 'Mid Model' ],
		];

		$result = $normalizer->normalize( $raw );
		$this->assertSame( 'Alpha Model', $result[0]['name'] );
		$this->assertSame( 'Zebra Model', $result[2]['name'] );
	}

	// ── Registry tests ──────────────────────────────────────────

	/**
	 * Idempotency: refresh(false) with a fresh payload skips HTTP.
	 */
	public function test_refresh_skips_when_fresh(): void {
		// Simulate: fetched 1 hour ago, idempotency window = 12h.
		$this->stub_get_option( [
			self::OPTION . '_fetched_at' => time() - 3600,
			self::OPTION               => [ [ 'id' => 'cached/model' ] ],
			'prautoblogger_log_level'  => 'info',
		] );

		// wp_remote_get should NOT be called.
		Functions\when( 'wp_remote_get' )->alias( function () {
			throw new \RuntimeException( 'wp_remote_get should not be called' );
		} );

		$registry = new \PRAutoBlogger_OpenRouter_Model_Registry(
			self::OPTION, self::TRANSIENT, 'https://test.example.com/models', 86400, 43200
		);

		$result = $registry->refresh( false );
		$this->assertSame( 1, $result['count'] );
	}

	/**
	 * refresh(true) bypasses idempotency and always fetches.
	 */
	public function test_refresh_force_fetches(): void {
		$this->stub_get_option( [
			self::OPTION . '_fetched_at' => time() - 60,
			self::OPTION               => [],
			'prautoblogger_log_level'  => 'info',
		] );

		$api_body = wp_json_encode( [
			'data' => [
				[ 'id' => 'fresh/model', 'name' => 'Fresh', 'architecture' => [ 'input_modalities' => [ 'text' ], 'output_modalities' => [ 'text' ] ] ],
			],
		] );

		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $api_body );

		$registry = new \PRAutoBlogger_OpenRouter_Model_Registry(
			self::OPTION, self::TRANSIENT, 'https://test.example.com/models', 86400, 43200
		);

		$result = $registry->refresh( true );
		$this->assertSame( 1, $result['count'] );
	}

	/**
	 * get_models_with_capability filters correctly.
	 */
	public function test_capability_filtering(): void {
		$models = [
			[ 'id' => 'a', 'capabilities' => [ 'text→text' ] ],
			[ 'id' => 'b', 'capabilities' => [ 'text+image→text', 'text→text' ] ],
			[ 'id' => 'c', 'capabilities' => [ 'text→image' ] ],
		];

		// Serve from transient.
		Functions\when( 'get_transient' )->alias( function ( $key ) use ( $models ) {
			return $key === self::TRANSIENT ? $models : false;
		} );

		$registry = new \PRAutoBlogger_OpenRouter_Model_Registry(
			self::OPTION, self::TRANSIENT
		);

		$text_to_text = $registry->get_models_with_capability( 'text→text' );
		$this->assertCount( 2, $text_to_text );

		$vision = $registry->get_models_with_capability( 'text+image→text' );
		$this->assertCount( 1, $vision );
		$this->assertSame( 'b', $vision[0]['id'] );
	}

	/**
	 * find_model returns null for unknown models.
	 */
	public function test_find_model_returns_null_for_unknown(): void {
		Functions\when( 'get_transient' )->justReturn( [] );
		$this->stub_get_option( [ self::OPTION => [], 'prautoblogger_log_level' => 'info' ] );

		$registry = new \PRAutoBlogger_OpenRouter_Model_Registry( self::OPTION, self::TRANSIENT );
		$this->assertNull( $registry->find_model( 'nonexistent/model' ) );
	}

	/**
	 * Stale-and-fetch-fails: serves option payload and flags admin notice.
	 */
	public function test_stale_fallback_on_fetch_failure(): void {
		$cached_models = [ [ 'id' => 'stale/model', 'capabilities' => [ 'text→text' ] ] ];

		$this->stub_get_option( [
			self::OPTION . '_fetched_at' => time() - 100000,
			self::OPTION               => $cached_models,
			'prautoblogger_log_level'  => 'info',
		] );

		// Simulate network failure.
		$wp_error = new \stdClass();
		Functions\when( 'wp_remote_get' )->justReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );
		$wp_error->get_error_message = function () {
			return 'Connection timed out';
		};
		Functions\when( 'wp_remote_get' )->alias( function () {
			return new \WP_Error( 'http_request_failed', 'Connection timed out' );
		} );

		// Accept that update_option will be called for the stale flag.
		Functions\when( 'update_option' )->justReturn( true );

		$registry = new \PRAutoBlogger_OpenRouter_Model_Registry(
			self::OPTION, self::TRANSIENT, 'https://test.example.com/models', 86400, 43200
		);

		$result = $registry->refresh( true );
		$this->assertSame( 1, $result['count'] );
	}
}

