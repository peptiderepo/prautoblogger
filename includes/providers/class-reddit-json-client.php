<?php
declare(strict_types=1);

/**
 * Reddit HTTP client — fetches posts via RSS (primary) and .json (fallback).
 *
 * RSS feeds are the primary data source because they work reliably from datacenter
 * IPs (Hostinger), while .json endpoints return 403 for server IPs. The .json
 * endpoints are kept as a fallback in case RSS ever breaks, and are always used
 * for comment fetching (comments are not available in RSS feeds).
 *
 * Triggered by: PRAutoBlogger_Reddit_Provider::collect_data().
 * Dependencies: wp_remote_get(), PRAutoBlogger_Logger, PRAutoBlogger_Reddit_RSS_Parser.
 *
 * @see providers/class-reddit-provider.php      — Instantiates and calls this class.
 * @see providers/class-reddit-rss-parser.php    — Parses Atom XML into post arrays.
 * @see ARCHITECTURE.md                         — External API integrations table.
 */
class PRAutoBlogger_Reddit_JSON_Client {

	/**
	 * Reddit base URL for all endpoints.
	 *
	 * @var string
	 */
	private const BASE_URL = 'https://www.reddit.com';

	/**
	 * User agent — Reddit requires a descriptive UA for unauthenticated requests.
	 * Using a browser-like UA helps avoid aggressive datacenter IP blocking.
	 *
	 * @var string
	 */
	private const USER_AGENT = 'Mozilla/5.0 (compatible; PRAutoBlogger/0.2.1; +https://peptiderepo.com)';

	/**
	 * HTTP timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 30;

	/**
	 * Transient key for rate limit tracking (.json endpoints only).
	 *
	 * @var string
	 */
	private const RATE_LIMIT_TRANSIENT = 'prautoblogger_reddit_json_rate_limit';

	/**
	 * Fetch hot posts from a subreddit. Tries RSS first, .json fallback.
	 *
	 * RSS is the primary source because it works from datacenter IPs where
	 * .json returns 403. If RSS fails, we fall back to .json. Both return
	 * data in the same normalized format.
	 *
	 * Side effects: HTTP request to Reddit.
	 *
	 * @param string $subreddit   Subreddit name (without r/ prefix).
	 * @param int    $limit       Max posts to fetch (Reddit caps at 100).
	 * @param string $time_filter Time filter (unused by RSS, used by .json).
	 *
	 * @return array<int, array<string, mixed>> Array of post data.
	 */
	public function fetch_posts( string $subreddit, int $limit, string $time_filter = 'day' ): array {
		// Try RSS first — most reliable from datacenter IPs.
		$posts = $this->fetch_posts_via_rss( $subreddit, $limit );

		if ( ! empty( $posts ) ) {
			return $posts;
		}

		// RSS failed — fall back to .json endpoints.
		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Reddit RSS returned 0 posts for r/%s — trying .json fallback.', $subreddit ),
			'reddit'
		);

		return $this->fetch_posts_via_json( $subreddit, $limit, $time_filter );
	}

	/**
	 * Fetch posts from a subreddit via Reddit's RSS/Atom feed.
	 *
	 * RSS endpoints work reliably from datacenter IPs where .json gets blocked.
	 * Returns data normalized to the same format as .json for pipeline compatibility.
	 * Some fields (score, num_comments) are unavailable in RSS and set to defaults.
	 *
	 * Side effects: HTTP request to Reddit.
	 *
	 * @param string $subreddit Subreddit name (without r/ prefix).
	 * @param int    $limit     Max posts to fetch.
	 *
	 * @return array<int, array<string, mixed>> Array of post data.
	 */
	public function fetch_posts_via_rss( string $subreddit, int $limit ): array {
		$url = sprintf(
			'%s/r/%s/hot.rss?limit=%d',
			self::BASE_URL,
			rawurlencode( $subreddit ),
			min( $limit, 100 )
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => [
					'User-Agent' => self::USER_AGENT,
					'Accept'     => 'application/atom+xml, application/rss+xml',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			PRAutoBlogger_Logger::instance()->error(
				'Reddit RSS GET failed: ' . $response->get_error_message(),
				'reddit'
			);
			return [];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Reddit RSS HTTP %d for r/%s', $status_code, $subreddit ),
				'reddit'
			);
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$parser = new PRAutoBlogger_Reddit_RSS_Parser();
		return $parser->parse( $body, $subreddit );
	}

	/**
	 * Fetch hot posts from a subreddit via Reddit's .json endpoint (fallback).
	 *
	 * Datacenter IPs are often blocked by Reddit for .json endpoints (HTTP 403).
	 * This method is only used as a fallback when RSS fails.
	 *
	 * Side effects: HTTP request, updates rate limit transient.
	 *
	 * @param string $subreddit   Subreddit name (without r/ prefix).
	 * @param int    $limit       Max posts to fetch (Reddit caps at 100).
	 * @param string $time_filter Time filter for /hot (hour, day, week, month, year, all).
	 *
	 * @return array<int, array<string, mixed>> Array of post data.
	 */
	public function fetch_posts_via_json( string $subreddit, int $limit, string $time_filter = 'day' ): array {
		$url = sprintf(
			'%s/r/%s/hot.json?limit=%d&t=%s&raw_json=1',
			self::BASE_URL,
			rawurlencode( $subreddit ),
			min( $limit, 100 ),
			rawurlencode( $time_filter )
		);

		$response = $this->api_get( $url );

		if ( null === $response || ! isset( $response['data']['children'] ) ) {
			return [];
		}

		$posts = [];
		foreach ( $response['data']['children'] as $child ) {
			if ( isset( $child['data'] ) ) {
				$post = $child['data'];
				$post['data_source'] = 'reddit_json';
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Fetch top comments for a specific post via Reddit's .json endpoint.
	 *
	 * Comments are always fetched via .json because RSS feeds don't include them.
	 * Datacenter IP blocks may prevent this from working, in which case we
	 * return an empty array (articles can still be generated from post titles alone).
	 *
	 * Side effects: HTTP request, updates rate limit transient.
	 *
	 * @param string $subreddit Subreddit name (without r/ prefix).
	 * @param string $post_id   Reddit post ID (without t3_ prefix).
	 * @param int    $limit     Max comments to fetch.
	 *
	 * @return array<int, array<string, mixed>> Array of comment data.
	 */
	public function fetch_comments( string $subreddit, string $post_id, int $limit = 5 ): array {
		$url = sprintf(
			'%s/r/%s/comments/%s.json?limit=%d&sort=top&depth=1&raw_json=1',
			self::BASE_URL,
			rawurlencode( $subreddit ),
			rawurlencode( $post_id ),
			min( $limit, 100 )
		);

		$response = $this->api_get( $url );

		// Comments endpoint returns [post_listing, comments_listing].
		if ( ! is_array( $response ) || ! isset( $response[1]['data']['children'] ) ) {
			return [];
		}

		$comments = [];
		foreach ( $response[1]['data']['children'] as $child ) {
			// Filter out "more" type entries, keep only actual comments (kind=t1).
			if ( isset( $child['data'] ) && 't1' === ( $child['kind'] ?? '' ) ) {
				$comments[] = $child['data'];
			}
		}

		return $comments;
	}

	/**
	 * Check if at least one Reddit endpoint is reachable from this server.
	 *
	 * Tries RSS first (most reliable), then .json as fallback.
	 *
	 * Side effects: HTTP request.
	 *
	 * @return bool True if we got a valid response from either endpoint.
	 */
	public function is_available(): bool {
		return $this->is_rss_available() || $this->is_json_available();
	}

	/**
	 * Check if Reddit RSS endpoints are reachable.
	 *
	 * RSS endpoints are rarely blocked by Reddit, even for datacenter IPs.
	 *
	 * @return bool True if RSS endpoint returns HTTP 200.
	 */
	public function is_rss_available(): bool {
		$url      = self::BASE_URL . '/r/all/hot.rss?limit=1';
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
	 * Check if Reddit .json endpoints are reachable.
	 *
	 * Datacenter IPs are often blocked by Reddit for .json endpoints.
	 *
	 * @return bool True if .json endpoint returns HTTP 200.
	 */
	public function is_json_available(): bool {
		$url      = self::BASE_URL . '/r/all/hot.json?limit=1';
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
	 * Reddit .json endpoints return rate limit headers. RSS has no rate limits.
	 *
	 * @return array{remaining: int, limit: int, resets_at: string}
	 */
	public function get_rate_limit_status(): array {
		$status = get_transient( self::RATE_LIMIT_TRANSIENT );

		if ( is_array( $status ) ) {
			return $status;
		}

		return [
			'remaining' => 10,
			'limit'     => 10,
			'resets_at' => '',
		];
	}

	/**
	 * Make an HTTP GET request to a Reddit .json endpoint.
	 *
	 * No authentication — just a plain GET with a descriptive User-Agent.
	 * Tracks rate limits from response headers when available.
	 *
	 * Side effects: HTTP request, sets rate limit transient, logs errors.
	 *
	 * @param string $url Full URL with .json suffix and query params.
	 *
	 * @return array<string, mixed>|null Decoded JSON, or null on failure.
	 */
	private function api_get( string $url ): ?array {
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
				'Reddit .json GET failed: ' . $response->get_error_message(),
				'reddit'
			);
			return null;
		}

		// Track rate limits from response headers.
		$rate_remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$rate_used      = wp_remote_retrieve_header( $response, 'x-ratelimit-used' );
		$rate_reset     = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );

		if ( '' !== $rate_remaining ) {
			set_transient(
				self::RATE_LIMIT_TRANSIENT,
				[
					'remaining' => (int) $rate_remaining,
					'limit'     => (int) $rate_used + (int) $rate_remaining,
					'resets_at' => gmdate( 'Y-m-d H:i:s', time() + (int) $rate_reset ),
				],
				120
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf(
					'Reddit .json HTTP %d: %s',
					$status_code,
					substr( wp_remote_retrieve_body( $response ), 0, 300 )
				),
				'reddit'
			);
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
