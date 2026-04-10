<?php
declare(strict_types=1);

/**
 * Google Analytics 4 API client for fetching post performance metrics.
 *
 * Handles OAuth2 service account authentication and the GA4 Data API.
 * Decoupled from metrics collection logic for testability and reusability.
 *
 * Triggered by: Autoblogger_Metrics_Collector::fetch_ga4_data() calls methods here.
 * Dependencies: Autoblogger_Encryption (for credentials decryption), wp_remote_post/get().
 *
 * @see core/class-metrics-collector.php — Instantiates this class.
 * @see ARCHITECTURE.md                  — External API integrations table.
 */
class Autoblogger_GA4_Client {

	/**
	 * Fetch Google Analytics 4 data for given posts.
	 *
	 * Returns empty data if GA4 is not configured or API call fails.
	 *
	 * Side effects: HTTP request to GA4 API (if configured).
	 *
	 * @param \WP_Post[] $posts
	 *
	 * @return array<int, array{pageviews: int, avg_time_on_page: float, bounce_rate: float}>
	 */
	public function fetch_data( array $posts ): array {
		$property_id = get_option( 'autoblogger_ga4_property_id', '' );
		if ( '' === $property_id ) {
			return [];
		}

		$credentials_encrypted = get_option( 'autoblogger_ga4_credentials_json', '' );
		if ( '' === $credentials_encrypted ) {
			return [];
		}

		$credentials = Autoblogger_Encryption::decrypt( $credentials_encrypted );
		if ( '' === $credentials ) {
			return [];
		}

		// Build page paths for the GA4 query.
		$page_paths = [];
		$path_to_id = [];
		foreach ( $posts as $post ) {
			$path = wp_parse_url( get_permalink( $post->ID ), PHP_URL_PATH );
			if ( $path ) {
				$page_paths[]         = $path;
				$path_to_id[ $path ] = (int) $post->ID;
			}
		}

		if ( empty( $page_paths ) ) {
			return [];
		}

		// GA4 Data API request.
		// Using the REST API directly since we don't want a full Google SDK dependency.
		$access_token = $this->get_access_token( $credentials );
		if ( '' === $access_token ) {
			Autoblogger_Logger::instance()->error( 'GA4: Failed to get access token. Possible causes: invalid service account JSON, expired or revoked private key, or network error reaching oauth2.googleapis.com. Verify credentials in AutoBlogger → Settings.', 'ga4' );
			return [];
		}

		$request_body = [
			'dateRanges'  => [ [ 'startDate' => '30daysAgo', 'endDate' => 'today' ] ],
			'dimensions'  => [ [ 'name' => 'pagePath' ] ],
			'metrics'     => [
				[ 'name' => 'screenPageViews' ],
				[ 'name' => 'averageSessionDuration' ],
				[ 'name' => 'bounceRate' ],
			],
			'dimensionFilter' => [
				'filter' => [
					'fieldName'    => 'pagePath',
					'inListFilter' => [ 'values' => $page_paths ],
				],
			],
		];

		$response = wp_remote_post(
			"https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport",
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $request_body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			Autoblogger_Logger::instance()->error( 'GA4 API request failed (WP HTTP error): ' . $response->get_error_message(), 'ga4' );
			return [];
		}

		$ga4_status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $ga4_status ) {
			$ga4_body = wp_remote_retrieve_body( $response );
			Autoblogger_Logger::instance()->error(
				sprintf( 'GA4 API request failed (HTTP %d): %s', $ga4_status, substr( $ga4_body, 0, 500 ) ),
				'ga4'
			);
			return [];
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$results = [];

		foreach ( ( $data['rows'] ?? [] ) as $row ) {
			$path = $row['dimensionValues'][0]['value'] ?? '';
			if ( isset( $path_to_id[ $path ] ) ) {
				$results[ $path_to_id[ $path ] ] = [
					'pageviews'        => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
					'avg_time_on_page' => (float) ( $row['metricValues'][1]['value'] ?? 0 ),
					'bounce_rate'      => (float) ( $row['metricValues'][2]['value'] ?? 0 ),
				];
			}
		}

		return $results;
	}

	/**
	 * Get a GA4 access token from service account credentials.
	 *
	 * @param string $credentials_json JSON service account key.
	 *
	 * @return string Access token, or empty string on failure.
	 */
	private function get_access_token( string $credentials_json ): string {
		$creds = json_decode( $credentials_json, true );
		if ( ! isset( $creds['client_email'], $creds['private_key'] ) ) {
			return '';
		}

		// Build JWT for service account auth.
		$now    = time();
		$header = wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] );
		$claim  = wp_json_encode( [
			'iss'   => $creds['client_email'],
			'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'exp'   => $now + 3600,
			'iat'   => $now,
		] );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$base64_header = rtrim( strtr( base64_encode( $header ), '+/', '-_' ), '=' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$base64_claim  = rtrim( strtr( base64_encode( $claim ), '+/', '-_' ), '=' );

		$signing_input = $base64_header . '.' . $base64_claim;
		$signature     = '';

		$key = openssl_pkey_get_private( $creds['private_key'] );
		if ( false === $key ) {
			Autoblogger_Logger::instance()->error( 'GA4: Failed to parse private key from service account credentials. The key may be malformed or the OpenSSL extension may be misconfigured.', 'ga4' );
			return '';
		}

		openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$base64_signature = rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' );

		$jwt = $signing_input . '.' . $base64_signature;

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'timeout' => 15,
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			Autoblogger_Logger::instance()->error( 'GA4: OAuth token exchange failed (WP HTTP error): ' . $response->get_error_message(), 'ga4' );
			return '';
		}

		$token_status = wp_remote_retrieve_response_code( $response );
		$data         = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $token_status || ! isset( $data['access_token'] ) ) {
			$error_desc = $data['error_description'] ?? $data['error'] ?? 'unknown';
			Autoblogger_Logger::instance()->error(
				sprintf( 'GA4: OAuth token exchange failed (HTTP %d): %s', $token_status, $error_desc ),
				'ga4'
			);
			return '';
		}

		return $data['access_token'];
	}
}
