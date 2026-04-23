<?php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
declare(strict_types=1);

/**
 * Daily cron that retroactively amortizes orphan `llm_research` rows.
 *
 * When a pipeline run dies between post creation and the final
 * `Post_Assembler::amortize_research_costs()` call (Hostinger exec kill,
 * OOM, LiteSpeed cut, fatal), the shared research-cost row stays in
 * `wp_prautoblogger_generation_log` with `post_id = NULL` and the
 * articles from that run understate their true cost in the popover.
 *
 * This reaper scans for such orphan rows once a day, finds their sibling
 * posts via the `_prautoblogger_run_id` post_meta (primary) or the
 * gen_log's own post-linked rows (fallback for legacy posts that
 * predate the meta), and delegates to the existing idempotent
 * `amortize_research_costs()` method. Orphans that still have no
 * siblings after 7 days are deleted outright as sunk cost.
 *
 * Spec-wise the CTO asked for the reap method to live on Post_Assembler;
 * hosting it here instead keeps Post_Assembler under the 300-line cap
 * (it grew past 300 with the reap method attached) and colocates the
 * attribution logic next to the cron/CLI plumbing that triggers it.
 *
 * Triggered by:
 * - WP-Cron: `prautoblogger_reap_orphan_research_rows` (daily, 03:15).
 * - WP-CLI:  `wp prautoblogger reap-research` (manual ops trigger).
 * Dependencies: PRAutoBlogger_Post_Assembler (amortize_research_costs),
 *               PRAutoBlogger_Logger, WordPress $wpdb + get_posts.
 *
 * @see core/class-post-assembler.php  — amortize_research_costs() does the work.
 * @see class-activator.php            — Schedules the daily cron.
 * @see class-deactivator.php          — Unschedules on deactivate.
 * @see ARCHITECTURE.md                — Cost tracking data flow.
 */
class PRAutoBlogger_Research_Reaper {

	/** Grace window — live pipelines have this long to finish amortizing before reaping. */
	private const GRACE_WINDOW_SECONDS = 3600; // 1 hour.

	/** Stale window — orphans with no sibling posts are deleted outright after this long. */
	private const STALE_WINDOW_SECONDS = 7 * 86400; // 7 days.

	/**
	 * Cron action handler — entrypoint for the daily WP-Cron event.
	 *
	 * Pure delegate. Never throws.
	 *
	 * @return void
	 */
	public static function on_cron(): void {
		self::reap();
	}

	/**
	 * Scan and reap orphan `llm_research` rows. Returns a summary that the
	 * WP-CLI command prints and the cron path ignores.
	 *
	 * Side effects: SELECT / INSERT / DELETE on generation_log; post_meta
	 *               reads; one INFO log line per reaped or stale-deleted
	 *               orphan; one WARNING on DB error.
	 *
	 * @return array{reaped: int, deleted: int, skipped: int}
	 */
	public static function reap(): array {
		$stats = array(
			'reaped'  => 0,
			'deleted' => 0,
			'skipped' => 0,
		);

		try {
			global $wpdb;
			$log_table = $wpdb->prefix . 'prautoblogger_generation_log';
			$grace     = gmdate( 'Y-m-d H:i:s', time() - self::GRACE_WINDOW_SECONDS );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$orphans = $wpdb->get_results(
				$wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, run_id, created_at FROM {$log_table}
					WHERE stage = 'llm_research' AND post_id IS NULL AND created_at < %s",
					$grace
				)
			);
			if ( ! is_array( $orphans ) ) {
				return $stats;
			}

			$stale_cutoff = time() - self::STALE_WINDOW_SECONDS;
			foreach ( $orphans as $orphan ) {
				self::handle_orphan( $orphan, $stale_cutoff, $log_table, $stats );
			}
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->warning(
				'Reaper encountered DB error: ' . $e->getMessage(),
				'cost-tracker'
			);
		}

		return $stats;
	}

	/**
	 * Route one orphan row: amortize if siblings exist, delete if stale,
	 * otherwise skip and try again next run.
	 *
	 * @param object $orphan       DB row with id, run_id, created_at.
	 * @param int    $stale_cutoff Unix timestamp — orphans older than this delete.
	 * @param string $log_table    Fully-qualified gen_log table name.
	 * @param array  $stats        Counters, mutated by reference.
	 * @return void
	 */
	private static function handle_orphan( object $orphan, int $stale_cutoff, string $log_table, array &$stats ): void {
		global $wpdb;
		$run_id     = (string) ( $orphan->run_id ?? '' );
		$created_ts = strtotime( (string) ( $orphan->created_at ?? '' ) );
		$is_stale   = false !== $created_ts && $created_ts < $stale_cutoff;

		if ( '' === $run_id ) {
			if ( $is_stale ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->delete( $log_table, array( 'id' => (int) $orphan->id ) );
				++$stats['deleted'];
			} else {
				++$stats['skipped'];
			}
			return;
		}

		$post_ids = self::find_posts_for_run_id( $run_id );
		if ( count( $post_ids ) >= 1 ) {
			PRAutoBlogger_Post_Assembler::amortize_research_costs( $run_id );
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Reaped research cost across %d articles for run %s.', count( $post_ids ), $run_id ),
				'cost-tracker'
			);
			++$stats['reaped'];
			return;
		}

		if ( $is_stale ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( $log_table, array( 'id' => (int) $orphan->id ) );
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Deleted 7-day-stale orphan research row for run %s — no articles to attribute.', $run_id ),
				'cost-tracker'
			);
			++$stats['deleted'];
			return;
		}

		++$stats['skipped'];
	}

	/**
	 * Return distinct post IDs for a given run_id. Prefers post_meta;
	 * falls back to gen_log rows for legacy posts.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return int[] Distinct post IDs.
	 */
	private static function find_posts_for_run_id( string $run_id ): array {
		$meta_ids = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'meta_key'         => '_prautoblogger_run_id',
				'meta_value'       => $run_id,
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);
		if ( is_array( $meta_ids ) && ! empty( $meta_ids ) ) {
			return array_map( 'intval', $meta_ids );
		}

		global $wpdb;
		$log_table = $wpdb->prefix . 'prautoblogger_generation_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT post_id FROM {$log_table} WHERE run_id = %s AND post_id IS NOT NULL",
				$run_id
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
