<?php
declare(strict_types=1);

/**
 * Displays admin notices for onboarding, errors, and budget warnings.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_notices`.
 * Dependencies: PRAutoBlogger_Cost_Reporter (for budget warnings).
 *
 * @see class-prautoblogger.php — Registers the hook.
 */
class PRAutoBlogger_Admin_Notices {

	/**
	 * Display relevant admin notices.
	 *
	 * @return void
	 */
	public function on_display_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->check_api_key_notice();
		$this->check_budget_notice();
		$this->check_subreddit_notice();
	}

	/**
	 * Show a notice if the OpenRouter API key is not configured.
	 *
	 * @return void
	 */
	private function check_api_key_notice(): void {
		$api_key = get_option( 'prautoblogger_openrouter_api_key', '' );
		if ( '' !== $api_key ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'PRAutoBlogger: OpenRouter API key is not configured. Content generation is disabled.', 'prautoblogger' ),
			esc_url( admin_url( 'admin.php?page=prautoblogger-settings' ) ),
			esc_html__( 'Configure now', 'prautoblogger' )
		);
	}

	/**
	 * Show a warning if monthly budget is approaching or exceeded.
	 *
	 * @return void
	 */
	private function check_budget_notice(): void {
		$cost_reporter = new PRAutoBlogger_Cost_Reporter();
		$utilization   = $cost_reporter->get_budget_utilization();

		if ( $utilization >= 100.0 ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'PRAutoBlogger: Monthly API budget EXCEEDED. Content generation is paused until next month or budget increase.', 'prautoblogger' )
			);
		} elseif ( $utilization >= 80.0 ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %s: budget utilization percentage */
					__( 'PRAutoBlogger: Monthly API budget is at %.0f%%. Consider increasing the budget or reducing daily article count.', 'prautoblogger' ),
					$utilization
				) )
			);
		}
	}

	/**
	 * Show a notice if no subreddits are configured.
	 *
	 * @return void
	 */
	private function check_subreddit_notice(): void {
		$subreddits = json_decode( get_option( 'prautoblogger_target_subreddits', '[]' ), true );
		if ( ! empty( $subreddits ) ) {
			return;
		}

		// Only show on our settings page.
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_prautoblogger-settings' !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html__( 'PRAutoBlogger: No subreddits configured. Add target subreddits in Source Configuration to start collecting data.', 'prautoblogger' )
		);
	}
}
