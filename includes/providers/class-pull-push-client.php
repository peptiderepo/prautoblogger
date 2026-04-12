<?php
declare(strict_types=1);

/**
 * PullPush.io API HTTP client for fetching Reddit data without OAuth.
 *
 * PullPush.io is a free, no-auth-required API that mirrors Reddit's submission
 * and comment data. We use it as the PRIMARY data source because Reddit rejected
 * our OAuth application. Rate limit: 30 requests/minute, 1000/hour.
 *
 * Triggered by: PRAutoBlogger_Reddit_Provider calls fetch methods here.
 * Dependencies: wp_remote_get(), PRAutoBlogger_Logger.
 *
 * @see providers/class-reddit-provider.php   — Instantiates this class.
 * @see providers/class-reddit-json-client.php — Fallback client if PullPush is down.
 * @see ARCHITECTURE.md                        — External API integrations table.
 */
class PRAutoBlogger_PullPush_Client {

	/**
	 * PullPush API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.pullpush.io/reddit';

	/**
	 * User agent sent with every request so PullPush can identify us.
	 *
	 * @var string
	 */
	private const USER_AGENT = 'PRAutoBlogger/0.1.0 (WordPress Plugin; +https://peptiderepo.com)';

	/**
	 * HTTP timeout in seconds. PullPush can be slow under load.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 30;

	/**
	 * Maximum results PullPush returns per request.
	 *
	 * @var int
	 */
	private const MAX_SIZE = 100;

	/**
	 * Transient key for tracking rate limit state.
	 *
	 * @var string
	 */
	private const RATE_LIMIT_TRANSIENT = 'prautoblogger_pullpush_rate_limit';

	/**
	 * Minimum seconds between requests to stay under 30 req/min.
	 *
	 * @var float
	 */
	private const MIN_REQUEST_INTERVAL = 2.0;

	/**
	 * Fetch recent submissions (posts) from a subreddit, sorted by score.
	 *
	 * PullPush returns submissions as a flat array (not nested like Reddit's
	 * data.children[].data structure). Each item has Reddit-standard field names:
	 * id, title, selftext, author, score, num_comments, permalink, created_utc, etc.
	 *
	 * Side effects: HTTP request to PullPush API, updates rate limit transient.
	 *
	 * @param string $subreddit   Subreddit name (without r/ prefix).
	 * @param int    $limit       Max posts to fetch (capped at 100 by API).
	 * @param string $time_filter Time window: 'hour', 'day', 'week', 'month', 'year', 'all'.
	 *
	 * @return array<int, array<string, mixed>> Array of submission data arrays.
	 */
	public function fetch_posts( string $subreddit, int $limit, string $time_filter = 'day' ): array {
		$size  = min( $limit, self::MAX_SIZE );
		$after = $this->time_filter_to_epoch( $time_filter );

		$url = sprintf(
			'%s/search/submission?subreddit=%s&sort=score&sort_type=desc&size=%d&after=%d',
			self::API_BASE,
			rawurlencode( $subreddit ),
			$size,
			$after
		);

		$response = $this->api_get( $url );

		if ( null === $response ) {
			return [];
		}

		// PullPush returns { "data": [...] } or just [...] depending on endpoint version.
		$items = $response['data'] ?? $response;

		if ( ! is_array( $items ) ) {
			return [];
		}

		return $items;
	}

	/**
	 * Fetch recent comments from a subreddit, sorted by score.
	 *
	 * Unlike the Reddit API which fetches comments per-post, PullPush lets us
	 * search comments across an entire subreddit. This is more efficient for
	 * finding high-quality discussion content.
	 *
	 * Side effects: HTTP request to PullPush API, updates rate limit transient.
	 *
	 * @param string $subreddit Subreddit name (without r/ prefix).
	 * @param int    $limit     Max comments to fetch (capped at 100 by API).
	 * @param string $time_filter Time window for filtering.
	 *
	 * @return array<int, array<string, mixed>> Array of comment data arrays.
	 */
	public function fetch_comments( string $subreddit, int $limit, string $time_filter = 'day' ): array {
		$size  = min( $limit, self::MAX_SIZE );
		$after = $this->time_filter_to_epoch( $time_filter );

		$url = sprintf(
			'%s/search/comment?subreddit=%s&sort=score&sort_type=desc&size=%d&after=%d',
			self::API_BASE,
			rawurlencode( $subreddit ),
			$size,
			$after
		);

		$response = $this->api_get( $url );

		if ( null === $response ) {
			return [];
		}

		$items = $response['data'] ?? $response;

		if ( ! is_array( $items ) ) {
			return [];
		}

		return $items;
	}

	/**
	 * Fetch comments for a specific post by its Reddit ID.
	 *
	 * Uses the link_id filter (t3_ prefixed) to get comments for one post.
	 *
	 * Side effects: HTTP request to PullPush API.
	 *
	 * @param string $post_id Reddit post ID (without t3_ prefix).
	 * @param int    $limit   Max comments to fetch.
	 *
	 * @return array<int, array<string, mixed>> Array of comment data arrays.
	 */
	public function fetch_comments_for_post( string $post_id, int $limit = 5 ): array {
		$size = min( $limit, self::MAX_SIZE );

		$url = sprintf(
			'%s/search/comment?link_id=t3_%s&sort=score&sort_type=desc&size=%d',
			self::API_BASE,
			rawurlencode( $post_id ),
			$size
		);

		$response = $this->api_get( $url );

		if ( null === $response ) {
			return [];
		}

		$items = $response['data'] ?? $response;

		return is_array( $items ) ? $items : [];
	}

	/**
	 * Check if PullPush API is reachable.
	 *
	 * Makes a minimal request to verify connectivity. Used by the provider
	 * to decide whether to fall back to Reddit .json endpoints.
	 *
	 * Side effects: HTTP request.
	 *
	 * @return bool True if API responded with HTTP 200.
	 */
	public function is_available(): bool {
		// Minimal query: 1 submission from a popular subreddit.
		$url      = self::API_BASE . '/search/submission?subreddit=all&size=1';
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => [
					'User-Agent' => self::USER_AGENT,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Get current rate limit status.
	 *
	 * PullPush doesn't return rate limit headers, so we track our own
	 * request count using a transient with a 60-second TTL.
	 *
	 * @return array{remaining: int, limit: int, resets_at: string}
	 */
	public function get_rate_limit_status(): array {
		$status = get_transient( self::RATE_LIMIT_TRANSIENT );

		if ( is_array( $status ) ) {
			return $status;
		}

		// No tracking data yet — assume fresh window.
		return [
			'remaining' => 30,
			'limit'     => 30,
			'resets_at' => '',
		];
	}

	/**
	 * Make an HTTP GET request to the PullPush API.
	 *
	 * Handles error logging, response parsing, and self-imposed rate limit tracking.
	 * No authentication required — PullPush is a free public API.
	 *
	 * Side effects: HTTP request, sets rate limit transient, logs errors.
	 *
	 * @param string $url Full API URL with query parameters.
	 *
	 * @return array<string, mixed>|null Decoded JSON response, or null on failure.
	 */
	private function api_get( string $url ): ?array {
		// Self-imposed rate limiting: track requests per minute.
		$this->track_rate_limit();

		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => [
					'User-Agent' => self::USER_AGENT,
					'Accept'     => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			PRAutoBlogger_Logger::instance()->error(
				'PullPush API GET failed: ' . $response->get_error_message(),
				'pullpush'
			);
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf(
					'PullPush API HTTP %d: %s',
					$status_code,
					substr( wp_remote_retrieve_body( $response ), 0, 300 )
				),
				'pullpush'
			);
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			PRAutoBlogger_Logger::instance()->error(
				'PullPush API returned non-JSON response.',
				'pullpush'
			);
			return null;
		}

		return $data;
	}

	/**
	 * Track our own request count to avoid exceeding PullPush's 30 req/min limit.
	 *
	 * Uses a transient that stores the request count and window start time.
	 * If we're close to the limit, we don't block — the caller should check
	 * get_rate_limit_status() before making bulk requests.
	 *
	 * Side effects: Sets transient.
	 */
	private function track_rate_limit(): void {
		$status = get_transient( self::RATE_LIMIT_TRANSIENT );

		if ( ! is_array( $status ) ) {
			$status = [
				'remaining' => 29, // This request uses one.
				'limit'     => 30,
				'resets_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			];
		} else {
			$status['remaining'] = max( 0, (int) $status['remaining'] - 1 );
		}

		// TTL of 60 seconds — the window auto-resets.
		set_transient( self::RATE_LIMIT_TRANSIENT, $status, 60 );
	}

	/**
	 * Convert a human-readable time filter to a Unix epoch timestamp.
	 *
	 * PullPush uses `after` parameter as a Unix timestamp, not Reddit's
	 * `t=day` style. This maps between the two conventions.
	 *
	 * @param string $time_filter One of: 'hour', 'day', 'week', 'month', 'year', 'all'.
	 *
	 * @return int Unix timestamp to use as the `after` parameter.
	 */
	private function time_filter_to_epoch( string $time_filter ): int {
		$now = time();

		switch ( $time_filter ) {
			case 'hour':
				return $now - 3600;
			case 'day':
				return $now - 86400;
			case 'week':
				return $now - 604800;
			case 'month':
				return $now - 2592000;
			case 'year':
				return $now - 31536000;
			case 'all':
				return 0;
			default:
				return $now - 86400; // Default to 1 day.
		}
	}
}
