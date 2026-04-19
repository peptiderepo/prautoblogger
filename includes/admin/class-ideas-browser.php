<?php
declare(strict_types=1);

/**
 * Admin page listing analysis results (article ideas) from all pipeline runs.
 *
 * Shows every idea the content analyzer has surfaced: topic, type, relevance
 * score, frequency, source, and metadata (key points, keywords). Filterable
 * by type and source, with pagination. Gives the CEO a live view of the idea
 * pipeline without needing to trigger a generation run.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu`.
 * Dependencies: WordPress $wpdb for direct table queries.
 *
 * @see core/class-content-analyzer.php       — Produces the rows this page displays.
 * @see models/class-analysis-result.php      — Data model for analysis results.
 * @see templates/admin/ideas-browser.php     — Renders the HTML.
 * @see ARCHITECTURE.md                       — Data flow step 2.
 */
class PRAutoBlogger_Ideas_Browser {

	private const PER_PAGE = 30;

	/**
	 * Register the Ideas submenu page under PRAutoBlogger.
	 *
	 * @return void
	 */
	public function on_register_menu(): void {
		add_submenu_page(
			'prautoblogger-settings',
			__( 'Ideas', 'prautoblogger' ),
			__( 'Ideas', 'prautoblogger' ),
			'manage_options',
			'prautoblogger-ideas',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the ideas browser page.
	 *
	 * Reads filter/pagination parameters from $_GET, queries the analysis_results
	 * table, and includes the template.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$paged       = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$type_filter = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		$source_filter = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '';

		$result      = $this->query_ideas( $paged, $type_filter, $source_filter );
		$rows        = $result['rows'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / self::PER_PAGE );
		$type_counts = $this->get_type_counts();

		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/ideas-browser.php';
	}

	/**
	 * Query analysis results with optional filtering and pagination.
	 *
	 * Side effects: database SELECT.
	 *
	 * @param int    $paged  Page number (1-based).
	 * @param string $type   Filter by analysis_type (empty = all).
	 * @param string $source Filter: 'llm_research' or 'reddit' based on source_ids content.
	 * @return array{rows: array[], total: int}
	 */
	private function query_ideas( int $paged, string $type, string $source ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'prautoblogger_analysis_results';
		$where  = [];
		$params = [];

		if ( '' !== $type ) {
			$where[]  = 'analysis_type = %s';
			$params[] = $type;
		}

		$where_sql = empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );
		$offset    = ( $paged - 1 ) * self::PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$total = (int) $wpdb->get_var(
			empty( $params )
				? "SELECT COUNT(*) FROM {$table} {$where_sql}"
				: $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$params )
		);

		$order_sql = 'ORDER BY analyzed_at DESC, relevance_score DESC';
		$limit_sql = sprintf( 'LIMIT %d OFFSET %d', self::PER_PAGE, $offset );
		$full_sql  = "SELECT * FROM {$table} {$where_sql} {$order_sql} {$limit_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = empty( $params )
			? $wpdb->get_results( $full_sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $full_sql, ...$params ), ARRAY_A );

		return [
			'rows'  => $rows ?: [],
			'total' => $total,
		];
	}

	/**
	 * Get counts per analysis_type for the filter badges.
	 *
	 * Side effects: database SELECT.
	 *
	 * @return array<string, int> Map of analysis_type => count.
	 */
	private function get_type_counts(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_analysis_results';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			"SELECT analysis_type, COUNT(*) AS cnt FROM {$table} GROUP BY analysis_type ORDER BY cnt DESC",
			ARRAY_A
		);

		$counts = [];
		foreach ( $rows ?: [] as $row ) {
			$counts[ $row['analysis_type'] ] = (int) $row['cnt'];
		}
		return $counts;
	}
}
