<?php
declare(strict_types=1);

/**
 * WordPress Dashboard widget showing PRAutoBlogger generation status at a glance.
 *
 * Displays: last generation time, articles generated today, monthly cost,
 * and budget utilization.
 *
 * Triggered by: Could be registered via wp_dashboard_setup hook.
 * Dependencies: PRAutoBlogger_Cost_Reporter.
 *
 * @see class-prautoblogger.php — Can register this on wp_dashboard_setup.
 */
class PRAutoBlogger_Dashboard_Widget {

	/**
	 * Register the dashboard widget.
	 *
	 * @return void
	 */
	public function on_register_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'prautoblogger_status',
			__( 'PRAutoBlogger Status', 'prautoblogger' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Render the widget content.
	 *
	 * @return void
	 */
	public function render_widget(): void {
		$cost_reporter = new PRAutoBlogger_Cost_Reporter();
		$monthly_spend = $cost_reporter->get_monthly_spend();
		$budget        = (float) get_option( 'prautoblogger_monthly_budget_usd', 50.00 );
		$utilization   = $cost_reporter->get_budget_utilization();

		$next_run = wp_next_scheduled( 'prautoblogger_daily_generation' );

		printf(
			'<p><strong>%s</strong> $%.2f / $%.2f (%.0f%%)</p>',
			esc_html__( 'Monthly Spend:', 'prautoblogger' ),
			$monthly_spend,
			$budget,
			$utilization
		);

		if ( false !== $next_run ) {
			printf(
				'<p><strong>%s</strong> %s</p>',
				esc_html__( 'Next Generation:', 'prautoblogger' ),
				esc_html( wp_date( 'M j, Y g:i A', $next_run ) )
			);
		}

		printf(
			'<p><a href="%s" class="button button-secondary">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=prautoblogger-metrics' ) ),
			esc_html__( 'View Full Metrics', 'prautoblogger' )
		);
	}
}
