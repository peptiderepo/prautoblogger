<?php
declare(strict_types=1);

/**
 * Reddit endpoint availability checker.
 *
 * Probes Reddit's RSS and .json endpoints to determine which data sources
 * are reachable from the current server. Datacenter IPs (Hostinger) are
 * often blocked by Reddit for .json but not RSS, so the client must check
 * both before deciding which strategy to use.
 *
 * Triggered by: PRAutoBlogger_Reddit_JSON_Client::is_available() delegation,
 *               Admin settings page "test connection" button.
 * Dependencies: wp_remote_get(), PRAutoBlogger_Logger.
 *
 * @see providers/class-reddit-json-client.php — Instantiates and delegates to this class.
 * @see ARCHITECTURE.md                        — External API integrations table.
 */
class PRAutoBlogger_Reddit_Availability {

	/**
	 * Reddit base URL for all endpoints.
	 *
	 * @var string
	 */
	private const BASE_URL = 'https://www.reddit.com';

	/**
	 * User agent — Reddit requires a descriptive UA for unauthenticated requests.
	 *
	 * @var string
	 */
	private const USER_AGENT = 'Mozilla/5.0 (compatible; PRAutoBlogger/0.2.1; +https://peptiderepo.com)';

	/**
	 * Transient key for rate limit tracking (.json endpoints only).
	 *
	 * @var string
	 */
	private const RATE_LIMIT_TRANSIENT = 'prautoblogger_reddit_json_rate_limit';

	/**
	 * Check if any Reddit endpoint is reachable (RSS or .json).
	 *
	 * Side effects: HTTP request to Reddit.
	 *
	 * @return bool True if at least one endpoint is accessible.
	 */
	public function is_available(): bool {
		return $this->is_rss_available() || $this->is_json_available();
	}

	/**
	 * Check if Reddit RSS endpoints are reachable.
	 *
	 * RSS endpoints are rarely blocked by Reddit, even for datacenter IPs.
	 *
	 * Side effects: One HTTP GET to Reddit.
	 *
	 * @return bool True if RSS endpoint returns HTTP 200.
	 */
	public function is_rss_available(): bool {
		$url      = self::BASE_URL . '/r/all/hot.rss?limit=1';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'User-Agent' => self::USER_AGENT,
				),
			)
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
	 * Side effects: One HTTP GET to Reddit.
	 *
	 * @return bool True if .json endpoint returns HTTP 200.
	 */
	public function is_json_available(): bool {
		$url      = self::BASE_URL . '/r/all/hot.json?limit=1';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'User-Agent' => self::USER_AGENT,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Get current rate limit status from cached .json response headers.
	 *
	 * Reddit .json endpoints return rate limit headers. RSS has no rate limits.
	 * The rate data is written by api_get() and read here.
	 *
	 * @return array{remaining: int, limit: int, resets_at: string}
	 */
	public function get_rate_limit_status(): array {
		$status = get_transient( self::RATE_LIMIT_TRANSIENT );

		if ( is_array( $status ) ) {
			return $status;
		}

		return array(
			'remaining' => 10,
			'limit'     => 10,
			'resets_at' => '',
		);
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
	public function api_get( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'User-Agent' => self::USER_AGENT,
					'Accept'     => 'application/json',
				),
			)
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
				array(
					'remaining' => (int) $rate_remaining,
					'limit'     => (int) $rate_used + (int) $rate_remaining,
					'resets_at' => gmdate( 'Y-m-d H:i:s', time() + (int) $rate_reset ),
				),
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
