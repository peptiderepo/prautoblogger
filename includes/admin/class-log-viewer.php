<?php
declare(strict_types=1);

/**
 * Admin page displaying structured application log entries.
 *
 * Provides level filtering (error/warning/info/debug), text search,
 * pagination, and a one-click "Clear Old Logs" action. Reads from the
 * ab_event_log table via Autoblogger_Logger::query().
 *
 * Triggered by: Autoblogger::register_admin_hooks() on `admin_menu`.
 * Dependencies: Autoblogger_Logger.
 *
 * @see core/class-logger.php              — Writes log entries and provides query().
 * @see templates/admin/log-viewer.php     — Renders the HTML.
 * @see class-autoblogger.php              — Registers the AJAX clear action.
 */
class Autoblogger_Log_Viewer {

	/**
	 * Register the log viewer submenu page under AutoBlogger.
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		add_submenu_page(
			'autoblogger-settings',
			__( 'Activity Log', 'autoblogger' ),
			__( 'Activity Log', 'autoblogger' ),
			'manage_options',
			'autoblogger-logs',
			[ $this, 'render_page' ]
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

		$result      = Autoblogger_Logger::query( $level, $search, $paged, 50 );
		$rows        = $result['rows'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / 50 );

		include AUTOBLOGGER_PLUGIN_DIR . 'templates/admin/log-viewer.php';
	}

	/**
	 * AJAX handler: clear logs older than 30 days.
	 *
	 * Side effects: deletes rows from ab_event_log.
	 *
	 * @return void
	 */
	public function on_ajax_clear_logs(): void {
		check_ajax_referer( 'autoblogger_clear_logs', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'autoblogger' ) ], 403 );
			return;
		}

		$days    = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
		$deleted = Autoblogger_Logger::prune( $days );

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %d: number of entries deleted */
				__( '%d log entries cleared.', 'autoblogger' ),
				$deleted
			),
		] );
	}
}
