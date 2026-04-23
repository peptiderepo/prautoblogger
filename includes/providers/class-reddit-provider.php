<?php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
declare(strict_types=1);

/**
 * Reddit data provider — collects posts and comments from target subreddits.
 *
 * Uses Reddit RSS/Atom feeds as the PRIMARY data source (reliable from datacenter IPs,
 * no auth required, no rate-limit headers). Falls back to Reddit .json endpoints if
 * RSS fails. PullPush.io was removed because its index was frequently stale/unavailable.
 *
 * Triggered by: Source_Collector calls collect_data() on each enabled provider.
 * Dependencies: PRAutoBlogger_Reddit_JSON_Client (handles both RSS and .json).
 *
 * @see providers/class-reddit-json-client.php — HTTP client for RSS and .json endpoints.
 * @see core/class-source-collector.php        — Orchestrates collection from all providers.
 * @see ARCHITECTURE.md                        — External API integrations table.
 */
class PRAutoBlogger_Reddit_Provider implements PRAutoBlogger_Source_Provider_Interface {

	/**
	 * Collect posts and comments from configured subreddits.
	 *
	 * Strategy: RSS first (works reliably from Hostinger datacenter IPs).
	 * If RSS fails, falls back to .json endpoints. Comment fetching always
	 * uses .json since comments are not available in RSS feeds.
	 *
	 * For each subreddit: fetches posts via RSS, then fetches top comments
	 * for high-engagement posts via .json. Returns Source_Data objects.
	 *
	 * Side effects: HTTP requests to Reddit, uses/sets transients.
	 *
	 * @param array{
	 *   subreddits?: string[],
	 *   limit?: int,
	 *   time_filter?: string,
	 *   include_comments?: bool,
	 *   comments_per_post?: int,
	 * } $config Collection configuration.
	 *
	 * @return PRAutoBlogger_Source_Data[]
	 */
	public function collect_data( array $config ): array {
		$subreddits       = $config['subreddits'] ?? $this->get_configured_subreddits();
		$limit            = $config['limit'] ?? 25;
		$time_filter      = $config['time_filter'] ?? 'day';
		$include_comments = $config['include_comments'] ?? true;
		$comments_limit   = $config['comments_per_post'] ?? 5;

		$client    = new PRAutoBlogger_Reddit_JSON_Client();
		$collected = array();

		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Reddit collect_data: %d subreddits=%s, limit=%d, time=%s',
				count( $subreddits ),
				wp_json_encode( $subreddits ),
				$limit,
				$time_filter
			),
			'reddit'
		);

		foreach ( $subreddits as $subreddit ) {
			$subreddit = sanitize_text_field( $subreddit );

			try {
				$before_count = count( $collected );

				// Fetch posts — client tries RSS first, .json fallback internally.
				$posts = $client->fetch_posts( $subreddit, $limit, $time_filter );

				foreach ( $posts as $post ) {
					$data_source = $post['data_source'] ?? 'reddit';

					$collected[] = new PRAutoBlogger_Source_Data(
						array(
							'source_type'   => 'reddit',
							'source_id'     => 't3_' . ( $post['id'] ?? '' ),
							'subreddit'     => $subreddit,
							'title'         => $post['title'] ?? '',
							'content'       => $post['selftext'] ?? '',
							'author'        => $post['author'] ?? '[deleted]',
							'score'         => (int) ( $post['score'] ?? 0 ),
							'comment_count' => (int) ( $post['num_comments'] ?? 0 ),
							'permalink'     => 'https://reddit.com' . ( $post['permalink'] ?? '' ),
							'collected_at'  => current_time( 'mysql' ),
							'metadata'      => array(
								'post_type'    => ( $post['is_self'] ?? false ) ? 'self' : 'link',
								'flair'        => $post['link_flair_text'] ?? null,
								'created_utc'  => $post['created_utc'] ?? null,
								'upvote_ratio' => $post['upvote_ratio'] ?? null,
								'is_original'  => $post['is_original_content'] ?? false,
								'data_source'  => $data_source,
							),
						)
					);

					// Fetch comments for high-engagement posts via .json
					// (comments are not available in RSS feeds).
					if ( $include_comments && ( $post['num_comments'] ?? 0 ) > 0 ) {
						$post_id  = $post['id'] ?? '';
						$comments = $client->fetch_comments( $subreddit, $post_id, $comments_limit );

						foreach ( $comments as $comment ) {
							$collected[] = new PRAutoBlogger_Source_Data(
								array(
									'source_type'   => 'reddit',
									'source_id'     => 't1_' . ( $comment['id'] ?? '' ),
									'subreddit'     => $subreddit,
									'title'         => null,
									'content'       => $comment['body'] ?? '',
									'author'        => $comment['author'] ?? '[deleted]',
									'score'         => (int) ( $comment['score'] ?? 0 ),
									'comment_count' => 0,
									'permalink'     => 'https://reddit.com' . ( $comment['permalink'] ?? '' ),
									'collected_at'  => current_time( 'mysql' ),
									'metadata'      => array(
										'parent_post_id' => 't3_' . $post_id,
										'parent_title'   => $post['title'] ?? '',
										'is_comment'     => true,
										'created_utc'    => $comment['created_utc'] ?? null,
										'data_source'    => 'reddit_json',
									),
								)
							);
						}
					}
				}

				$added = count( $collected ) - $before_count;
				PRAutoBlogger_Logger::instance()->info(
					sprintf( 'Reddit r/%s: fetched %d items', $subreddit, $added ),
					'reddit'
				);
			} catch ( \Throwable $e ) {
				PRAutoBlogger_Logger::instance()->error(
					sprintf(
						'Reddit: %s collecting from r/%s: %s',
						get_class( $e ),
						$subreddit,
						$e->getMessage()
					),
					'reddit'
				);
			}
		}

		return $collected;
	}

	/**
	 * @return string
	 */
	public function get_source_type(): string {
		return 'reddit';
	}

	/**
	 * Validate that at least one data source is reachable.
	 *
	 * No credentials needed — RSS and .json are both unauthenticated.
	 * We just check if either endpoint is responding.
	 *
	 * Side effects: HTTP requests to Reddit.
	 *
	 * @return bool True if at least one data source is available.
	 */
	public function validate_credentials(): bool {
		$client = new PRAutoBlogger_Reddit_JSON_Client();
		return $client->is_available();
	}

	/**
	 * Get current rate limit status from the Reddit client.
	 *
	 * @return array{remaining: int, limit: int, resets_at: string}
	 */
	public function get_rate_limit_status(): array {
		$client = new PRAutoBlogger_Reddit_JSON_Client();
		return $client->get_rate_limit_status();
	}

	/**
	 * Get the list of subreddits configured in plugin settings.
	 *
	 * @return string[]
	 */
	private function get_configured_subreddits(): array {
		$json = get_option( 'prautoblogger_target_subreddits', '' );
		$list = json_decode( $json, true );
		return is_array( $list ) ? $list : array();
	}
}
