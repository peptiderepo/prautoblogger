<?php
/**
 * Log viewer template — displays structured application log entries.
 *
 * Variables from Autoblogger_Log_Viewer::render_page():
 *   $rows        — array of log entry rows.
 *   $total       — total matching entries.
 *   $total_pages — total pages.
 *   $level       — current level filter.
 *   $search      — current search string.
 *   $paged       — current page number.
 *
 * @see admin/class-log-viewer.php — Renders this template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$base_url    = admin_url( 'admin.php?page=autoblogger-logs' );
$level_counts = [
	'all'     => __( 'All', 'autoblogger' ),
	'error'   => __( 'Errors', 'autoblogger' ),
	'warning' => __( 'Warnings', 'autoblogger' ),
	'info'    => __( 'Info', 'autoblogger' ),
	'debug'   => __( 'Debug', 'autoblogger' ),
];
?>
<div class="wrap ab-wrap">
	<div class="ab-header">
		<div class="ab-header-left">
			<span class="dashicons dashicons-list-view ab-header-icon"></span>
			<h1 class="ab-header-title"><?php esc_html_e( 'Activity Log', 'autoblogger' ); ?></h1>
		</div>
		<div class="ab-header-actions">
			<button type="button" id="autoblogger-clear-logs" class="ab-btn ab-btn-outline" data-nonce="<?php echo esc_attr( wp_create_nonce( 'autoblogger_clear_logs' ) ); ?>">
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e( 'Clear Logs (30d+)', 'autoblogger' ); ?>
			</button>
		</div>
	</div>

	<div id="autoblogger-log-status" class="hidden"></div>

	<!-- Filters -->
	<div class="ab-log-filters">
		<div class="ab-log-level-tabs">
			<?php foreach ( $level_counts as $lv => $lv_label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( [ 'level' => $lv, 's' => $search, 'paged' => 1 ], $base_url ) ); ?>"
				   class="ab-log-level-tab <?php echo $level === $lv ? 'ab-log-level-active' : ''; ?>">
					<?php echo esc_html( $lv_label ); ?>
				</a>
			<?php endforeach; ?>
		</div>

		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="ab-log-search">
			<input type="hidden" name="page" value="autoblogger-logs" />
			<input type="hidden" name="level" value="<?php echo esc_attr( $level ); ?>" />
			<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search logs...', 'autoblogger' ); ?>" class="ab-input ab-log-search-input" />
			<button type="submit" class="ab-btn ab-btn-outline ab-btn-small"><?php esc_html_e( 'Search', 'autoblogger' ); ?></button>
		</form>
	</div>

	<!-- Results count -->
	<p class="ab-log-count">
		<?php
		printf(
			/* translators: %s: total count */
			esc_html__( '%s entries found', 'autoblogger' ),
			'<strong>' . esc_html( number_format( $total ) ) . '</strong>'
		);
		?>
	</p>

	<?php if ( empty( $rows ) ) : ?>
		<div class="ab-log-empty">
			<span class="dashicons dashicons-yes-alt"></span>
			<p><?php esc_html_e( 'No log entries match your filters.', 'autoblogger' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped ab-log-table">
			<thead>
				<tr>
					<th class="ab-log-col-level"><?php esc_html_e( 'Level', 'autoblogger' ); ?></th>
					<th class="ab-log-col-time"><?php esc_html_e( 'Time', 'autoblogger' ); ?></th>
					<th class="ab-log-col-context"><?php esc_html_e( 'Context', 'autoblogger' ); ?></th>
					<th><?php esc_html_e( 'Message', 'autoblogger' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $lvl = $row['level'] ?? 'info'; ?>
					<tr class="ab-log-row ab-log-row-<?php echo esc_attr( $lvl ); ?>">
						<td class="ab-log-col-level">
							<span class="ab-log-badge ab-log-badge-<?php echo esc_attr( $lvl ); ?>">
								<?php echo esc_html( strtoupper( $lvl ) ); ?>
							</span>
						</td>
						<td class="ab-log-col-time">
							<?php echo esc_html( wp_date( 'M j, g:i:s A', strtotime( $row['created_at'] ) ) ); ?>
						</td>
						<td class="ab-log-col-context">
							<?php echo esc_html( $row['context'] ?? '' ); ?>
						</td>
						<td>
							<?php echo esc_html( $row['message'] ?? '' ); ?>
							<?php if ( ! empty( $row['meta_json'] ) ) : ?>
								<button type="button" class="ab-log-meta-toggle" title="<?php esc_attr_e( 'Show details', 'autoblogger' ); ?>">
									<span class="dashicons dashicons-arrow-down-alt2"></span>
								</button>
								<pre class="ab-log-meta"><?php echo esc_html( wp_json_encode( json_decode( $row['meta_json'], true ), JSON_PRETTY_PRINT ) ); ?></pre>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="ab-log-pagination">
				<?php
				echo wp_kses_post( paginate_links( [
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				] ) );
				?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
