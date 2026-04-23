<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Adds "Writing Model", "Image Model", and "Cost" columns to the Posts list.
 *
 * Shows which LLM wrote each article, which model generated its featured image,
 * and the total API cost of generating the article. Non-generated posts show "—".
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks().
 * Dependencies: Post meta, attachment meta, prab_generation_log table.
 *
 * @see core/class-publisher.php              — Sets _prautoblogger_model_used.
 * @see core/class-image-media-sideloader.php — Sets _prautoblogger_image_model on attachment.
 * @see core/class-cost-tracker.php           — Writes generation_log with estimated_cost.
 * @see class-prautoblogger.php               — Registers hooks for this class.
 */
class PRAutoBlogger_Post_List_Columns {

	/**
	 * Register column and render hooks.
	 *
	 * Side effects: adds WordPress filters/actions for post list customization.
	 */
	public function register(): void {
		add_filter( 'manage_posts_columns', array( $this, 'filter_add_columns' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'on_render_column' ), 10, 2 );
		add_filter( 'manage_edit-post_sortable_columns', array( $this, 'filter_sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'on_sort_by_model' ) );
		add_action( 'admin_head', array( $this, 'on_column_styles' ) );
	}

	/**
	 * Insert three columns after the title column.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string> Modified columns.
	 */
	public function filter_add_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['prab_writing_model'] = __( 'Writing Model', 'prautoblogger' );
				$new['prab_image_model']   = __( 'Image Model', 'prautoblogger' );
				$new['prab_cost']          = __( 'Cost', 'prautoblogger' );
			}
		}
		return $new;
	}

	/**
	 * Render cell content for our custom columns.
	 *
	 * @param string $column  Column identifier.
	 * @param int    $post_id Current post ID.
	 */
	public function on_render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'prab_writing_model':
				$this->render_model_cell(
					(string) get_post_meta( $post_id, '_prautoblogger_model_used', true )
				);
				break;

			case 'prab_image_model':
				$this->render_image_model_cell( $post_id );
				break;

			case 'prab_cost':
				$this->render_cost_cell( $post_id );
				break;
		}
	}

	/**
	 * Make the writing model column sortable.
	 *
	 * @param array<string, string> $columns Sortable column map.
	 * @return array<string, string>
	 */
	public function filter_sortable_columns( array $columns ): array {
		$columns['prab_writing_model'] = 'prab_writing_model';
		return $columns;
	}

	/**
	 * Handle sorting by writing model meta value.
	 *
	 * @param \WP_Query $query The admin posts query.
	 */
	public function on_sort_by_model( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'prab_writing_model' !== $query->get( 'orderby' ) ) {
			return;
		}
		$query->set( 'meta_key', '_prautoblogger_model_used' );
		$query->set( 'orderby', 'meta_value' );
	}

	/**
	 * Output column width styles on the Posts list screen only.
	 */
	public function on_column_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-post' !== $screen->id ) {
			return;
		}
		echo '<style>'
			. '.wp-list-table .column-title{width:35%;word-wrap:break-word;overflow-wrap:break-word}'
			. '.column-prab_writing_model{width:100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
			. '.column-prab_image_model{width:100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
			. '.column-prab_cost{width:55px;white-space:nowrap;overflow:visible}'
			. '.prab-cost-wrap{position:relative;display:inline-block}'
			. '.prab-cost-trigger{font-size:12px;color:#2271b1;text-decoration:underline;cursor:pointer}'
			. '.prab-cost-popover{display:none;position:absolute;right:0;top:100%;z-index:9999;'
			. 'background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.15);'
			. 'padding:8px;min-width:340px;white-space:normal}'
			. '.prab-cost-wrap:hover .prab-cost-popover,'
			. '.prab-cost-trigger:focus+.prab-cost-popover{display:block}'
			. '.prab-cost-table{width:100%;border-collapse:collapse;font-size:12px}'
			. '.prab-cost-table th{text-align:left;border-bottom:2px solid #dcdcde;padding:3px 6px;color:#50575e;font-weight:600}'
			. '.prab-cost-table td{padding:3px 6px;border-bottom:1px solid #f0f0f1;color:#3c434a}'
			. '.prab-cost-table tr:last-child td{border-bottom:none}'
			. '.prab-cost-total td{border-top:2px solid #dcdcde;border-bottom:none}'
			. '</style>';
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Render a model name cell with shortened display and full tooltip.
	 *
	 * @param string $model Full model identifier (may be empty).
	 */
	private function render_model_cell( string $model ): void {
		if ( '' === $model ) {
			echo '—';
			return;
		}
		printf(
			'<span title="%s" style="font-size:12px;color:#666;">%s</span>',
			esc_attr( $model ),
			esc_html( $this->shorten( $model ) )
		);
	}

	/**
	 * Look up the featured image's model and render it.
	 *
	 * @param int $post_id The post ID.
	 */
	private function render_image_model_cell( int $post_id ): void {
		$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		if ( 0 === $thumbnail_id ) {
			echo '—';
			return;
		}
		$model = (string) get_post_meta( $thumbnail_id, '_prautoblogger_image_model', true );
		$this->render_model_cell( $model );
	}

	/**
	 * Fetch generation_log rows for a post, render total with clickable breakdown.
	 *
	 * @param int $post_id The post ID.
	 */
	private function render_cost_cell( int $post_id ): void {
		$is_generated = get_post_meta( $post_id, '_prautoblogger_generated', true );
		if ( '1' !== $is_generated ) {
			echo '—';
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stage, model, prompt_tokens, completion_tokens, estimated_cost  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				FROM {$table} WHERE post_id = %d ORDER BY id ASC",
				$post_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			echo '<span style="font-size:12px;color:#999;">$0.00</span>';
			return;
		}

		$total     = array_sum( array_column( $rows, 'estimated_cost' ) );
		$formatted = $total < 0.01
			? sprintf( '$%.4f', $total )
			: sprintf( '$%.2f', $total );

		$breakdown = $this->build_breakdown_html( $rows, $total );

		printf(
			'<span class="prab-cost-wrap">'
			. '<a href="#" class="prab-cost-trigger" onclick="return false;">%s</a>'
			. '<span class="prab-cost-popover">%s</span>'
			. '</span>',
			esc_html( $formatted ),
			$breakdown
		);
	}

	/**
	 * Build the HTML breakdown table for the cost popover.
	 *
	 * @param array[] $rows  Generation log rows.
	 * @param float   $total Total cost.
	 * @return string HTML table (already escaped).
	 */
	private function build_breakdown_html( array $rows, float $total ): string {
		$html  = '<table class="prab-cost-table">';
		$html .= '<tr><th>Stage</th><th>Model</th><th>Tokens</th><th>Cost</th></tr>';

		foreach ( $rows as $row ) {
			$stage  = esc_html( ucfirst( str_replace( '_', ' ', $row['stage'] ) ) );
			$model  = esc_html( $this->shorten( $row['model'] ) );
			$tokens = (int) $row['prompt_tokens'] + (int) $row['completion_tokens'];
			$cost   = (float) $row['estimated_cost'];
			$cost_f = $cost < 0.01 ? sprintf( '$%.4f', $cost ) : sprintf( '$%.2f', $cost );

			$html .= sprintf(
				'<tr><td>%s</td><td title="%s">%s</td><td>%s</td><td>%s</td></tr>',
				$stage,
				esc_attr( $row['model'] ),
				$model,
				esc_html( number_format( $tokens ) ),
				esc_html( $cost_f )
			);
		}

		$total_f = $total < 0.01 ? sprintf( '$%.4f', $total ) : sprintf( '$%.2f', $total );
		$html   .= sprintf(
			'<tr class="prab-cost-total"><td colspan="3"><strong>Total</strong></td>'
			. '<td><strong>%s</strong></td></tr>',
			esc_html( $total_f )
		);
		$html   .= '</table>';

		return $html;
	}

	/**
	 * Strip provider prefix for compact display.
	 *
	 * @param string $model Full model identifier.
	 * @return string Shortened name.
	 */
	private function shorten( string $model ): string {
		$pos = strpos( $model, '/' );
		return false !== $pos ? substr( $model, $pos + 1 ) : $model;
	}
}
