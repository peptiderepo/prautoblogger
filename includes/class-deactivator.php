<?php
declare(strict_types=1);

/**
 * Handles plugin deactivation: clears scheduled cron events and transients.
 *
 * Note: does NOT delete data or options — that happens in uninstall.php.
 * Deactivation should be reversible (user can reactivate and pick up where they left off).
 *
 * Triggered by: WordPress `register_deactivation_hook` in prautoblogger.php.
 * Dependencies: None.
 *
 * @see class-activator.php — Sets up what this class tears down.
 * @see uninstall.php       — Permanent data removal on plugin deletion.
 */
class PRAutoBlogger_Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * Side effects: removes WP-Cron events and deletes transients.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::clear_cron();
		self::clear_transients();
	}

	/**
	 * Remove all scheduled cron events belonging to this plugin.
	 *
	 * @return void
	 */
	private static function clear_cron(): void {
		$hooks = array(
			'prautoblogger_daily_generation',
			'prautoblogger_collect_metrics',
			'prautoblogger_reap_orphan_research_rows',
		);

		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	/**
	 * Delete all plugin transients.
	 *
	 * Side effects: deletes transients from wp_options.
	 *
	 * @return void
	 */
	private static function clear_transients(): void {
		$transients = array(
			'prautoblogger_reddit_token',
			'prautoblogger_generation_lock',
			'prautoblogger_openrouter_models',
		);

		foreach ( $transients as $transient ) {
			delete_transient( $transient );
		}
	}
}
