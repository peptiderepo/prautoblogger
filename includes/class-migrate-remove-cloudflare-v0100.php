<?php
declare(strict_types=1);

/**
 * One-shot v0.10.0 migration — remove Cloudflare Workers AI as image provider.
 *
 * Cloudflare Workers AI was removed as an image provider in v0.10.0.
 * Runware FLUX.1 schnell is 65× cheaper at equivalent quality, so any user
 * still on a `cloudflare` provider must be migrated off — otherwise the
 * image pipeline throws "no provider for id cloudflare" at the first
 * generation run after upgrade.
 *
 * Split out of class-activator.php so that file stays under the 300-line
 * cap. The activator's maybe_run_db_migrations() delegates here.
 *
 * Triggered by: PRAutoBlogger::maybe_run_db_migrations() once per admin_init
 *               until the `prautoblogger_migrated_remove_cloudflare_v0100`
 *               flag is set.
 * Dependencies: PRAutoBlogger_Image_Model_Registry::provider_for() for
 *               current-model provider derivation.
 *
 * @see class-activator.php                 — Parallel v0.9.0 migration.
 * @see class-prautoblogger.php             — Calls run() during admin_init.
 * @see class-image-model-registry.php      — Provider derivation source.
 * @see convo/prautoblogger/threads/2026-04-cloudflare-workers-ai-removal/
 */
class PRAutoBlogger_Migrate_Remove_Cloudflare_V0100 {

	/**
	 * Migration flag option key. Presence = already migrated.
	 */
	private const FLAG = 'prautoblogger_migrated_remove_cloudflare_v0100';

	/**
	 * Run the migration. Idempotent — second + later calls are no-ops.
	 *
	 * Side effects: may update `prautoblogger_image_model` +
	 *               `prautoblogger_image_provider`; always deletes
	 *               `prautoblogger_cloudflare_ai_token`,
	 *               `prautoblogger_cloudflare_account_id`,
	 *               `prautoblogger_cf_image_via_gateway`; sets the
	 *               migration flag option.
	 *
	 * Explicitly does NOT touch `prautoblogger_ai_gateway_base_url` —
	 * that option is shared with the OpenRouter AI Gateway route and
	 * must stay intact.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( get_option( self::FLAG ) ) {
			return;
		}

		if ( self::is_user_on_cloudflare() ) {
			// Closest equivalent — FLUX.1 schnell, Runware-hosted, ~$0.0006/image.
			update_option( 'prautoblogger_image_model', 'runware:100@1' );
			update_option( 'prautoblogger_image_provider', 'runware' );
		}

		// Clean up orphaned CF Workers AI credentials + the dead gateway toggle.
		delete_option( 'prautoblogger_cloudflare_ai_token' );
		delete_option( 'prautoblogger_cloudflare_account_id' );
		delete_option( 'prautoblogger_cf_image_via_gateway' );

		update_option( self::FLAG, '1' );
	}

	/**
	 * Detect whether the site is currently configured to use Cloudflare
	 * Workers AI for image generation.
	 *
	 * Because the v0.10.0 image-model registry no longer contains
	 * Cloudflare entries, `provider_for()` returns '' for legacy
	 * `flux-1-schnell` slugs. We therefore look at three signals and
	 * treat any of them as "on Cloudflare":
	 *   1. Current registry-derived provider === 'cloudflare'.
	 *   2. The legacy `prautoblogger_image_provider` option is 'cloudflare'.
	 *   3. The legacy CF-specific slug `flux-1-schnell` is still persisted
	 *      as the image model (pre-registry-rename installs).
	 *
	 * @return bool
	 */
	private static function is_user_on_cloudflare(): bool {
		$curr          = (string) get_option( 'prautoblogger_image_model', '' );
		$curr_provider = PRAutoBlogger_Image_Model_Registry::provider_for( $curr );

		if ( 'cloudflare' === $curr_provider ) {
			return true;
		}

		$legacy_provider = (string) get_option( 'prautoblogger_image_provider', '' );
		if ( 'cloudflare' === $legacy_provider ) {
			return true;
		}

		return 'flux-1-schnell' === $curr;
	}
}
