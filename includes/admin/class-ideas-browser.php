<?php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
declare(strict_types=1);

/**
 * Admin page listing analysis results (article ideas) with per-idea generation.
 *
 * Triggered by: PRAutoBlogger::register_admin_hooks() on `admin_menu`.
 * Dependencies: WordPress $wpdb, Article_Worker, Cost_Tracker, Generation_Lock.
 *
 * @see core/class-article-worker.php         — Generates articles from ideas.
 * @see templates/admin/ideas-browser.php     — Renders the HTML.
 */
class PRAutoBlogger_Ideas_Browser {

	private const PER_PAGE = 30;

	/** Transient prefix for per-idea generation status. */
	private const STATUS_PREFIX = 'prab_idea_gen_';

	/** How long to keep per-idea status (seconds). */
	private const STATUS_TTL = 600;

	/** Register the Ideas submenu page under PRAutoBlogger. */
	public function on_register_menu(): void {
		add_submenu_page(
			'prautoblogger-settings',
			__( 'Ideas', 'prautoblogger' ),
			__( 'Ideas', 'prautoblogger' ),
			'manage_options',
			'prautoblogger-ideas',
			array( $this, 'render_page' )
		);
	}

	/** Render the ideas browser page. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$paged       = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$type_filter = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';

		$result      = $this->query_ideas( $paged, $type_filter );
		$rows        = $result['rows'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / self::PER_PAGE );
		$type_counts = $this->get_type_counts();

		include PRAUTOBLOGGER_PLUGIN_DIR . 'templates/admin/ideas-browser.php';
	}

	/**
	 * AJAX: trigger article generation from a specific idea.
	 *
	 * Loads the analysis result, stores it as a transient, sets a per-idea
	 * status transient, and schedules a one-shot cron event so the actual
	 * generation runs in a separate PHP process (Hostinger 120s limit).
	 *
	 * Side effects: transient writes, cron scheduling.
	 */
	public function on_ajax_generate_from_idea(): void {
		check_ajax_referer( 'prautoblogger_idea_gen', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
			return;
		}

		$idea_id = absint( $_POST['idea_id'] ?? 0 );
		if ( $idea_id < 1 ) {
			wp_send_json_error( array( 'message' => 'Invalid idea ID.' ) );
			return;
		}

		// Build Article_Idea from the analysis row.
		$idea_data = self::load_idea_data( $idea_id );
		if ( null === $idea_data ) {
			wp_send_json_error( array( 'message' => 'Idea not found.' ) );
			return;
		}

		// Store idea for the cron handler and set initial "running" status.
		set_transient( self::STATUS_PREFIX . 'data_' . $idea_id, $idea_data, self::STATUS_TTL );
		set_transient(
			self::STATUS_PREFIX . $idea_id,
			array(
				'status'  => 'running',
				'stage'   => __( 'Starting generation…', 'prautoblogger' ),
				'started' => time(),
			),
			self::STATUS_TTL
		);

		// Schedule background cron — passes idea_id as argument.
		$hook = 'prautoblogger_generate_from_idea';
		if ( ! wp_next_scheduled( $hook, array( $idea_id ) ) ) {
			wp_schedule_single_event( time(), $hook, array( $idea_id ) );
		}
		spawn_cron();
		wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
			)
		);

		wp_send_json_success( array( 'message' => 'Generation started.' ) );
	}

	/**
	 * AJAX: return per-idea generation status for frontend polling.
	 *
	 * Side effects: none (reads transient only).
	 */
	public function on_ajax_idea_gen_status(): void {
		check_ajax_referer( 'prautoblogger_idea_gen', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
			return;
		}

		$idea_id = absint( $_POST['idea_id'] ?? 0 );
		$status  = get_transient( self::STATUS_PREFIX . $idea_id );
		if ( ! is_array( $status ) ) {
			wp_send_json_success( array( 'status' => 'idle' ) );
			return;
		}

		wp_send_json_success( $status );
	}

	/**
	 * Cron handler: generate a single article from a stored idea.
	 *
	 * Runs in a background PHP process. Acquires the generation lock,
	 * runs Article_Worker, and writes the result to the per-idea status transient.
	 *
	 * Side effects: LLM API calls, DB writes, post creation, cost logging.
	 *
	 * @param int $idea_id Analysis result row ID.
	 */
	public static function on_cron_generate_from_idea( int $idea_id ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ignore_user_abort( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		$key       = self::STATUS_PREFIX . $idea_id;
		$idea_data = get_transient( self::STATUS_PREFIX . 'data_' . $idea_id );
		if ( ! is_array( $idea_data ) ) {
			set_transient(
				$key,
				array(
					'status'  => 'error',
					'message' => 'Idea data expired.',
				),
				self::STATUS_TTL
			);
			return;
		}

		if ( ! PRAutoBlogger_Generation_Lock::acquire() ) {
			set_transient(
				$key,
				array(
					'status'  => 'error',
					'message' => 'Another generation is running.',
				),
				self::STATUS_TTL
			);
			return;
		}

		try {
			$idea         = new PRAutoBlogger_Article_Idea( $idea_data );
			$cost_tracker = new PRAutoBlogger_Cost_Tracker();
			$cost_tracker->set_run_id( wp_generate_uuid4() );

			self::update_idea_stage( $idea_id, __( 'Generating article draft…', 'prautoblogger' ) );
			$worker = new PRAutoBlogger_Article_Worker( $cost_tracker );
			$result = $worker->generate( $idea );

			// Amortize any research costs for this single-article run.
			PRAutoBlogger_Post_Assembler::amortize_research_costs( $cost_tracker->get_run_id() );

			// Find the generated post ID for the "View" link.
			$post_id = self::find_post_by_run_id( $cost_tracker->get_run_id() );

			set_transient(
				$key,
				array(
					'status'    => 'complete',
					'generated' => $result['generated'],
					'published' => $result['published'],
					'cost'      => $result['cost'],
					'post_id'   => $post_id,
				),
				self::STATUS_TTL
			);
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Idea generation %s for #%d: %s', get_class( $e ), $idea_id, $e->getMessage() ),
				'pipeline'
			);
			set_transient(
				$key,
				array(
					'status'  => 'error',
					'message' => $e->getMessage(),
				),
				self::STATUS_TTL
			);
		}

		PRAutoBlogger_Generation_Lock::release();
		delete_transient( self::STATUS_PREFIX . 'data_' . $idea_id );
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/** Update the per-idea status transient with a stage message. */
	private static function update_idea_stage( int $idea_id, string $stage ): void {
		$key     = self::STATUS_PREFIX . $idea_id;
		$current = get_transient( $key );
		if ( is_array( $current ) ) {
			$current['stage'] = $stage;
			set_transient( $key, $current, self::STATUS_TTL );
		}
	}

	/** Find the most recent post created by a specific run_id. */
	private static function find_post_by_run_id( string $run_id ): ?int {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$post_id = $wpdb->get_var(
			$wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT post_id FROM {$table} WHERE run_id = %s AND post_id IS NOT NULL LIMIT 1",
				$run_id
			)
		);
		return null !== $post_id ? (int) $post_id : null;
	}

	/**
	 * Load an analysis result row and map it to Article_Idea constructor data.
	 *
	 * @param int $idea_id Analysis result row ID.
	 * @return array<string, mixed>|null Article_Idea-compatible array, or null.
	 */
	private static function load_idea_data( int $idea_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_analysis_results';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $idea_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$meta = json_decode( $row['metadata_json'] ?? '{}', true ) ?: array();
		return array(
			'topic'           => $row['topic'],
			'article_type'    => $row['analysis_type'],
			'suggested_title' => $meta['suggested_title'] ?? $row['topic'],
			'summary'         => $row['summary'] ?? '',
			'score'           => (float) $row['relevance_score'],
			'analysis_id'     => (int) $row['id'],
			'source_ids'      => json_decode( $row['source_ids_json'] ?? '[]', true ) ?: array(),
			'key_points'      => $meta['key_points'] ?? array(),
			'target_keywords' => $meta['target_keywords'] ?? array(),
		);
	}

	/** Query analysis results with optional filtering and pagination. */
	private function query_ideas( int $paged, string $type ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'prautoblogger_analysis_results';
		$where  = array();
		$params = array();

		if ( '' !== $type ) {
			$where[]  = 'analysis_type = %s';
			$params[] = $type;
		}

		$where_sql = empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );
		$offset    = ( $paged - 1 ) * self::PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$total = (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			empty( $params )  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				? "SELECT COUNT(*) FROM {$table} {$where_sql}"  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$params )
		);

		$order_sql = 'ORDER BY analyzed_at DESC, relevance_score DESC';
		$limit_sql = sprintf( 'LIMIT %d OFFSET %d', self::PER_PAGE, $offset );
		$full_sql  = "SELECT * FROM {$table} {$where_sql} {$order_sql} {$limit_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = empty( $params )  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			? $wpdb->get_results( $full_sql, ARRAY_A )  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_results( $wpdb->prepare( $full_sql, ...$params ), ARRAY_A );

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}

	/** Get counts per analysis_type for the filter badges. */
	private function get_type_counts(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_analysis_results';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT analysis_type, COUNT(*) AS cnt FROM {$table} GROUP BY analysis_type ORDER BY cnt DESC",
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows ?: array() as $row ) {
			$counts[ $row['analysis_type'] ] = (int) $row['cnt'];
		}
		return $counts;
	}
}
