<?php
declare(strict_types=1);

/**
 * Reddit API HTTP client for authenticated requests.
 *
 * Encapsulates OAuth2 token management, rate limit tracking, and HTTP methods
 * for the Reddit API. Decoupled from collection logic for testability.
 *
 * Triggered by: PRAutoBlogger_Reddit_Provider calls fetch methods here.
 * Dependencies: PRAutoBlogger_Encryption (for client secret), wp_remote_post/get().
 *
 * @see providers/class-reddit-provider.php — Instantiates this class.
 * @see ARCHITECTURE.md                     — External API integrations table.
 */
class PRAutoBlogger_Reddit_API_Client {

	private const AUTH_URL = 'https://www.reddit.com/api/v1/access_token';
	private const API_BASE = 'https://oauth.reddit.com';
	private const USER_AGENT = 'PRAutoBlogger/0.1.0 (WordPress Plugin)';

	/**
	 * Get an OAuth2 access token, using cached transient if available.
	 *
	 * Reddit script apps use client_credentials grant type.
	 *
	 * Side effects: HTTP request, sets transient.
	 *
	 * @param bool $force_refresh Bypass cache and request a new token.
	 *
	 * @return string Access token, or empty string on failure.
	 */
	public function get_access_token( bool $force_refresh = false ): string {
		if ( ! $force_refresh ) {
			$cached = get_transient( 'prautoblogger_reddit_token' );
			if ( false !== $cached && is_string( $cached ) ) {
				return $cached;
			}
		}

		$client_id     = get_option( 'prautoblogger_reddit_client_id', '' );
		$client_secret = PRAutoBlogger_Encryption::decrypt(
			get_option( 'prautoblogger_reddit_client_secret', '' )
		);

		if ( '' === $client_id || '' === $client_secret ) {
			return '';
		}

		$response = wp_remote_post(
			self::AUTH_URL,
			[
				'timeout' => 15,
				'headers' => [
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'User-Agent'    => self::USER_AGENT,
				],
				'body'    => [
					'grant_type' => 'client_credentials',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			PRAutoBlogger_Logger::instance()->error( 'Reddit OAuth failed: ' . $response->get_error_message(), 'reddit' );
			return '';
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			PRAutoBlogger_Logger::instance()->error( 'Reddit OAuth HTTP ' . wp_remote_retrieve_response_code( $response ), 'reddit' );
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $data['access_token'] ) ) {
			PRAutoBlogger_Logger::instance()->error( 'Reddit OAuth response missing access_token.', 'reddit' );
			return '';
		}

		$token   = $data['access_token'];
		$expires = isset( $data['expires_in'] ) ? (int) $data['expires_in'] - 60 : 3540;

		set_transient( 'prautoblogger_reddit_token', $token, $expires );

		return $token;
	}

	/**
	 * Fetch posts from a subreddit.
	 *
	 * @param string $token       OAuth access token.
	 * @param string $subreddit   Subreddit name (without r/ prefix).
	 * @param int    $limit       Max posts to fetch.
	 * @param string $time_filter Time filter for /top (hour, day, week, month, year, all).
	 *
	 * @return array<int, array<string, mixed>> Array of post data.
	 */
	public function fetch_posts( string $token, string $subreddit, int $limit, string $time_filter ): array {
		$url = sprintf(
			'%s/r/%s/hot?limit=%d&t=%s',
			self::API_BASE,
			rawurlencode( $subreddit ),
			min( $limit, 100 ),
			rawurlencode( $time_filter )
		);

		$response = $this->api_get( $token, $url );
		if ( null === $response || ! isset( $response['data']['children'] ) ) {
			return [];
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
	 * Fetch top comments for a specific post.
	 *
	 * @param string $token     OAuth access token.
	 * @param string $subreddit Subreddit name.
	 * @param string $post_id   Reddit post ID (without t3_ prefix).
	 * @param int    $limit     Max comments to fetch.
	 *
	 * @return array<int, array<string, mixed>> Array of comment data.
	 */
	public function fetch_comments( string $token, string $subreddit, string $post_id, int $limit ): array {
		$url = sprintf(
			'%s/r/%s/comments/%s?limit=%d&sort=top&depth=1',
			self::API_BASE,
			rawurlencode( $subreddit ),
			rawurlencode( $post_id ),
			min( $limit, 50 )
		);

		$response = $this->api_get( $token, $url );

		// Comments endpoint returns [post_listing, comments_listing].
		if ( ! is_array( $response ) || ! isset( $response[1]['data']['children'] ) ) {
			return [];
		}

		$comments = [];
		foreach ( $response[1]['data']['children'] as $child ) {
			if ( isset( $child['data'] ) && 't1' === ( $child['kind'] ?? '' ) ) {
				$comments[] = $child['data'];
			}
		}

		return $comments;
	}

	/**
	 * Make an authenticated GET request to the Reddit API.
	 *
	 * Updates rate limit tracking from response headers.
	 *
	 * @param string $token OAuth access token.
	 * @param string $url   Full API URL.
	 *
	 * @return array<string, mixed>|null Decoded response, or null on failure.
	 */
	public function api_get( string $token, string $url ): ?array {
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'User-Agent'    => self::USER_AGENT,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			PRAutoBlogger_Logger::instance()->error( 'Reddit API GET failed: ' . $response->get_error_message(), 'reddit' );
			return null;
		}

		// Track rate limits from response headers.
		$rate_remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$rate_limit     = wp_remote_retrieve_header( $response, 'x-ratelimit-used' );
		$rate_reset     = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );

		if ( '' !== $rate_remaining ) {
			set_transient( 'prautoblogger_reddit_rate_limit', [
				'remaining' => (int) $rate_remaining,
				'limit'     => (int) $rate_limit + (int) $rate_remaining,
				'resets_at' => gmdate( 'Y-m-d H:i:s', time() + (int) $rate_reset ),
			], 120 );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			PRAutoBlogger_Logger::instance()->error( sprintf( 'Reddit API HTTP %d: %s', $status_code, substr( wp_remote_retrieve_body( $response ), 0, 300 ) ), 'reddit' );
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
