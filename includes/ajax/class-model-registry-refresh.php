<?php
declare(strict_types=1);

/**
 * AJAX handler to manually refresh the OpenRouter model registry.
 *
 * What: Allows admins to trigger an immediate refresh of the cached model
 *       list via a button in the admin UI, without waiting for the daily
 *       cron. Useful for testing new models or after adding an API key.
 * Who calls: Admin-facing JavaScript (model-picker.js) via wp_ajax.
 * Dependencies: PRAutoBlogger_OpenRouter_Model_Registry, capability gating.
 *
 * Triggered by: AJAX POST to 'wp-admin/admin-ajax.php?action=prautoblogger_refresh_models'
 * Side effects: HTTP GET to OpenRouter /api/v1/models (optional, if 12h window passed);
 *               wp_options write; transient set.
 *
 * @see includes/class-ajax-handlers.php — Instantiation and wiring.
 * @see assets/js/model-picker.js       — Client-side trigger.
 * @see services/class-open-router-model-registry.php — Registry that performs the work.
 */
class PRAutoBlogger_Model_Registry_Refresh {

	/** @var PRAutoBlogger_OpenRouter_Model_Registry Lazy-loaded singleton. */
	private PRAutoBlogger_OpenRouter_Model_Registry $registry;

	/**
	 * @param PRAutoBlogger_OpenRouter_Model_Registry $registry Shared model registry.
	 */
	public function __construct( PRAutoBlogger_OpenRouter_Model_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Handle the AJAX refresh request.
	 *
	 * Security: nonce-gated via wp-nonce, capability gated to manage_options.
	 * Response format: JSON { success: bool, data: { count: int, fetched_at: int, message: string } }
	 *
	 * @side-effect Writes HTTP GET request, database options, transient cache.
	 * @return void (echoes JSON)
	 */
	public function handle(): void {
		// Nonce check.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'prautoblogger_refresh_models' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Please refresh the page and try again.', 'prautoblogger' ),
				),
				403
			);
		}

		// Capability check.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions. Only administrators can refresh the model list.', 'prautoblogger' ),
				),
				403
			);
		}

		// Perform the refresh (force=true bypasses the idempotency window).
		$result = $this->registry->refresh( true );

		$count       = (int) ( $result['count'] ?? 0 );
		$fetched_at  = (int) ( $result['fetched_at'] ?? 0 );
		$mins_ago    = $fetched_at > 0 ? intval( ( time() - $fetched_at ) / 60 ) : 0;

		wp_send_json_success(
			array(
				'count'       => $count,
				'fetched_at'  => $fetched_at,
				'message'     => sprintf(
					__( 'Model registry refreshed: %1$d models loaded, last updated %2$s', 'prautoblogger' ),
					$count,
					0 === $mins_ago ? __( 'just now', 'prautoblogger' ) : sprintf( __( '%d minutes ago', 'prautoblogger' ), $mins_ago )
				),
			)
		);
	}
}
