<?php
/**
 * Tests for PRAutoBlogger_Image_Model_Registry.
 *
 * The admin save flow (v0.8.0) collapses Provider and Image Model into a
 * single dropdown and derives the provider from the model via
 * Image_Model_Registry::provider_for(). This test pins that mapping down
 * so a registry edit that breaks the contract fails CI.
 *
 * @package PRAutoBlogger\Tests\Services
 */

namespace PRAutoBlogger\Tests\Services;

use PRAutoBlogger\Tests\BaseTestCase;

class ImageModelRegistryTest extends BaseTestCase {

	/**
	 * Every entry returned by get_models() must carry a non-empty id and
	 * a provider of 'openrouter' or 'cloudflare'. If this breaks, the
	 * admin save flow will silently drop the provider derivation.
	 */
	public function test_every_registry_entry_has_id_and_known_provider(): void {
		$models = \PRAutoBlogger_Image_Model_Registry::get_models();
		$this->assertNotEmpty( $models );

		foreach ( $models as $model ) {
			$this->assertArrayHasKey( 'id', $model );
			$this->assertNotEmpty( $model['id'] );
			$this->assertArrayHasKey( 'provider', $model );
			$this->assertContains(
				$model['provider'],
				[ 'openrouter', 'cloudflare' ],
				sprintf( 'Unexpected provider %s for model %s', (string) $model['provider'], (string) $model['id'] )
			);
		}
	}

	/**
	 * provider_for() returns the paired provider for a known model id.
	 * This is the core of the v0.8.0 collapsed-dropdown contract.
	 */
	public function test_provider_for_returns_paired_provider_for_known_model(): void {
		$this->assertSame(
			'cloudflare',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'flux-1-schnell' )
		);
		$this->assertSame(
			'openrouter',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'google/gemini-2.5-flash-image' )
		);
	}

	/**
	 * Unknown model ids return an empty string so callers can distinguish
	 * "not in registry" from a valid provider and show a settings error.
	 */
	public function test_provider_for_returns_empty_for_unknown_model(): void {
		$this->assertSame(
			'',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'nonexistent/model' )
		);
		$this->assertSame(
			'',
			\PRAutoBlogger_Image_Model_Registry::provider_for( '' )
		);
	}
}
