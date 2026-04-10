<?php
declare(strict_types=1);

/**
 * WordPress Dashboard widget showing AutoBlogger generation status at a glance.
 *
 * Displays: last generation time, articles generated today, monthly cost,
 * and budget utilization.
 *
 * Triggered by: Could be registered via wp_dashboard_setup hook.
 * Dependencies: Autoblogger_Cost_Tracker.
 *
 * @see class-autoblogger.php — Can register this on wp_dashboard_setup.
 */
class Autoblogger_Dashboard_Widget {

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
			'autoblogger_status',
			__( 'AutoBlogger Status', 'autoblogger' ),
			[ $this, 'render_widget' ]
		);
	}

	/**
	 * Render the widget content.
	 *
	 * @return void
	 */
	public function render_widget(): void {
		$cost_tracker = new Autoblogger_Cost_Tracker();
		$monthly_spend = $cost_tracker->get_monthly_spend();
		$budget        = (float) get_option( 'autoblogger_monthly_budget_usd', 50.00 );
		$utilization   = $cost_tracker->get_budget_utilization();

		$next_run = wp_next_scheduled( 'autoblogger_daily_generation' );

		printf( '<p><strong>%s</strong> $%.2f / $%.2f (%.0f%%)</p>',
			esc_html__( 'Monthly Spend:', 'autoblogger' ),
			$monthly_spend,
			$budget,
			$utilization
		);

		if ( false !== $next_run ) {
			printf( '<p><strong>%s</strong> %s</p>',
				esc_html__( 'Next Generation:', 'autoblogger' ),
				esc_html( wp_date( 'M j, Y g:i A', $next_run ) )
			);
		}

		printf(
			'<p><a href="%s" class="button button-secondary">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=autoblogger-metrics' ) ),
			esc_html__( 'View Full Metrics', 'autoblogger' )
		);
	}
}
