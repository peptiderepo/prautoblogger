<?php
declare(strict_types=1);

/**
 * Reddit data provider — collects posts and comments from target subreddits.
 *
 * Uses PullPush.io as the PRIMARY data source (free, no auth required, 30 req/min).
 * Falls back to Reddit .json endpoints if PullPush is unavailable.
 * Reddit OAuth was removed because Reddit rejected our API application.
 *
 * Triggered by: Source_Collector calls collect_data() on each enabled provider.
 * Dependencies: PRAutoBlogger_PullPush_Client (primary), PRAutoBlogger_Reddit_JSON_Client (fallback).
 *
 * @see interface-source-provider.php          — Interface this class implements.
 * @see providers/class-pullpush-client.php    — Primary HTTP client (PullPush.io).
 * @see providers/class-reddit-json-client.php — Fallback HTTP client (Reddit .json).
 * @see core/class-source-collector.php        — Orchestrates collection from all providers.
 * @see ARCHITECTURE.md                        — External API integrations table.
 */
class PRAutoBlogger_Reddit_Provider implements PRAutoBlogger_Source_Provider_Interface {

	/**
	 * Transient key for caching PullPush availability check.
	 * Avoids re-checking on every request within a collection run.
	 *
	 * @var string
	 */
	private const PULLPUSH_AVAILABLE_TRANSIENT = 'prautoblogger_pullpush_available';

	/**
	 * How long to cache PullPush availability status (5 minutes).
	 *
	 * @var int
	 */
	private const AVAILABILITY_CACHE_TTL = 300;

	/**
	 * Collect posts and comments from configured subreddits.
	 *
	 * Strategy: Try PullPush first (better API, subreddit-wide comment search).
	 * If PullPush is down, fall back to Reddit .json endpoints (same response
	 * format as the old OAuth API, but unauthenticated and rate-limited).
	 *
	 * For each subreddit: fetches top posts, then fetches top comments for
	 * the most-engaged posts. Returns an array of Source_Data objects.
	 *
	 * Side effects: HTTP requests to PullPush or Reddit, uses/sets transients.
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

		// Determine which client to use.
		$use_pullpush = $this->is_pullpush_available();
		$collected    = [];

		foreach ( $subreddits as $subreddit ) {
			$subreddit = sanitize_text_field( $subreddit );

			try {
				if ( $use_pullpush ) {
					$collected = array_merge(
						$collected,
						$this->collect_from_pullpush( $subreddit, $limit, $time_filter, $include_comments, $comments_limit )
					);
				} else {
					$collected = array_merge(
						$collected,
						$this->collect_from_reddit_json( $subreddit, $limit, $time_filter, $include_comments, $comments_limit )
					);
				}
			} catch ( \Exception $e ) {
				// Log but continue with other subreddits.
				PRAutoBlogger_Logger::instance()->error(
					sprintf(
						'Reddit: Failed to collect from r/%s: %s',
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
	 * No credentials needed anymore — PullPush and Reddit .json are both
	 * unauthenticated. We just check if either endpoint is responding.
	 *
	 * Side effects: HTTP requests to PullPush and/or Reddit.
	 *
	 * @return bool True if at least one data source is available.
	 */
	public function validate_credentials(): bool {
		$pullpush = new PRAutoBlogger_PullPush_Client();
		if ( $pullpush->is_available() ) {
			return true;
		}

		$reddit_json = new PRAutoBlogger_Reddit_JSON_Client();
		return $reddit_json->is_available();
	}

	/**
	 * Get current rate limit status from the active data source.
	 *
	 * @return array{remaining: int, limit: int, resets_at: string}
	 */
	public function get_rate_limit_status(): array {
		if ( $this->is_pullpush_available() ) {
			$client = new PRAutoBlogger_PullPush_Client();
			return $client->get_rate_limit_status();
		}

		$client = new PRAutoBlogger_Reddit_JSON_Client();
		return $client->get_rate_limit_status();
	}

	/**
	 * Collect data from PullPush.io API.
	 *
	 * PullPush returns flat arrays of Reddit objects with standard field names.
	 * We can search comments subreddit-wide (more efficient than per-post).
	 *
	 * @param string $subreddit       Subreddit name.
	 * @param int    $limit           Max posts.
	 * @param string $time_filter     Time window.
	 * @param bool   $include_comments Whether to fetch comments.
	 * @param int    $comments_limit  Max comments per post.
	 *
	 * @return PRAutoBlogger_Source_Data[]
	 */
	private function collect_from_pullpush(
		string $subreddit,
		int $limit,
		string $time_filter,
		bool $include_comments,
		int $comments_limit
	): array {
		$client    = new PRAutoBlogger_PullPush_Client();
		$collected = [];

		// Fetch posts.
		$posts = $client->fetch_posts( $subreddit, $limit, $time_filter );

		foreach ( $posts as $post ) {
			$collected[] = new PRAutoBlogger_Source_Data( [
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
				'metadata'      => [
					'post_type'   => ( $post['is_self'] ?? false ) ? 'self' : 'link',
					'flair'       => $post['link_flair_text'] ?? null,
					'created_utc' => $post['created_utc'] ?? null,
					'upvote_ratio' => $post['upvote_ratio'] ?? null,
					'is_original' => $post['is_original_content'] ?? false,
					'data_source' => 'pullpush',
				],
			] );

			// Fetch comments for high-engagement posts.
			if ( $include_comments && ( $post['num_comments'] ?? 0 ) > 0 ) {
				$post_id  = $post['id'] ?? '';
				$comments = $client->fetch_comments_for_post( $post_id, $comments_limit );

				foreach ( $comments as $comment ) {
					$collected[] = new PRAutoBlogger_Source_Data( [
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
						'metadata'      => [
							'parent_post_id' => 't3_' . $post_id,
							'parent_title'   => $post['title'] ?? '',
							'is_comment'     => true,
							'created_utc'    => $comment['created_utc'] ?? null,
							'data_source'    => 'pullpush',
						],
					] );
				}
			}
		}

		return $collected;
	}

	/**
	 * Collect data from Reddit .json endpoints (fallback).
	 *
	 * Response format matches the old OAuth API: data.children[].data.
	 * Per-post comment fetching (not subreddit-wide like PullPush).
	 *
	 * @param string $subreddit       Subreddit name.
	 * @param int    $limit           Max posts.
	 * @param string $time_filter     Time window.
	 * @param bool   $include_comments Whether to fetch comments.
	 * @param int    $comments_limit  Max comments per post.
	 *
	 * @return PRAutoBlogger_Source_Data[]
	 */
	private function collect_from_reddit_json(
		string $subreddit,
		int $limit,
		string $time_filter,
		bool $include_comments,
		int $comments_limit
	): array {
		$client    = new PRAutoBlogger_Reddit_JSON_Client();
		$collected = [];

		// Fetch posts — same response format as old OAuth API.
		$posts = $client->fetch_posts( $subreddit, $limit, $time_filter );

		foreach ( $posts as $post ) {
			$collected[] = new PRAutoBlogger_Source_Data( [
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
				'metadata'      => [
					'post_type'   => ( $post['is_self'] ?? false ) ? 'self' : 'link',
					'flair'       => $post['link_flair_text'] ?? null,
					'created_utc' => $post['created_utc'] ?? null,
					'upvote_ratio' => $post['upvote_ratio'] ?? null,
					'is_original' => $post['is_original_content'] ?? false,
					'data_source' => 'reddit_json',
				],
			] );

			// Collect top comments for high-engagement posts.
			if ( $include_comments && ( $post['num_comments'] ?? 0 ) > 0 ) {
				$post_id  = $post['id'] ?? '';
				$comments = $client->fetch_comments( $subreddit, $post_id, $comments_limit );

				foreach ( $comments as $comment ) {
					$collected[] = new PRAutoBlogger_Source_Data( [
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
						'metadata'      => [
							'parent_post_id' => 't3_' . $post_id,
							'parent_title'   => $post['title'] ?? '',
							'is_comment'     => true,
							'created_utc'    => $comment['created_utc'] ?? null,
							'data_source'    => 'reddit_json',
						],
					] );
				}
			}
		}

		return $collected;
	}

	/**
	 * Check if PullPush.io is currently available, with caching.
	 *
	 * Caches the result for 5 minutes to avoid re-checking availability
	 * on every subreddit within a single collection run.
	 *
	 * Side effects: May make HTTP request, sets transient.
	 *
	 * @return bool True if PullPush is reachable.
	 */
	private function is_pullpush_available(): bool {
		$cached = get_transient( self::PULLPUSH_AVAILABLE_TRANSIENT );

		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$client    = new PRAutoBlogger_PullPush_Client();
		$available = $client->is_available();

		set_transient(
			self::PULLPUSH_AVAILABLE_TRANSIENT,
			$available ? '1' : '0',
			self::AVAILABILITY_CACHE_TTL
		);

		if ( ! $available ) {
			PRAutoBlogger_Logger::instance()->warning(
				'PullPush.io unavailable — falling back to Reddit .json endpoints.',
				'reddit'
			);
		}

		return $available;
	}

	/**
	 * Get the list of subreddits configured in plugin settings.
	 *
	 * @return string[]
	 */
	private function get_configured_subreddits(): array {
		$json = get_option( 'prautoblogger_target_subreddits', '' );
		$list = json_decode( $json, true );
		return is_array( $list ) ? $list : [];
	}
}
