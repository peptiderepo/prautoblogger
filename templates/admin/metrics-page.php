<?php
/**
 * Metrics & cost dashboard template.
 *
 * @see admin/class-metrics-page.php — Renders this template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cost_tracker = new Autoblogger_Cost_Tracker();
$monthly_spend = $cost_tracker->get_monthly_spend();
$budget        = (float) get_option( 'autoblogger_monthly_budget_usd', 50.00 );
$utilization   = $cost_tracker->get_budget_utilization();
$daily_spend   = $cost_tracker->get_daily_spend( 30 );

$first_of_month = gmdate( 'Y-m-01' );
$today          = gmdate( 'Y-m-d' );
$stage_breakdown = $cost_tracker->get_spend_by_stage( $first_of_month, $today );
?>
<div class="wrap autoblogger-metrics">
	<h1><?php esc_html_e( 'AutoBlogger — Metrics & Costs', 'autoblogger' ); ?></h1>

	<!-- Budget Overview Cards -->
	<div class="autoblogger-cards">
		<div class="autoblogger-card">
			<h3><?php esc_html_e( 'Monthly Spend', 'autoblogger' ); ?></h3>
			<div class="autoblogger-card-value">
				$<?php echo esc_html( number_format( $monthly_spend, 4 ) ); ?>
			</div>
			<div class="autoblogger-card-sub">
				<?php
				printf(
					/* translators: %s: budget amount */
					esc_html__( 'of $%s budget', 'autoblogger' ),
					esc_html( number_format( $budget, 2 ) )
				);
				?>
			</div>
		</div>

		<div class="autoblogger-card <?php echo $utilization >= 100 ? 'autoblogger-card-danger' : ( $utilization >= 80 ? 'autoblogger-card-warning' : '' ); ?>">
			<h3><?php esc_html_e( 'Budget Used', 'autoblogger' ); ?></h3>
			<div class="autoblogger-card-value">
				<?php echo esc_html( number_format( $utilization, 1 ) ); ?>%
			</div>
			<div class="autoblogger-budget-bar">
				<div class="autoblogger-budget-fill" style="width: <?php echo esc_attr( (string) min( 100, $utilization ) ); ?>%"></div>
			</div>
		</div>

		<div class="autoblogger-card">
			<h3><?php esc_html_e( 'Next Generation', 'autoblogger' ); ?></h3>
			<div class="autoblogger-card-value autoblogger-card-value-small">
				<?php
				$next_run = wp_next_scheduled( 'autoblogger_daily_generation' );
				echo false !== $next_run
					? esc_html( wp_date( 'M j, g:i A', $next_run ) )
					: esc_html__( 'Not scheduled', 'autoblogger' );
				?>
			</div>
		</div>
	</div>

	<!-- Cost by Stage -->
	<h2><?php esc_html_e( 'Cost by Pipeline Stage (This Month)', 'autoblogger' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Stage', 'autoblogger' ); ?></th>
				<th><?php esc_html_e( 'API Calls', 'autoblogger' ); ?></th>
				<th><?php esc_html_e( 'Total Tokens', 'autoblogger' ); ?></th>
				<th><?php esc_html_e( 'Cost (USD)', 'autoblogger' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $stage_breakdown ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No data yet.', 'autoblogger' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $stage_breakdown as $stage => $data ) : ?>
					<tr>
						<td><?php echo esc_html( ucfirst( $stage ) ); ?></td>
						<td><?php echo esc_html( (string) $data['calls'] ); ?></td>
						<td><?php echo esc_html( number_format( $data['tokens'] ) ); ?></td>
						<td>$<?php echo esc_html( number_format( $data['cost'], 4 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<!-- Daily Spend Chart (simple HTML table — upgrade to Chart.js later) -->
	<h2><?php esc_html_e( 'Daily Spend (Last 30 Days)', 'autoblogger' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'autoblogger' ); ?></th>
				<th><?php esc_html_e( 'Cost (USD)', 'autoblogger' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $daily_spend ) ) : ?>
				<tr><td colspan="2"><?php esc_html_e( 'No data yet.', 'autoblogger' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( array_reverse( $daily_spend, true ) as $date => $cost ) : ?>
					<tr>
						<td><?php echo esc_html( $date ); ?></td>
						<td>$<?php echo esc_html( number_format( $cost, 4 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
