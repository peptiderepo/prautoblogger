<?php
declare(strict_types=1);

/**
 * Credential + connectivity validator for the OpenRouter LLM provider.
 *
 * Split out of the provider to keep each file under the 300-line cap and
 * to keep the validation path easy to unit-test without spinning up the
 * retry loop. The provider's `validate_credentials_detailed()` delegates
 * straight to `run()` here.
 *
 * Triggered by: PRAutoBlogger_OpenRouter_Provider::validate_credentials_detailed()
 *               which is called by the admin "Test Connection" action.
 * Dependencies: PRAutoBlogger_OpenRouter_Config (URL resolution),
 *               PRAutoBlogger_Encryption (API key decryption),
 *               PRAutoBlogger_Logger (WARN/ERROR lines), wp_remote_get().
 *
 * @see class-open-router-provider.php Parent class that delegates here.
 * @see class-open-router-config.php   Config helpers for base URL.
 * @see interface-llm-provider.php     Return-shape contract.
 */
class PRAutoBlogger_OpenRouter_Validator {

	private const TIMEOUT_SECONDS = 15;

	/**
	 * Run a non-destructive credential check.
	 *
	 * Lightweight probe: hits the /auth/key endpoint. Never makes a real
	 * LLM completion request — that would consume tokens on every click.
	 *
	 * Side effects: one HTTP GET; one Logger line on failure.
	 *
	 * @return array{status: string, message: string, debug?: string}
	 */
	public function run(): array {
		$encrypted = get_option( 'prautoblogger_openrouter_api_key', '' );
		if ( '' === $encrypted ) {
			return $this->err(
				'option_empty',
				__( 'No API key saved. Enter your OpenRouter key in settings.', 'prautoblogger' )
			);
		}

		$api_key = PRAutoBlogger_Encryption::decrypt( $encrypted );
		if ( '' === $api_key ) {
			return $this->err(
				'decrypt_failed:encrypted_len=' . strlen( $encrypted ),
				__( 'API key decryption failed. Re-enter your key.', 'prautoblogger' )
			);
		}

		// Sanity check: OpenRouter keys typically start with "sk-or-".
		$key_prefix = substr( $api_key, 0, 6 );
		$key_len    = strlen( $api_key );

		if ( 0 !== strpos( $api_key, 'sk-or-' ) ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf(
					'API key format invalid (prefix="%s", len=%d). Likely salt changed — re-enter key.',
					$key_prefix,
					$key_len
				),
				'openrouter'
			);
			return $this->err(
				sprintf( 'bad_format:prefix=%s,len=%d', $key_prefix, $key_len ),
				__( 'API key appears corrupted (decryption produced invalid data). Your WordPress auth salt may have changed. Please re-enter your OpenRouter API key in settings.', 'prautoblogger' )
			);
		}

		// Belt-and-suspenders: inject Authorization at cURL level (see send_chat_completion).
		$config                = new PRAutoBlogger_OpenRouter_Config();
		$base_url              = $config->get_api_base_url();
		$base_host             = (string) wp_parse_url( $base_url, PHP_URL_HOST );
		$auth_header_value     = 'Bearer ' . $api_key;
		$curl_auth_filter_cred = function ( $handle, $parsed_args, $url ) use ( $auth_header_value, $base_host ): void {
			if ( '' === $base_host || false === strpos( (string) $url, $base_host ) ) {
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_HTTPHEADER, [
				'Authorization: ' . $auth_header_value,
			] );
		};
		add_action( 'http_api_curl', $curl_auth_filter_cred, 99, 3 );

		$response = wp_remote_get(
			$base_url . '/auth/key',
			[
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
				],
			]
		);

		remove_action( 'http_api_curl', $curl_auth_filter_cred, 99 );

		if ( is_wp_error( $response ) ) {
			$err_msg = $response->get_error_message();
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'OpenRouter credential check wp_error: %s (key_prefix=%s, key_len=%d)', $err_msg, $key_prefix, $key_len ),
				'openrouter'
			);
			return $this->err(
				'wp_error:' . $err_msg,
				sprintf(
					/* translators: %s: transport-layer error message. */
					esc_html__( 'Network error reaching OpenRouter: %s', 'prautoblogger' ),
					esc_html( $err_msg )
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $status_code ) {
			return [
				'status'  => 'ok',
				'message' => __( 'OpenRouter connected.', 'prautoblogger' ),
			];
		}

		$body_raw = wp_remote_retrieve_body( $response );
		PRAutoBlogger_Logger::instance()->warning(
			sprintf( 'OpenRouter credential check HTTP %d (key_prefix=%s, key_len=%d): %s', $status_code, $key_prefix, $key_len, substr( $body_raw, 0, 300 ) ),
			'openrouter'
		);

		return $this->err(
			sprintf( 'http_%d:key_prefix=%s,key_len=%d', $status_code, $key_prefix, $key_len ),
			sprintf(
				/* translators: %1$d: HTTP status, %2$s: response body. */
				esc_html__( 'OpenRouter returned HTTP %1$d. %2$s', 'prautoblogger' ),
				$status_code,
				esc_html( substr( $body_raw, 0, 200 ) )
			)
		);
	}


	/**
	 * @return array{status: string, message: string, debug: string}
	 */
	private function err( string $debug, string $message ): array {
		return [
			'status'  => 'error',
			'message' => $message,
			'debug'   => $debug,
		];
	}
}
