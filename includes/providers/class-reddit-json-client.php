<?php
declare(strict_types=1);

/**
 * Reddit .json endpoint client — fallback when PullPush.io is unavailable.
 *
 * Appends `.json` to standard Reddit URLs to get JSON responses without OAuth.
 * No authentication required, but heavily rate-limited (~10 req/min for
 * unauthenticated requests) and datacenter IPs may be blocked aggressively.
 * This is a FALLBACK only — PullPush is the primary data source.
 *
 * Triggered by: PRAutoBlogger_Reddit_Provider when PullPush is_available() returns false.
 * Dependencies: wp_remote_get(), PRAutoBlogger_Logger.
 *
 * @see providers/class-pullpush-client.php    — Primary client (preferred).
 * @see providers/class-reddit-provider.php    — Instantiates this class as fallback.
 * @see ARCHITECTURE.md                        — External API integrations table.
 */
class PRAutoBlogger_Reddit_JSON_Client {

	/**
	 * Reddit base URL for .json endpoints.
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
	private const USER_AGENT = 'Mozilla/5.0 (compatible; PRAutoBlogger/0.1.0; +https://peptiderepo.com)';

	/**
	 * HTTP timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 30;

	/**
	 * Transient key for rate limit tracking.
	 *
	 * @var string
	 */
	private const RATE_LIMIT_TRANSIENT = 'prautoblogger_reddit_json_rate_limit';

	/**
	 * Fetch hot posts from a subreddit via Reddit's .json endpoint.
	 *
	 * Returns data in the same format as the old OAuth client's fetch_posts():
	 * an array of post data arrays with standard Reddit field names.
	 *
	 * Side effects: HTTP request, updates rate limit transient.
	 *
	 * @param string $subreddit   Subreddit name (without r/ prefix).
	 * @param int    $limit       Max posts to fetch (Reddit caps at 100).
	 * @param string $time_filter Time filter for /top (hour, day, week, month, year, all).
	 *
	 * @return array<int, array<string, mixed>> Array of post data.
	 */
	public function fetch_posts( string $subreddit, int $limit, string $time_filter = 'day' ): array {
		$url = sprintf(
			'%s/r/%s/hot.json?limit=%d&t=%s&raw_json=1',
			self::BASE_URL,
			rawurlencode( $subreddit ),
			min( $limit, 100 ),
			rawurlencode( $time_filter )
		);

		$response = $this->api_get( $url );

		if ( null === $response || ! isset( $response['data']['children'] ) ) {
			// .json endpoint failed (likely 403 IP block) — try RSS fallback.
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Reddit .json failed for r/%s — trying RSS fallback.', $subreddit ),
				'reddit_json'
			);
			return $this->fetch_posts_via_rss( $subreddit, $limit );
		}

		$posts = [];
		foreach ( $response['data']['children'] as $child ) {
			if ( isset( $child['data'] ) ) {
				$posts[] = $child['data'];
			}
		}

		return $posts;
	}

	/**
	 * Fetch posts from a subreddit via Reddit's RSS/Atom feed.
	 *
	 * RSS endpoints are less commonly blocked by Reddit than .json.
	 * Returns data in the same format as fetch_posts() for compatibility,
	 * but some fields (score, num_comments) are unavailable in RSS and
	 * set to defaults.
	 *
	 * Side effects: HTTP request to Reddit.
	 *
	 * @param string $subreddit Subreddit name (without r/ prefix).
	 * @param int    $limit     Max posts to fetch.
	 *
	 * @return array<int, array<string, mixed>> Array of post data.
	 */
	private function fetch_posts_via_rss( string $subreddit, int $limit ): array {
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
				'reddit_json'
			);
			return [];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Reddit RSS HTTP %d for r/%s', $status_code, $subreddit ),
				'reddit_json'
			);
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		return $this->parse_atom_feed( $body, $subreddit );
	}

	/**
	 * Parse a Reddit Atom feed into post-data arrays matching .json format.
	 *
	 * Maps Atom entry fields to the standard Reddit post fields so the rest
	 * of the pipeline can consume RSS data without changes. Fields unavailable
	 * in RSS (score, num_comments, upvote_ratio) are set to reasonable defaults.
	 *
	 * @param string $xml       Raw Atom XML string.
	 * @param string $subreddit The subreddit name for context.
	 *
	 * @return array<int, array<string, mixed>> Parsed post data.
	 */
	private function parse_atom_feed( string $xml, string $subreddit ): array {
		// Suppress XML errors for malformed feeds.
		$prev_errors = libxml_use_internal_errors( true );
		$feed        = simplexml_load_string( $xml );
		libxml_use_internal_errors( $prev_errors );

		if ( false === $feed ) {
			PRAutoBlogger_Logger::instance()->error( 'Failed to parse Reddit RSS XML.', 'reddit_json' );
			return [];
		}

		// Register the Atom namespace.
		$feed->registerXPathNamespace( 'atom', 'http://www.w3.org/2005/Atom' );
		$entries = $feed->xpath( '//atom:entry' );

		if ( empty( $entries ) ) {
			return [];
		}

		$posts = [];
		foreach ( $entries as $entry ) {
			$title   = (string) $entry->title;
			$link    = '';
			$content = '';

			// Get the HTML link (type="text/html").
			foreach ( $entry->link as $link_el ) {
				if ( 'text/html' === (string) $link_el['type'] || 'alternate' === (string) $link_el['rel'] ) {
					$link = (string) $link_el['href'];
					break;
				}
			}

			// Content is in <content> tag as HTML.
			if ( isset( $entry->content ) ) {
				// Strip HTML tags to get plain text for content field.
				$content = wp_strip_all_tags( (string) $entry->content );
			}

			// Extract post ID from the entry id (format: /r/subreddit/comments/ID/...)
			$entry_id = (string) $entry->id;
			$post_id  = '';
			if ( preg_match( '#/comments/([a-z0-9]+)#', $entry_id, $m ) ) {
				$post_id = $m[1];
			}

			// Extract author name.
			$author = '[deleted]';
			if ( isset( $entry->author->name ) ) {
				$author = str_replace( '/u/', '', (string) $entry->author->name );
			}

			// Parse published date to Unix timestamp.
			$published   = (string) ( $entry->published ?? $entry->updated ?? '' );
			$created_utc = '' !== $published ? (int) strtotime( $published ) : time();

			// Extract permalink (relative path) from full URL.
			$permalink = '';
			if ( '' !== $link ) {
				$parsed = wp_parse_url( $link );
				$permalink = $parsed['path'] ?? '';
			}

			$posts[] = [
				'id'            => $post_id,
				'title'         => $title,
				'selftext'      => $content,
				'author'        => $author,
				'score'         => 1,           // Not available in RSS.
				'num_comments'  => 0,           // Not available in RSS.
				'permalink'     => $permalink,
				'created_utc'   => $created_utc,
				'is_self'       => true,
				'link_flair_text' => null,
				'upvote_ratio'  => null,
				'is_original_content' => false,
				'data_source'   => 'reddit_rss', // Tag for debugging.
			];
		}

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Parsed %d posts from Reddit RSS for r/%s.', count( $posts ), $subreddit ),
			'reddit_json'
		);

		return $posts;
	}

	/**
	 * Fetch top comments for a specific post via Reddit's .json endpoint.
	 *
	 * Returns data in the same format as the old OAuth client's fetch_comments():
	 * an array of comment data arrays with standard Reddit field names.
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
	 * Check if Reddit .json or RSS endpoints are reachable from this server.
	 *
	 * Datacenter IPs are often blocked by Reddit for .json endpoints.
	 * We check .json first, then fall back to RSS availability.
	 *
	 * Side effects: HTTP request.
	 *
	 * @return bool True if we got a valid response from either endpoint.
	 */
	public function is_available(): bool {
		return $this->is_json_available() || $this->is_rss_available();
	}

	/**
	 * Check if Reddit .json endpoints are reachable.
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
	 * Check if Reddit RSS endpoints are reachable.
	 *
	 * RSS endpoints are less commonly blocked by Reddit than .json.
	 *
	 * @return bool True if RSS endpoint returns HTTP 200 with XML content.
	 */
	private function is_rss_available(): bool {
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
	 * Get current rate limit status.
	 *
	 * Reddit .json endpoints return rate limit headers similar to the OAuth API.
	 * We track them in a transient.
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
				'reddit_json'
			);
			return null;
		}

		// Track rate limits from response headers (same as OAuth API).
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
				'reddit_json'
			);
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
