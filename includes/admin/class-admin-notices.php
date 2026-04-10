<?php
declare(strict_types=1);

/**
 * Displays admin notices for onboarding, errors, and budget warnings.
 *
 * Triggered by: Autoblogger::register_admin_hooks() on `admin_notices`.
 * Dependencies: Autoblogger_Cost_Tracker (for budget warnings).
 *
 * @see class-autoblogger.php — Registers the hook.
 */
class Autoblogger_Admin_Notices {

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
		$api_key = get_option( 'autoblogger_openrouter_api_key', '' );
		if ( '' !== $api_key ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'AutoBlogger: OpenRouter API key is not configured. Content generation is disabled.', 'autoblogger' ),
			esc_url( admin_url( 'admin.php?page=autoblogger-settings' ) ),
			esc_html__( 'Configure now', 'autoblogger' )
		);
	}

	/**
	 * Show a warning if monthly budget is approaching or exceeded.
	 *
	 * @return void
	 */
	private function check_budget_notice(): void {
		$cost_tracker = new Autoblogger_Cost_Tracker();
		$utilization  = $cost_tracker->get_budget_utilization();

		if ( $utilization >= 100.0 ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'AutoBlogger: Monthly API budget EXCEEDED. Content generation is paused until next month or budget increase.', 'autoblogger' )
			);
		} elseif ( $utilization >= 80.0 ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %s: budget utilization percentage */
					__( 'AutoBlogger: Monthly API budget is at %.0f%%. Consider increasing the budget or reducing daily article count.', 'autoblogger' ),
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
		$subreddits = json_decode( get_option( 'autoblogger_target_subreddits', '[]' ), true );
		if ( ! empty( $subreddits ) ) {
			return;
		}

		// Only show on our settings page.
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_autoblogger-settings' !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html__( 'AutoBlogger: No subreddits configured. Add target subreddits in Source Configuration to start collecting data.', 'autoblogger' )
		);
	}
}
