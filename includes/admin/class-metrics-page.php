<?php
declare(strict_types=1);

/**
 * Admin page displaying cost dashboard, content scores, and generation history.
 *
 * Shows: monthly spend, daily cost chart, spend by pipeline stage, budget
 * utilization, top/bottom performing posts, and recent generation logs.
 *
 * Triggered by: Autoblogger::register_admin_hooks() on `admin_menu`.
 * Dependencies: Autoblogger_Cost_Tracker.
 *
 * @see class-autoblogger.php       — Registers the hook.
 * @see core/class-cost-tracker.php  — Provides cost data.
 */
class Autoblogger_Metrics_Page {

	/**
	 * Register the metrics submenu page under AutoBlogger.
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		add_submenu_page(
			'autoblogger-settings',
			__( 'Metrics & Costs', 'autoblogger' ),
			__( 'Metrics & Costs', 'autoblogger' ),
			'manage_options',
			'autoblogger-metrics',
			[ $this, 'render_page' ]
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

		include AUTOBLOGGER_PLUGIN_DIR . 'templates/admin/metrics-page.php';
	}
}
