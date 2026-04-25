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
	 * a provider of 'runware' or 'openrouter' (Cloudflare Workers AI was
	 * removed as an image provider in v0.10.0). If this breaks, the
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
				[ 'runware', 'openrouter' ],
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
			'openrouter',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'google/gemini-2.5-flash-image' )
		);
		$this->assertSame(
			'runware',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'runware:100@1' )
		);
	}

	/**
	 * v0.10.0 scope boundary: no Cloudflare entries remain in the registry.
	 * The legacy `flux-1-schnell` slug (pre-Runware) must resolve to '' so
	 * the migration's detection path catches it. If a registry regression
	 * re-adds a cloudflare entry this test fails loudly.
	 */
	public function test_cloudflare_entries_removed_v0100(): void {
		foreach ( \PRAutoBlogger_Image_Model_Registry::get_models() as $model ) {
			$this->assertNotSame( 'cloudflare', $model['provider'] ?? '' );
		}
		$this->assertSame(
			'',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'flux-1-schnell' )
		);
	}

	/**
	 * Runware model ids (added v0.9.0) resolve to the 'runware' provider.
	 * This test pins the v0.9.0 default flip so a registry regression
	 * that loses the Runware entries fails CI loudly.
	 */
	public function test_provider_for_runware_models(): void {
		$this->assertSame(
			'runware',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'runware:100@1' )
		);
		$this->assertSame(
			'runware',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'runware:101@1' )
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

	/**
	 * Test that runware:400@4 resolves to runware provider.
	 */
	public function test_provider_for_runware_400_at_4(): void {
		$this->assertSame(
			'runware',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'runware:400@4' )
		);
	}

	/**
	 * Test that runware:glm-image@0 resolves to runware provider.
	 */
	public function test_provider_for_runware_glm_image(): void {
		$this->assertSame(
			'runware',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'runware:glm-image@0' )
		);
	}

	/**
	 * Test that openai/gpt-5.4-image-2 resolves to openrouter provider.
	 */
	public function test_provider_for_gpt_5_4_image_2(): void {
		$this->assertSame(
			'openrouter',
			\PRAutoBlogger_Image_Model_Registry::provider_for( 'openai/gpt-5.4-image-2' )
		);
	}

	/**
	 * Test that get_models() returns 21 models.
	 */
	public function test_get_models_returns_21_models(): void {
		$models = \PRAutoBlogger_Image_Model_Registry::get_models();
		$this->assertCount( 21, $models );
	}

	/**
	 * Test that every model has required non-empty fields.
	 */
	public function test_every_model_has_required_fields(): void {
		$models = \PRAutoBlogger_Image_Model_Registry::get_models();
		foreach ( $models as $model ) {
			$this->assertNotEmpty( $model['id'] ?? '', 'Model missing id' );
			$this->assertNotEmpty( $model['name'] ?? '', 'Model missing name' );
			$this->assertNotEmpty( $model['provider'] ?? '', 'Model missing provider' );
			$this->assertTrue( isset( $model['cost_per_image'] ), 'Model missing cost_per_image' );
			$this->assertIsNumeric( $model['cost_per_image'], 'cost_per_image is not numeric' );
		}
	}
}
