<?php
declare(strict_types=1);

/**
 * Collects post performance metrics from WordPress native data and Google Analytics 4.
 *
 * Runs on a separate cron schedule (every 6 hours) to gather pageviews, bounce rate,
 * time on page, and comments for PRAutoBlogger-generated posts. Computes a composite
 * "content score" that feeds back into the analysis engine for self-improvement.
 *
 * Triggered by: WP-Cron hook `prautoblogger_collect_metrics` (every 6 hours).
 * Dependencies: WordPress $wpdb, PRAutoBlogger_GA4_Client (for GA4 API calls).
 *
 * @see core/class-ga4-client.php       — Handles GA4 API authentication and requests.
 * @see core/class-content-analyzer.php — Reads scores for self-improvement loop.
 * @see admin/class-metrics-page.php    — Displays collected metrics.
 * @see ARCHITECTURE.md                 — Data flow step 7.
 */
class PRAutoBlogger_Metrics_Collector {

	/**
	 * Collect metrics for all PRAutoBlogger-generated posts.
	 *
	 * Gathers WordPress native metrics (comments) and GA4 data (if configured),
	 * then computes composite scores.
	 *
	 * Side effects: GA4 API calls (if configured), database writes.
	 *
	 * @return int Number of posts scored.
	 */
	public function collect_all(): int {
		$posts = $this->get_generated_posts();
		if ( empty( $posts ) ) {
			return 0;
		}

		$ga4_client = new PRAutoBlogger_GA4_Client();
		$ga4_data   = $ga4_client->fetch_data( $posts );
		$scored     = 0;

		foreach ( $posts as $post ) {
			$post_id    = (int) $post->ID;
			$wp_metrics = $this->get_wp_native_metrics( $post_id );

			// Skip posts that were deleted between the query and metrics collection.
			if ( null === $wp_metrics ) {
				continue;
			}

			$ga_metrics = $ga4_data[ $post_id ] ?? array();

			$score = $this->compute_composite_score( $wp_metrics, $ga_metrics, $post );
			$this->store_score( $post_id, $wp_metrics, $ga_metrics, $score );
			++$scored;
		}

		PRAutoBlogger_Logger::instance()->info( sprintf( 'Metrics collected for %d posts.', $scored ), 'metrics' );
		return $scored;
	}

	/**
	 * Get all PRAutoBlogger-generated published posts.
	 *
	 * @return \WP_Post[]
	 */
	private function get_generated_posts(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'meta_key'       => '_prautoblogger_generated',
				'meta_value'     => '1',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return $query->posts;
	}

	/**
	 * Get WordPress native metrics for a post.
	 *
	 * @param int $post_id
	 *
	 * @return array{comment_count: int, days_published: int}|null Null if post no longer exists.
	 */
	private function get_wp_native_metrics( int $post_id ): ?array {
		$post = get_post( $post_id );

		// Post may have been deleted between the WP_Query and this call.
		if ( null === $post ) {
			PRAutoBlogger_Logger::instance()->debug( sprintf( 'Metrics: Post ID %d no longer exists, skipping.', $post_id ), 'metrics' );
			return null;
		}

		return array(
			'comment_count'  => (int) ( $post->comment_count ?? 0 ),
			'days_published' => max( 1, (int) ( ( time() - strtotime( $post->post_date ) ) / DAY_IN_SECONDS ) ),
		);
	}


	/**
	 * Compute a composite content score (0-100) from multiple metrics.
	 *
	 * @param array    $wp_metrics  WordPress native metrics.
	 * @param array    $ga_metrics  GA4 metrics (may be empty).
	 * @param \WP_Post $post        The post object.
	 *
	 * @return array{score: float, factors: array<string, float>}
	 */
	private function compute_composite_score( array $wp_metrics, array $ga_metrics, \WP_Post $post ): array {
		$factors = array();

		// Comment engagement (0-25 points).
		$comments_per_day    = $wp_metrics['comment_count'] / max( 1, $wp_metrics['days_published'] );
		$factors['comments'] = min( 25.0, $comments_per_day * 25.0 );

		// Pageview performance (0-30 points, if GA4 available).
		if ( isset( $ga_metrics['pageviews'] ) ) {
			$views_per_day        = $ga_metrics['pageviews'] / max( 1, $wp_metrics['days_published'] );
			$factors['pageviews'] = min( 30.0, $views_per_day * 3.0 );
		} else {
			$factors['pageviews'] = 0.0;
		}

		// Time on page (0-25 points, if GA4 available).
		if ( isset( $ga_metrics['avg_time_on_page'] ) ) {
			// 3+ minutes is excellent for a blog post.
			$factors['time_on_page'] = min( 25.0, ( $ga_metrics['avg_time_on_page'] / 180.0 ) * 25.0 );
		} else {
			$factors['time_on_page'] = 0.0;
		}

		// Low bounce rate bonus (0-20 points, if GA4 available).
		if ( isset( $ga_metrics['bounce_rate'] ) ) {
			// Lower bounce rate = better. 40% or below is excellent.
			$factors['bounce_rate'] = max( 0.0, 20.0 - ( $ga_metrics['bounce_rate'] * 20.0 ) );
		} else {
			$factors['bounce_rate'] = 0.0;
		}

		$total = array_sum( $factors );

		return array(
			'score'   => round( $total, 2 ),
			'factors' => $factors,
		);
	}

	/**
	 * Store a content score in the database.
	 *
	 * @param int   $post_id
	 * @param array $wp_metrics
	 * @param array $ga_metrics
	 * @param array $score_data  {score: float, factors: array}.
	 *
	 * @return void
	 */
	private function store_score( int $post_id, array $wp_metrics, array $ga_metrics, array $score_data ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_content_scores';

		$entry = new PRAutoBlogger_Content_Score(
			array(
				'post_id'          => $post_id,
				'pageviews'        => $ga_metrics['pageviews'] ?? 0,
				'avg_time_on_page' => $ga_metrics['avg_time_on_page'] ?? 0.0,
				'bounce_rate'      => $ga_metrics['bounce_rate'] ?? 0.0,
				'comment_count'    => $wp_metrics['comment_count'] ?? 0,
				'composite_score'  => $score_data['score'],
				'score_factors'    => $score_data['factors'],
				'measured_at'      => current_time( 'mysql' ),
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $entry->to_db_row() );
	}
}
