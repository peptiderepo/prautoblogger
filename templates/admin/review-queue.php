<?php
/**
 * Review queue template — lists generated drafts awaiting editorial approval.
 *
 * Variables available from Autoblogger_Review_Queue::render_page():
 *   $query (WP_Query) — the pending draft posts.
 *
 * @see admin/class-review-queue.php — Renders this template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bulk_done = isset( $_GET['autoblogger_bulk_done'] ) ? absint( $_GET['autoblogger_bulk_done'] ) : 0;
$bulk_type = isset( $_GET['autoblogger_bulk_type'] ) ? sanitize_text_field( wp_unslash( $_GET['autoblogger_bulk_type'] ) ) : '';
?>
<div class="wrap autoblogger-review-queue">
	<h1><?php esc_html_e( 'AutoBlogger — Review Queue', 'autoblogger' ); ?></h1>

	<?php if ( $bulk_done > 0 && '' !== $bulk_type ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				if ( 'approve' === $bulk_type ) {
					printf(
						/* translators: %d: number of posts */
						esc_html( _n( '%d post published.', '%d posts published.', $bulk_done, 'autoblogger' ) ),
						$bulk_done
					);
				} else {
					printf(
						/* translators: %d: number of posts */
						esc_html( _n( '%d post rejected.', '%d posts rejected.', $bulk_done, 'autoblogger' ) ),
						$bulk_done
					);
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<div id="autoblogger-queue-status" class="autoblogger-status-message hidden"></div>

	<?php if ( ! $query->have_posts() ) : ?>
		<div class="autoblogger-empty-queue">
			<p><?php esc_html_e( 'No generated drafts awaiting review. New posts will appear here after the next generation run.', 'autoblogger' ); ?></p>
		</div>
	<?php else : ?>
		<form method="post" id="autoblogger-review-form">
			<?php wp_nonce_field( 'autoblogger_review_queue_bulk', 'autoblogger_review_nonce' ); ?>

			<div class="autoblogger-queue-toolbar">
				<label>
					<input type="checkbox" id="autoblogger-select-all" />
					<?php esc_html_e( 'Select All', 'autoblogger' ); ?>
				</label>
				<button type="submit" name="autoblogger_bulk_action" value="approve" class="button button-primary">
					<?php esc_html_e( 'Approve Selected', 'autoblogger' ); ?>
				</button>
				<button type="submit" name="autoblogger_bulk_action" value="reject" class="button">
					<?php esc_html_e( 'Reject Selected', 'autoblogger' ); ?>
				</button>
				<span class="autoblogger-queue-count">
					<?php
					printf(
						/* translators: %d: number of drafts */
						esc_html( _n( '%d draft', '%d drafts', $query->found_posts, 'autoblogger' ) ),
						$query->found_posts
					);
					?>
				</span>
			</div>

			<table class="widefat striped autoblogger-queue-table">
				<thead>
					<tr>
						<th class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'autoblogger' ); ?></span></th>
						<th><?php esc_html_e( 'Title', 'autoblogger' ); ?></th>
						<th><?php esc_html_e( 'Topic', 'autoblogger' ); ?></th>
						<th><?php esc_html_e( 'Type', 'autoblogger' ); ?></th>
						<th><?php esc_html_e( 'Editor Verdict', 'autoblogger' ); ?></th>
						<th><?php esc_html_e( 'Quality', 'autoblogger' ); ?></th>
						<th><?php esc_html_e( 'Generated', 'autoblogger' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'autoblogger' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php while ( $query->have_posts() ) : $query->the_post(); ?>
						<?php
						$post_id       = get_the_ID();
						$verdict       = get_post_meta( $post_id, '_autoblogger_editor_verdict', true );
						$topic         = get_post_meta( $post_id, '_autoblogger_topic', true );
						$article_type  = get_post_meta( $post_id, '_autoblogger_article_type', true );
						$quality_score = get_post_meta( $post_id, '_autoblogger_quality_score', true );
						$generated_at  = get_post_meta( $post_id, '_autoblogger_generated_at', true );
						$editor_notes  = get_post_meta( $post_id, '_autoblogger_editor_notes', true );
						$verdict_class = 'autoblogger-verdict-' . sanitize_html_class( $verdict ?: 'pending' );
						?>
						<tr data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
							<td class="check-column">
								<input type="checkbox" name="autoblogger_post_ids[]" value="<?php echo esc_attr( (string) $post_id ); ?>" />
							</td>
							<td>
								<strong><?php the_title(); ?></strong>
								<?php if ( $editor_notes ) : ?>
									<div class="autoblogger-editor-notes"><?php echo esc_html( $editor_notes ); ?></div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $topic ?: '—' ); ?></td>
							<td><?php echo esc_html( ucfirst( $article_type ?: 'article' ) ); ?></td>
							<td><span class="<?php echo esc_attr( $verdict_class ); ?>"><?php echo esc_html( ucfirst( $verdict ?: 'pending' ) ); ?></span></td>
							<td><?php echo '' !== $quality_score ? esc_html( $quality_score . '/10' ) : '—'; ?></td>
							<td><?php echo $generated_at ? esc_html( wp_date( 'M j, g:i A', strtotime( $generated_at ) ) ) : '—'; ?></td>
							<td class="autoblogger-queue-actions">
								<button type="button" class="button button-small button-primary autoblogger-approve-btn" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
									<?php esc_html_e( 'Approve', 'autoblogger' ); ?>
								</button>
								<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'autoblogger' ); ?>
								</a>
								<a href="<?php echo esc_url( get_preview_post_link( $post_id ) ); ?>" class="button button-small" target="_blank">
									<?php esc_html_e( 'Preview', 'autoblogger' ); ?>
								</a>
								<button type="button" class="button button-small autoblogger-reject-btn" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
									<?php esc_html_e( 'Reject', 'autoblogger' ); ?>
								</button>
							</td>
						</tr>
					<?php endwhile; ?>
					<?php wp_reset_postdata(); ?>
				</tbody>
			</table>
		</form>

		<?php
		// Pagination.
		$total_pages = $query->max_num_pages;
		if ( $total_pages > 1 ) :
			$current_page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
			echo '<div class="autoblogger-pagination">';
			echo wp_kses_post( paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $current_page,
				'total'     => $total_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			] ) );
			echo '</div>';
		endif;
		?>
	<?php endif; ?>
</div>
