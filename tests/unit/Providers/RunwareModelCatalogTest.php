<?php
/**
 * Tests for PRAutoBlogger_Runware_Model_Catalog.
 *
 * Validates live sync, fallback logic, cache staleness, and pricing merge.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

class RunwareModelCatalogTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Mock WP options for cache simulation.
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			static $options = array();
			return $options[ $key ] ?? $default;
		} );
		Functions\when( 'update_option' )->alias( function ( $key, $value ) {
			static $options = array();
			$options[ $key ] = $value;
			return true;
		} );
		Functions\when( 'time' )->justReturn( 1704067200 ); // 2024-01-01 00:00:00
	}

	/** Sync with empty or missing API key returns false. */
	public function test_sync_no_api_key(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$result = $catalog->sync();
		$this->assertFalse( $result );
	}

	/** get_models returns fallback if no cache and sync fails. */
	public function test_get_models_fallback_on_sync_failure(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$models = $catalog->get_models();

		// Should have the fallback list (15 Runware models).
		$this->assertIsArray( $models );
		$this->assertGreaterThan( 0, count( $models ) );

		// Check at least one expected fallback model exists.
		$schnell_exists = false;
		foreach ( $models as $model ) {
			if ( 'runware:100@1' === ( $model['id'] ?? '' ) ) {
				$schnell_exists = true;
				$this->assertSame( 'FLUX.1 schnell (Runware)', $model['name'] );
				$this->assertSame( 'runware', $model['provider'] );
				break;
			}
		}
		$this->assertTrue( $schnell_exists, 'Fallback should include FLUX.1 schnell' );
	}

	/** is_stale returns true if never synced. */
	public function test_is_stale_never_synced(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$this->assertTrue( $catalog->is_stale() );
	}

	/** get_last_synced_at returns null if never synced. */
	public function test_get_last_synced_at_never(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$this->assertNull( $catalog->get_last_synced_at() );
	}

	/** Fallback list is never empty. */
	public function test_get_models_never_empty(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();

		// Even with no cache and failed sync, get_models should return the fallback.
		$models = $catalog->get_models();
		$this->assertIsArray( $models );
		$this->assertNotEmpty( $models );

		// All models should have required fields.
		foreach ( $models as $model ) {
			$this->assertArrayHasKey( 'id', $model );
			$this->assertArrayHasKey( 'name', $model );
			$this->assertArrayHasKey( 'provider', $model );
			$this->assertArrayHasKey( 'capabilities', $model );
		}
	}

	/** Fallback models have correct pricing. */
	public function test_fallback_models_have_pricing(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$models = $catalog->get_models();

		$schnell = null;
		$dev = null;
		foreach ( $models as $model ) {
			if ( 'runware:100@1' === $model['id'] ) {
				$schnell = $model;
			}
			if ( 'runware:101@1' === $model['id'] ) {
				$dev = $model;
			}
		}

		$this->assertNotNull( $schnell );
		$this->assertEqualsWithDelta( 0.0006, $schnell['cost_per_image'], 0.000001 );

		$this->assertNotNull( $dev );
		$this->assertEqualsWithDelta( 0.02, $dev['cost_per_image'], 0.000001 );
	}

	/** Fallback models all have 'image_generation' capability. */
	public function test_fallback_models_have_capabilities(): void {
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$models = $catalog->get_models();

		foreach ( $models as $model ) {
			$this->assertIsArray( $model['capabilities'] );
			$this->assertContains( 'image_generation', $model['capabilities'] );
		}
	}

	/** Happy path: successful sync caches models and get_models returns them. */
	public function test_sync_success_caches_and_returns_models(): void {
		// The mocked API response body — models must have taskType=imageInference
		// so normalize_models() doesn't filter them out.
		$fake_body = json_encode(
			array(
				'data' => array(
					array(
						'id'       => 'test-model-1',
						'name'     => 'Test Model 1',
						'taskType' => 'imageInference',
					),
					array(
						'id'       => 'test-model-2',
						'name'     => 'Test Model 2',
						'taskType' => 'imageInference',
					),
				),
			)
		);

		$fake_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => $fake_body,
		);

		Functions\when( 'wp_remote_post' )->justReturn( $fake_response );
		// Alias the WP response-parsing helpers so they work against our array.
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $r ) { return (string) ( $r['body'] ?? '' ); }
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Shared options store so get_option/update_option stay in sync
		// within this test. The API key must be non-empty for sync() to proceed.
		$store = array( 'prautoblogger_runware_api_key' => 'test-api-key-plain' );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( &$store ) {
				return $store[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		// Sync should succeed: API key present, HTTP 200, 2 imageInference models.
		$catalog = new \PRAutoBlogger_Runware_Model_Catalog();
		$result = $catalog->sync();
		$this->assertTrue( $result, 'Sync should return true on HTTP 200 with valid models' );

		// get_models() should now return the synced list from cache.
		$models = $catalog->get_models();
		$this->assertIsArray( $models );
		$this->assertCount( 2, $models );
		$this->assertSame( 'test-model-1', $models[0]['id'] );
		$this->assertSame( 'test-model-2', $models[1]['id'] );
	}
}
