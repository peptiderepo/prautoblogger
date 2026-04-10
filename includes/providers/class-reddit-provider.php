<?php
declare(strict_types=1);

/**
 * Reddit API integration for collecting posts and comments from target subreddits.
 *
 * Uses Reddit's OAuth2 "script" app type for server-to-server authentication.
 * Collects hot/top posts and their top comments from configured subreddits,
 * storing them in the ab_source_data table.
 *
 * Triggered by: Source_Collector calls collect_data() on each enabled provider.
 * Dependencies: Autoblogger_Reddit_API_Client (for HTTP calls and auth).
 *
 * @see interface-source-provider.php        — Interface this class implements.
 * @see providers/class-reddit-api-client.php — HTTP client for Reddit API.
 * @see core/class-source-collector.php       — Orchestrates collection from all providers.
 * @see ARCHITECTURE.md                       — External API integrations table.
 */
class Autoblogger_Reddit_Provider implements Autoblogger_Source_Provider_Interface {

	/**
	 * Collect posts and comments from configured subreddits.
	 *
	 * For each subreddit: fetches hot posts, then fetches top comments for
	 * the most-engaged posts. Returns an array of Source_Data objects.
	 *
	 * Side effects: HTTP requests to Reddit API, uses/sets transients for OAuth token.
	 *
	 * @param array{
	 *     subreddits?: string[],
	 *     limit?: int,
	 *     time_filter?: string,
	 *     include_comments?: bool,
	 *     comments_per_post?: int,
	 * } $config Collection configuration.
	 *
	 * @return Autoblogger_Source_Data[]
	 *
	 * @throws \RuntimeException On authentication failure or API error.
	 */
	public function collect_data( array $config ): array {
		$api_client = new Autoblogger_Reddit_API_Client();
		$token = $api_client->get_access_token();
		if ( '' === $token ) {
			throw new \RuntimeException(
				__( 'Reddit authentication failed. Check client ID and secret in settings.', 'autoblogger' )
			);
		}

		$subreddits       = $config['subreddits'] ?? $this->get_configured_subreddits();
		$limit            = $config['limit'] ?? 25;
		$time_filter      = $config['time_filter'] ?? 'day';
		$include_comments = $config['include_comments'] ?? true;
		$comments_limit   = $config['comments_per_post'] ?? 10;

		$collected = [];

		foreach ( $subreddits as $subreddit ) {
			$subreddit = sanitize_text_field( $subreddit );

			try {
				$posts = $api_client->fetch_posts( $token, $subreddit, $limit, $time_filter );

				foreach ( $posts as $post ) {
					$collected[] = new Autoblogger_Source_Data( [
						'source_type'   => 'reddit',
						'source_id'     => 't3_' . $post['id'],
						'subreddit'     => $subreddit,
						'title'         => $post['title'] ?? '',
						'content'       => $post['selftext'] ?? '',
						'author'        => $post['author'] ?? '[deleted]',
						'score'         => (int) ( $post['score'] ?? 0 ),
						'comment_count' => (int) ( $post['num_comments'] ?? 0 ),
						'permalink'     => 'https://reddit.com' . ( $post['permalink'] ?? '' ),
						'collected_at'  => current_time( 'mysql' ),
						'metadata'      => [
							'post_type'      => $post['is_self'] ? 'self' : 'link',
							'flair'          => $post['link_flair_text'] ?? null,
							'created_utc'    => $post['created_utc'] ?? 0,
							'upvote_ratio'   => $post['upvote_ratio'] ?? 0,
							'is_original'    => $post['is_original_content'] ?? false,
						],
					] );

					// Collect top comments for high-engagement posts.
					if ( $include_comments && ( $post['num_comments'] ?? 0 ) > 5 ) {
						$comments = $api_client->fetch_comments(
							$token,
							$subreddit,
							$post['id'],
							$comments_limit
						);

						foreach ( $comments as $comment ) {
							$collected[] = new Autoblogger_Source_Data( [
								'source_type'   => 'reddit',
								'source_id'     => 't1_' . $comment['id'],
								'subreddit'     => $subreddit,
								'title'         => null,
								'content'       => $comment['body'] ?? '',
								'author'        => $comment['author'] ?? '[deleted]',
								'score'         => (int) ( $comment['score'] ?? 0 ),
								'comment_count' => 0,
								'permalink'     => 'https://reddit.com' . ( $comment['permalink'] ?? '' ),
								'collected_at'  => current_time( 'mysql' ),
								'metadata'      => [
									'parent_post_id' => 't3_' . $post['id'],
									'parent_title'   => $post['title'] ?? '',
									'is_comment'     => true,
									'created_utc'    => $comment['created_utc'] ?? 0,
								],
							] );
						}
					}
				}
			} catch ( \Exception $e ) {
				// Log but continue with other subreddits.
				Autoblogger_Logger::instance()->error(
					sprintf( 'Reddit: Failed to collect from r/%s: %s', $subreddit, $e->getMessage() ),
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
	 * Validate Reddit API credentials.
	 *
	 * Side effects: HTTP request to Reddit OAuth endpoint.
	 *
	 * @return bool
	 */
	public function validate_credentials(): bool {
		$api_client = new Autoblogger_Reddit_API_Client();
		$token = $api_client->get_access_token( true );
		return '' !== $token;
	}

	/**
	 * Get current rate limit status from last response headers.
	 *
	 * @return array{remaining: int, limit: int, resets_at: string}
	 */
	public function get_rate_limit_status(): array {
		// Reddit rate limits are tracked per-response via headers.
		// We store the last seen values in a transient.
		$status = get_transient( 'autoblogger_reddit_rate_limit' );
		if ( is_array( $status ) ) {
			return $status;
		}
		return [
			'remaining' => 100,
			'limit'     => 100,
			'resets_at' => '',
		];
	}


	/**
	 * Get the list of subreddits configured in plugin settings.
	 *
	 * @return string[]
	 */
	private function get_configured_subreddits(): array {
		$json = get_option( 'autoblogger_target_subreddits', '[]' );
		$list = json_decode( $json, true );
		return is_array( $list ) ? $list : [];
	}
}
