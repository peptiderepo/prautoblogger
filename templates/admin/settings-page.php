<?php
/**
 * Settings page template — modern tabbed UI.
 *
 * Variables from Autoblogger_Admin_Page::render_page():
 *   $sections — tab definitions from Settings_Fields::get_sections().
 *   $fields   — all field definitions (unused here, WP renders via do_settings_fields).
 *
 * @see admin/class-admin-page.php — Renders this template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active_tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'autoblogger_api';
$cost_tracker   = new Autoblogger_Cost_Tracker();
$monthly_spend  = $cost_tracker->get_monthly_spend();
$budget         = (float) get_option( 'autoblogger_monthly_budget_usd', 50.00 );
$utilization    = $budget > 0 ? ( $monthly_spend / $budget ) * 100.0 : 0;
$next_run       = wp_next_scheduled( 'autoblogger_daily_generation' );
?>
<div class="wrap ab-wrap">

	<!-- Header -->
	<div class="ab-header">
		<div class="ab-header-left">
			<span class="dashicons dashicons-edit-page ab-header-icon"></span>
			<div>
				<h1 class="ab-header-title"><?php esc_html_e( 'AutoBlogger', 'autoblogger' ); ?></h1>
				<span class="ab-header-version">v<?php echo esc_html( AUTOBLOGGER_VERSION ); ?></span>
			</div>
		</div>
		<div class="ab-header-actions">
			<button type="button" id="autoblogger-test-connection" class="ab-btn ab-btn-outline">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Test Connections', 'autoblogger' ); ?>
			</button>
			<button type="button" id="autoblogger-generate-now" class="ab-btn ab-btn-primary">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Generate Now', 'autoblogger' ); ?>
			</button>
		</div>
	</div>

	<!-- Quick stats bar -->
	<div class="ab-stats-bar">
		<div class="ab-stat">
			<span class="ab-stat-label"><?php esc_html_e( 'Monthly Spend', 'autoblogger' ); ?></span>
			<span class="ab-stat-value <?php echo $utilization >= 90 ? 'ab-text-danger' : ( $utilization >= 70 ? 'ab-text-warning' : '' ); ?>">
				$<?php echo esc_html( number_format( $monthly_spend, 2 ) ); ?>
				<small>/ $<?php echo esc_html( number_format( $budget, 2 ) ); ?></small>
			</span>
		</div>
		<div class="ab-stat">
			<span class="ab-stat-label"><?php esc_html_e( 'Next Run', 'autoblogger' ); ?></span>
			<span class="ab-stat-value">
				<?php echo false !== $next_run ? esc_html( wp_date( 'M j, g:i A', $next_run ) ) : '<em>' . esc_html__( 'Not scheduled', 'autoblogger' ) . '</em>'; ?>
			</span>
		</div>
		<div class="ab-stat">
			<span class="ab-stat-label"><?php esc_html_e( 'Review Queue', 'autoblogger' ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=autoblogger-review-queue' ) ); ?>" class="ab-stat-value ab-stat-link">
				<?php esc_html_e( 'View Drafts →', 'autoblogger' ); ?>
			</a>
		</div>
	</div>

	<div id="autoblogger-status-message" class="hidden"></div>

	<!-- Tab navigation + settings form -->
	<div class="ab-layout">
		<nav class="ab-tabs" role="tablist">
			<?php foreach ( $sections as $sid => $sec ) : ?>
				<a href="#"
				   class="ab-tab <?php echo $active_tab === $sid ? 'ab-tab-active' : ''; ?>"
				   data-tab="<?php echo esc_attr( $sid ); ?>"
				   role="tab">
					<span class="dashicons <?php echo esc_attr( $sec['icon'] ?? 'dashicons-admin-generic' ); ?>"></span>
					<span class="ab-tab-label"><?php echo esc_html( $sec['title'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>

		<div class="ab-content">
			<form method="post" action="options.php" id="ab-settings-form">
				<?php settings_fields( 'autoblogger_settings_group' ); ?>

				<?php foreach ( $sections as $sid => $sec ) : ?>
					<div class="ab-panel <?php echo $active_tab === $sid ? 'ab-panel-active' : ''; ?>"
					     data-tab="<?php echo esc_attr( $sid ); ?>"
					     role="tabpanel">
						<div class="ab-panel-head">
							<h2 class="ab-panel-title"><?php echo esc_html( $sec['title'] ); ?></h2>
							<?php if ( ! empty( $sec['description'] ) ) : ?>
								<p class="ab-panel-desc"><?php echo esc_html( $sec['description'] ); ?></p>
							<?php endif; ?>
						</div>
						<table class="form-table ab-form-table" role="presentation">
							<?php do_settings_fields( 'autoblogger-settings', $sid ); ?>
						</table>
					</div>
				<?php endforeach; ?>

				<div class="ab-save-bar">
					<?php submit_button( __( 'Save Settings', 'autoblogger' ), 'ab-btn ab-btn-primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
	</div>
</div>
