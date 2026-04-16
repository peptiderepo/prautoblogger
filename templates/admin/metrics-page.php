<?php
/**
 * Metrics & cost dashboard template.
 *
 * @see admin/class-metrics-page.php — Renders this template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cost_reporter = new PRAutoBlogger_Cost_Reporter();
$monthly_spend = $cost_reporter->get_monthly_spend();
$budget        = (float) get_option( 'prautoblogger_monthly_budget_usd', 50.00 );
$utilization   = $cost_reporter->get_budget_utilization();
$daily_spend   = $cost_reporter->get_daily_spend( 30 );

$first_of_month = gmdate( 'Y-m-01' );
$today          = gmdate( 'Y-m-d' );
$stage_breakdown = $cost_reporter->get_spend_by_stage( $first_of_month, $today );
?>
<div class="wrap prautoblogger-metrics">
	<h1><?php esc_html_e( 'PRAutoBlogger — Metrics & Costs', 'prautoblogger' ); ?></h1>

	<!-- Budget Overview Cards -->
	<div class="prautoblogger-cards">
		<div class="prautoblogger-card">
			<h3><?php esc_html_e( 'Monthly Spend', 'prautoblogger' ); ?></h3>
			<div class="prautoblogger-card-value">
				$<?php echo esc_html( number_format( $monthly_spend, 4 ) ); ?>
			</div>
			<div class="prautoblogger-card-sub">
				<?php
				printf(
					/* translators: %s: budget amount */
					esc_html__( 'of $%s budget', 'prautoblogger' ),
					esc_html( number_format( $budget, 2 ) )
				);
				?>
			</div>
		</div>

		<div class="prautoblogger-card <?php echo $utilization >= 100 ? 'prautoblogger-card-danger' : ( $utilization >= 80 ? 'prautoblogger-card-warning' : '' ); ?>">
			<h3><?php esc_html_e( 'Budget Used', 'prautoblogger' ); ?></h3>
			<div class="prautoblogger-card-value">
				<?php echo esc_html( number_format( $utilization, 1 ) ); ?>%
			</div>
			<div class="prautoblogger-budget-bar">
				<div class="prautoblogger-budget-fill" style="width: <?php echo esc_attr( (string) min( 100, $utilization ) ); ?>%"></div>
			</div>
		</div>

		<div class="prautoblogger-card">
			<h3><?php esc_html_e( 'Next Generation', 'prautoblogger' ); ?></h3>
			<div class="prautoblogger-card-value prautoblogger-card-value-small">
				<?php
				$next_run = wp_next_scheduled( 'prautoblogger_daily_generation' );
				echo false !== $next_run
					? esc_html( wp_date( 'M j, g:i A', $next_run ) )
					: esc_html__( 'Not scheduled', 'prautoblogger' );
				?>
			</div>
		</div>
	</div>

	<!-- Cost by Stage -->
	<h2><?php esc_html_e( 'Cost by Pipeline Stage (This Month)', 'prautoblogger' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Stage', 'prautoblogger' ); ?></th>
				<th><?php esc_html_e( 'API Calls', 'prautoblogger' ); ?></th>
				<th><?php esc_html_e( 'Total Tokens', 'prautoblogger' ); ?></th>
				<th><?php esc_html_e( 'Cost (USD)', 'prautoblogger' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $stage_breakdown ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No data yet.', 'prautoblogger' ); ?></td></tr>
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
	<h2><?php esc_html_e( 'Daily Spend (Last 30 Days)', 'prautoblogger' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'prautoblogger' ); ?></th>
				<th><?php esc_html_e( 'Cost (USD)', 'prautoblogger' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $daily_spend ) ) : ?>
				<tr><td colspan="2"><?php esc_html_e( 'No data yet.', 'prautoblogger' ); ?></td></tr>
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
