<?php
/**
 * Tests for the v0.10.0 one-shot migration that removes Cloudflare
 * Workers AI as an image provider — exercises the cloudflare-user
 * path, the non-cloudflare-user path, idempotency, and the critical
 * scope-boundary invariant that `prautoblogger_ai_gateway_base_url`
 * (shared with the OpenRouter AI Gateway route) is left intact.
 *
 * See thread 2026-04-cloudflare-workers-ai-removal/01-cto-handoff.md.
 *
 * @package PRAutoBlogger\Tests\Core
 */

namespace PRAutoBlogger\Tests\Core;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class ActivatorRemoveCloudflareTest extends BaseTestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];

		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
	}

	/**
	 * Cloudflare user: flipped to Runware schnell, CF credentials removed,
	 * gateway URL preserved, flag set.
	 */
	public function test_cloudflare_user_migrated_to_runware(): void {
		$this->options = [
			'prautoblogger_image_provider'         => 'cloudflare',
			'prautoblogger_image_model'            => 'flux-1-schnell',
			'prautoblogger_cloudflare_ai_token'    => 'enc:SECRET',
			'prautoblogger_cloudflare_account_id'  => 'acct-123',
			'prautoblogger_cf_image_via_gateway'   => '1',
			// Shared with OpenRouter AI Gateway — MUST be preserved.
			'prautoblogger_ai_gateway_base_url'    => 'https://gateway.ai.cloudflare.com/v1/acct/gw/openrouter',
		];

		\PRAutoBlogger_Migrate_Remove_Cloudflare_V0100::run();

		$this->assertSame( 'runware', $this->options['prautoblogger_image_provider'] );
		$this->assertSame( 'runware:100@1', $this->options['prautoblogger_image_model'] );

		$this->assertArrayNotHasKey( 'prautoblogger_cloudflare_ai_token', $this->options );
		$this->assertArrayNotHasKey( 'prautoblogger_cloudflare_account_id', $this->options );
		$this->assertArrayNotHasKey( 'prautoblogger_cf_image_via_gateway', $this->options );

		$this->assertSame(
			'https://gateway.ai.cloudflare.com/v1/acct/gw/openrouter',
			$this->options['prautoblogger_ai_gateway_base_url'],
			'AI Gateway URL (shared with OpenRouter route) must NOT be touched.'
		);

		$this->assertSame( '1', $this->options['prautoblogger_migrated_remove_cloudflare_v0100'] );
	}

	/**
	 * Non-Cloudflare user (e.g. already on OpenRouter Gemini): model +
	 * provider untouched; CF credentials still swept; flag set.
	 */
	public function test_non_cloudflare_user_preserved(): void {
		$this->options = [
			'prautoblogger_image_provider'         => 'openrouter',
			'prautoblogger_image_model'            => 'google/gemini-2.5-flash-image',
			// Hypothetical orphans from an earlier experiment.
			'prautoblogger_cloudflare_ai_token'    => 'enc:OLD',
			'prautoblogger_cloudflare_account_id'  => 'acct-999',
			'prautoblogger_ai_gateway_base_url'    => 'https://gateway.ai.cloudflare.com/v1/acct/gw/openrouter',
		];

		\PRAutoBlogger_Migrate_Remove_Cloudflare_V0100::run();

		$this->assertSame( 'openrouter', $this->options['prautoblogger_image_provider'] );
		$this->assertSame( 'google/gemini-2.5-flash-image', $this->options['prautoblogger_image_model'] );

		$this->assertArrayNotHasKey( 'prautoblogger_cloudflare_ai_token', $this->options );
		$this->assertArrayNotHasKey( 'prautoblogger_cloudflare_account_id', $this->options );
		$this->assertSame(
			'https://gateway.ai.cloudflare.com/v1/acct/gw/openrouter',
			$this->options['prautoblogger_ai_gateway_base_url']
		);
		$this->assertSame( '1', $this->options['prautoblogger_migrated_remove_cloudflare_v0100'] );
	}

	/**
	 * Runware user: provider + model untouched; flag set.
	 */
	public function test_runware_user_preserved(): void {
		$this->options = [
			'prautoblogger_image_provider' => 'runware',
			'prautoblogger_image_model'    => 'runware:101@1',
		];

		\PRAutoBlogger_Migrate_Remove_Cloudflare_V0100::run();

		$this->assertSame( 'runware', $this->options['prautoblogger_image_provider'] );
		$this->assertSame( 'runware:101@1', $this->options['prautoblogger_image_model'] );
		$this->assertSame( '1', $this->options['prautoblogger_migrated_remove_cloudflare_v0100'] );
	}

	/**
	 * Legacy slug detection — the pre-registry `flux-1-schnell` model id
	 * still gets migrated even when the provider option is empty.
	 */
	public function test_legacy_slug_triggers_migration(): void {
		$this->options = [
			'prautoblogger_image_provider' => '', // Unset before v0.9.0 landed.
			'prautoblogger_image_model'    => 'flux-1-schnell',
		];

		\PRAutoBlogger_Migrate_Remove_Cloudflare_V0100::run();

		$this->assertSame( 'runware', $this->options['prautoblogger_image_provider'] );
		$this->assertSame( 'runware:100@1', $this->options['prautoblogger_image_model'] );
	}

	/**
	 * Running the migration twice is idempotent — the second pass must
	 * return immediately and must not clobber a user change made after
	 * the first migration run.
	 */
	public function test_idempotent_second_run_is_noop(): void {
		$this->options = [
			'prautoblogger_image_provider' => 'cloudflare',
			'prautoblogger_image_model'    => 'flux-1-schnell',
		];

		\PRAutoBlogger_Migrate_Remove_Cloudflare_V0100::run();
		$this->assertSame( 'runware', $this->options['prautoblogger_image_provider'] );

		// Simulate admin manually switching to OpenRouter after migration.
		$this->options['prautoblogger_image_provider'] = 'openrouter';
		$this->options['prautoblogger_image_model']    = 'google/gemini-3.1-flash-image-preview';

		\PRAutoBlogger_Migrate_Remove_Cloudflare_V0100::run();

		// Second run must NOT overwrite the admin's manual choice.
		$this->assertSame( 'openrouter', $this->options['prautoblogger_image_provider'] );
		$this->assertSame( 'google/gemini-3.1-flash-image-preview', $this->options['prautoblogger_image_model'] );
	}
}
