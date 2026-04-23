<?php
declare(strict_types=1);

/**
 * Admin page displaying structured application log entries.
 *
 * Provides level filtering (error/warning/info/debug), text search,
 * pagination, and a one-click "Clear Old Logs" action. Reads from the
 * prab_event_log table via PRAutoBlogger_Logger::query().
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu`.
 * Dependencies: PRAutoBlogger_Logger.
 *
 * @see core/class-logger.php              — Writes log entries and provides query().
 * @see templates/admin/log-viewer.php     — Renders the HTML.
 * @see class-prautoblogger.php              — Registers the AJAX clear action.
 */
class PRAutoBlogger_Log_Viewer {

	/**
	 * Register the log viewer submenu page under PRAutoBlogger.
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		add_submenu_page(
			'prautoblogger-settings',
			__( 'Activity Log', 'prautoblogger' ),
			__( 'Activity Log', 'prautoblogger' ),
			'manage_options',
			'prautoblogger-logs',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the log viewer page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$level  = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		$result      = PRAutoBlogger_Logger::query( $level, $search, $paged, 50 );
		$rows        = $result['rows'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / 50 );

		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/log-viewer.php';
	}

	/**
	 * AJAX handler: clear logs older than 30 days.
	 *
	 * Side effects: deletes rows from prab_event_log.
	 *
	 * @return void
	 */
	public function on_ajax_clear_logs(): void {
		check_ajax_referer( 'prautoblogger_clear_logs', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
			return;
		}

		$days    = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
		$deleted = PRAutoBlogger_Logger::prune( $days );

		wp_send_json_success(
			array(
				'message' => sprintf(
				/* translators: %d: number of entries deleted */
					__( '%d log entries cleared.', 'prautoblogger' ),
					$deleted
				),
			)
		);
	}
}
