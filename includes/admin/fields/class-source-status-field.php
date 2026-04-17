<?php
declare(strict_types=1);

/**
 * Renders the Reddit source status indicator in the admin settings.
 *
 * What: shows RSS (primary) and .json (fallback) availability with
 *       last-collection timestamp.
 * Who calls it: PRAutoBlogger_Admin_Page::render_field() delegates here
 *               for fields with type 'source_status'.
 * Dependencies: None — reads wp_options only.
 *
 * @see admin/class-admin-page.php — Calls render() from the main field switch.
 */
class PRAutoBlogger_Source_Status_Field {

	/**
	 * Render the Reddit source status indicator.
	 *
	 * Side effects: reads wp_options for last collection time.
	 */
	public static function render(): void {
		$last_collection  = get_option( 'prautoblogger_last_collection_time', '' );
		$last_source_used = get_option( 'prautoblogger_last_source_used', '' );

		echo '<div class="ab-source-status">';

		// Reddit RSS — primary source.
		printf(
			'<div class="ab-source-row">'
			. '<span class="ab-source-dot ab-status-ok"></span>'
			. '<strong>%s</strong> <span class="ab-source-badge">%s</span>'
			. '<span class="ab-source-label">%s</span>'
			. '</div>',
			esc_html__( 'Reddit RSS', 'prautoblogger' ),
			esc_html__( 'Primary', 'prautoblogger' ),
			esc_html__( 'Reliable from all IPs', 'prautoblogger' )
		);

		// Reddit .json — fallback source.
		printf(
			'<div class="ab-source-row">'
			. '<span class="ab-source-dot ab-status-ok"></span>'
			. '<strong>%s</strong> <span class="ab-source-badge ab-badge-secondary">%s</span>'
			. '<span class="ab-source-label">%s</span>'
			. '</div>',
			esc_html__( 'Reddit .json', 'prautoblogger' ),
			esc_html__( 'Fallback + Comments', 'prautoblogger' ),
			esc_html__( 'May be IP-blocked on some hosts', 'prautoblogger' )
		);

		// Last collection info.
		if ( '' !== $last_collection ) {
			$time_ago = human_time_diff( (int) $last_collection, time() );
			printf(
				'<div class="ab-source-meta">%s <strong>%s</strong> %s</div>',
				esc_html__( 'Last collection:', 'prautoblogger' ),
				/* translators: %s is a human-readable time difference like "2 hours" */
				esc_html( sprintf( __( '%s ago', 'prautoblogger' ), $time_ago ) ),
				'' !== $last_source_used ? esc_html( sprintf( __( 'via %s', 'prautoblogger' ), $last_source_used ) ) : ''
			);
		}

		echo '</div>';
	}
}
