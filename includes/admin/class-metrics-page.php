<?php
declare(strict_types=1);

/**
 * Admin page displaying cost dashboard, content scores, and generation history.
 *
 * Shows: monthly spend, daily cost chart, spend by pipeline stage, budget
 * utilization, top/bottom performing posts, and recent generation logs.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu`.
 * Dependencies: PRAutoBlogger_Cost_Tracker.
 *
 * @see class-prautoblogger.php       — Registers the hook.
 * @see core/class-cost-tracker.php  — Provides cost data.
 */
class PRAutoBlogger_Metrics_Page {

	/**
	 * Register the metrics submenu page under PRAutoBlogger.
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		add_submenu_page(
			'prautoblogger-settings',
			__( 'Metrics & Costs', 'prautoblogger' ),
			__( 'Metrics & Costs', 'prautoblogger' ),
			'manage_options',
			'prautoblogger-metrics',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the metrics page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/metrics-page.php';
	}
}
