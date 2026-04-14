<?php
declare(strict_types=1);

/**
 * OpenRouter API integration for all LLM calls (analysis, writing, editing).
 *
 * OpenRouter provides a unified API to access models from Anthropic, OpenAI,
 * Google, Meta, and others. This lets users pick any model without us maintaining
 * separate provider integrations.
 *
 * Triggered by: Content_Analyzer, Content_Generator, Chief_Editor, Metrics_Collector.
 * Dependencies: PRAutoBlogger_Encryption (for API key decryption), wp_remote_post(), PRAutoBlogger_OpenRouter_Pricing.
 *
 * @see interface-llm-provider.php      — Interface this class implements.
 * @see class-open-router-pricing.php   — Pricing and model list lookups.
 * @see class-cost-tracker.php          — Called after every request to log token usage.
 * @see ARCHITECTURE.md                 — Data flow diagram showing where this fits.
 */
class PRAutoBlogger_OpenRouter_Provider implements PRAutoBlogger_LLM_Provider_Interface {

	/**
	 * Default direct-to-OpenRouter endpoint. Used when no Cloudflare AI Gateway
	 * URL is configured. The active endpoint is resolved per-request via
	 * get_api_base_url() so admins can flip to an AI Gateway proxy without code.
	 */
	private const DEFAULT_API_BASE_URL = 'https://openrouter.ai/api/v1';

	/**
	 * Resolve the API base URL.
	 *
	 * If an admin has configured a Cloudflare AI Gateway URL in settings,
	 * we route through that instead of hitting OpenRouter directly. The
	 * gateway is a transparent OpenAI/OpenRouter-compatible proxy that
	 * adds caching, cost logging, rate-limiting, and provider fallback
	 * — see ARCHITECTURE.md "External API Integrations".
	 *
	 * Expected gateway URL shape:
	 *   https://gateway.ai.cloudflare.com/v1/{account_id}/{gateway_id}/openrouter
	 *
	 * @return string Base URL with no trailing slash.
	 */
	private function get_api_base_url(): string {
		$override = trim( (string) get_option( 'prautoblogger_ai_gateway_base_url', '' ) );
		if ( '' === $override ) {
			return self::DEFAULT_API_BASE_URL;
		}
		// Only accept https to avoid plaintext-auth regressions.
		if ( 0 !== stripos( $override, 'https://' ) ) {
			return self::DEFAULT_API_BASE_URL;
		}
		return rtrim( $override, '/' );
	}

	/**
	 * Cache TTL (seconds) for AI Gateway response caching.
	 *
	 * Cloudflare honours the `cf-aig-cache-ttl` request header and serves
	 * cached responses for identical payloads within the TTL window. Only
	 * meaningful when a gateway base URL is configured; harmless otherwise.
	 *
	 * @return int Non-negative integer seconds; 0 disables caching.
	 */
	private function get_cache_ttl_seconds(): int {
		$ttl = (int) get_option( 'prautoblogger_ai_gateway_cache_ttl', 0 );
		return $ttl > 0 ? $ttl : 0;
	}

	/**
	 * Send a chat completion request to OpenRouter.
	 *
	 * Retries with exponential backoff on transient failures (5xx, timeout).
	 * Logs every attempt. Fails loudly after exhausting retries.
	 *
	 * Side effects: HTTP request to OpenRouter API.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Chat messages.
	 * @param string $model   Model identifier (e.g., 'anthropic/claude-sonnet-4').
	 * @param array{
	 *     temperature?: float,
	 *     max_tokens?: int,
	 *     response_format?: array{type: string},
	 * } $options Optional parameters.
	 *
	 * @return array{
	 *     content: string,
	 *     model: string,
	 *     prompt_tokens: int,
	 *     completion_tokens: int,
	 *     total_tokens: int,
	 *     finish_reason: string,
	 * }
	 *
	 * @throws \RuntimeException On API error after retries exhausted.
	 */
	public function send_chat_completion( array $messages, string $model, array $options = [] ): array {
		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			throw new \RuntimeException(
				__( 'OpenRouter API key is not configured. Go to PRAutoBlogger → Settings.', 'prautoblogger' )
			);
		}

		// Validate key format — OpenRouter keys start with "sk-or-".
		// A key that decrypts to garbage (e.g. after a salt change) won't match.
		if ( 0 !== strpos( $api_key, 'sk-or-' ) ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf(
					'Decrypted API key has unexpected format (prefix="%s", len=%d). Re-enter your key in settings.',
					substr( $api_key, 0, 6 ),
					strlen( $api_key )
				),
				'openrouter'
			);
			throw new \RuntimeException(
				__( 'OpenRouter API key appears corrupted (unexpected format). Please re-enter your key in PRAutoBlogger → Settings.', 'prautoblogger' )
			);
		}

		$body = [
			'model'    => $model,
			'messages' => $messages,
		];

		if ( isset( $options['temperature'] ) ) {
			$body['temperature'] = $options['temperature'];
		}
		if ( isset( $options['max_tokens'] ) ) {
			$body['max_tokens'] = $options['max_tokens'];
		}
		if ( isset( $options['response_format'] ) ) {
			$body['response_format'] = $options['response_format'];
		}

		$last_error = '';

		$base_url     = $this->get_api_base_url();
		$base_host    = (string) wp_parse_url( $base_url, PHP_URL_HOST );
		$cache_ttl    = $this->get_cache_ttl_seconds();
		$via_gateway  = self::DEFAULT_API_BASE_URL !== $base_url;

		// Build headers once so the wp_remote_post call and the cURL belt-and-
		// suspenders filter stay in lockstep. The cf-aig-* headers are only
		// meaningful when going through a Cloudflare AI Gateway; they are
		// ignored by direct OpenRouter calls, so sending them unconditionally
		// when a gateway is configured is safe and keeps the code branch-free.
		$request_headers = [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
			'HTTP-Referer'  => home_url(),
			'X-Title'       => 'PRAutoBlogger WordPress Plugin',
		];
		if ( $via_gateway && $cache_ttl > 0 ) {
			$request_headers['cf-aig-cache-ttl'] = (string) $cache_ttl;
		}

		// Belt-and-suspenders: inject Authorization header at cURL level.
		// Some hosting environments (Hostinger, certain proxies) strip the
		// Authorization header from wp_remote_post's 'headers' array before
		// the request is sent. The http_api_curl action fires after WordPress
		// configures the cURL handle but before curl_exec — re-setting
		// CURLOPT_HTTPHEADER here ensures the header reaches the upstream
		// (either OpenRouter directly or the Cloudflare AI Gateway proxy).
		$curl_auth_filter = function ( $handle, $parsed_args, $url ) use ( $request_headers, $base_host ): void {
			// Scope to the configured upstream host only — never leak auth
			// into unrelated outbound requests made elsewhere in WordPress.
			if ( '' === $base_host || false === strpos( (string) $url, $base_host ) ) {
				return;
			}
			$curl_headers = [];
			foreach ( $request_headers as $name => $value ) {
				$curl_headers[] = $name . ': ' . $value;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_HTTPHEADER, $curl_headers );
		};
		add_action( 'http_api_curl', $curl_auth_filter, 99, 3 );

		try {
			for ( $attempt = 1; $attempt <= PRAUTOBLOGGER_MAX_RETRIES; $attempt++ ) {
				$response = wp_remote_post(
					$base_url . '/chat/completions',
					[
						'timeout' => PRAUTOBLOGGER_API_TIMEOUT_SECONDS,
						'headers' => $request_headers,
						'body'    => wp_json_encode( $body ),
					]
				);

				if ( is_wp_error( $response ) ) {
					$last_error = $response->get_error_message();
					PRAutoBlogger_Logger::instance()->warning(
						sprintf( 'OpenRouter request failed (attempt %d/%d): %s', $attempt, PRAUTOBLOGGER_MAX_RETRIES, $last_error ),
						'openrouter'
					);

					if ( $attempt < PRAUTOBLOGGER_MAX_RETRIES ) {
						$delay = PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS * pow( 2, $attempt - 1 );
						sleep( (int) $delay );
					}
					continue;
				}

				$status_code = wp_remote_retrieve_response_code( $response );
				$body_raw    = wp_remote_retrieve_body( $response );
				$data        = json_decode( $body_raw, true );

				// Rate limited (429) or server error (5xx) — retry with backoff.
				if ( 429 === $status_code || $status_code >= 500 ) {
					$last_error = sprintf( 'HTTP %d: %s', $status_code, $body_raw );
					PRAutoBlogger_Logger::instance()->warning(
						sprintf( 'OpenRouter HTTP %d (attempt %d/%d): %s', $status_code, $attempt, PRAUTOBLOGGER_MAX_RETRIES, substr( $body_raw, 0, 500 ) ),
						'openrouter'
					);

					if ( $attempt < PRAUTOBLOGGER_MAX_RETRIES ) {
						// Respect Retry-After header if present.
						$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
						$delay       = $retry_after
							? min( (int) $retry_after, 60 )
							: PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS * pow( 2, $attempt - 1 );
						sleep( (int) $delay );
					}
					continue;
				}

				// Client error — don't retry, fail immediately.
				if ( $status_code >= 400 ) {
					$error_msg = isset( $data['error']['message'] )
						? $data['error']['message']
						: 'HTTP ' . $status_code;

					// Log detailed diagnostic info for auth errors to aid debugging.
					if ( 401 === $status_code || 403 === $status_code ) {
						PRAutoBlogger_Logger::instance()->error(
							sprintf(
								'Auth failure HTTP %d: key_prefix=%s, key_len=%d, error=%s',
								$status_code,
								substr( $api_key, 0, 8 ),
								strlen( $api_key ),
								$error_msg
							),
							'openrouter'
						);
					}

					throw new \RuntimeException(
						sprintf(
							/* translators: %d: HTTP status code, %s: error message */
							__( 'OpenRouter API error (HTTP %1$d): %2$s', 'prautoblogger' ),
							$status_code,
							$error_msg
						)
					);
				}

				// Success — parse response.
				if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
					throw new \RuntimeException(
						__( 'OpenRouter returned unexpected response format.', 'prautoblogger' )
					);
				}

				$usage = $data['usage'] ?? [];

				return [
					'content'           => $data['choices'][0]['message']['content'],
					'model'             => $data['model'] ?? $model,
					'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
					'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
					'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
					'finish_reason'     => $data['choices'][0]['finish_reason'] ?? 'unknown',
				];
			}

			// All retries exhausted.
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: max retries, %s: last error message */
					__( 'OpenRouter API failed after %1$d attempts. Last error: %2$s', 'prautoblogger' ),
					PRAUTOBLOGGER_MAX_RETRIES,
					$last_error
				)
			);
		} finally {
			// Always clean up the cURL filter to avoid leaking into other requests.
			remove_action( 'http_api_curl', $curl_auth_filter, 99 );
		}
	}

	/**
	 * Get available models from OpenRouter's /models endpoint.
	 *
	 * Caches the result in a transient for 24 hours to avoid repeated API calls.
	 *
	 * Side effects: HTTP request (if cache miss), sets transient.
	 *
	 * @return array<int, array{id: string, name: string, context_length: int, pricing: array{prompt: float, completion: float}}>
	 */
	public function get_available_models(): array {
		$pricing = new PRAutoBlogger_OpenRouter_Pricing();
		return $pricing->get_available_models();
	}

	/**
	 * Estimate the cost of an API call in USD.
	 *
	 * Uses hardcoded pricing first, falls back to cached model data.
	 *
	 * @param string $model            Model identifier.
	 * @param int    $prompt_tokens     Input tokens.
	 * @param int    $completion_tokens Output tokens.
	 *
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( string $model, int $prompt_tokens, int $completion_tokens ): float {
		$pricing = new PRAutoBlogger_OpenRouter_Pricing();
		return $pricing->estimate_cost( $model, $prompt_tokens, $completion_tokens );
	}

	/**
	 * @return string
	 */
	public function get_provider_name(): string {
		return 'OpenRouter';
	}

	/**
	 * Validate that the OpenRouter API key is configured and working.
	 *
	 * Makes a lightweight request to the /auth/key endpoint.
	 *
	 * Side effects: HTTP request to OpenRouter, logs diagnostic info.
	 *
	 * @return bool
	 */
	public function validate_credentials(): bool {
		$diag = $this->validate_credentials_detailed();
		return 'ok' === $diag['status'];
	}

	/**
	 * Validate credentials with detailed diagnostic info.
	 *
	 * Returns an array with status ('ok' or 'error') and a human-readable
	 * message explaining what went wrong if validation fails.
	 *
	 * Side effects: HTTP request to OpenRouter, logs diagnostic info.
	 *
	 * @return array{status: string, message: string, debug?: string}
	 */
	public function validate_credentials_detailed(): array {
		$encrypted = get_option( 'prautoblogger_openrouter_api_key', '' );
		if ( '' === $encrypted ) {
			return [
				'status'  => 'error',
				'message' => __( 'No API key saved. Enter your OpenRouter key in settings.', 'prautoblogger' ),
				'debug'   => 'option_empty',
			];
		}

		$api_key = PRAutoBlogger_Encryption::decrypt( $encrypted );
		if ( '' === $api_key ) {
			return [
				'status'  => 'error',
				'message' => __( 'API key decryption failed. Re-enter your key.', 'prautoblogger' ),
				'debug'   => 'decrypt_failed:encrypted_len=' . strlen( $encrypted ),
			];
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
			return [
				'status'  => 'error',
				'message' => __( 'API key appears corrupted (decryption produced invalid data). Your WordPress auth salt may have changed. Please re-enter your OpenRouter API key in settings.', 'prautoblogger' ),
				'debug'   => sprintf( 'bad_format:prefix=%s,len=%d', $key_prefix, $key_len ),
			];
		}

		// Belt-and-suspenders: inject Authorization at cURL level (see send_chat_completion).
		$base_url              = $this->get_api_base_url();
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
				'timeout' => 15,
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
			return [
				'status'  => 'error',
				'message' => sprintf( __( 'Network error reaching OpenRouter: %s', 'prautoblogger' ), $err_msg ),
				'debug'   => 'wp_error:' . $err_msg,
			];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );

		if ( 200 === $status_code ) {
			return [
				'status'  => 'ok',
				'message' => __( 'OpenRouter connected.', 'prautoblogger' ),
			];
		}

		PRAutoBlogger_Logger::instance()->warning(
			sprintf( 'OpenRouter credential check HTTP %d (key_prefix=%s, key_len=%d): %s', $status_code, $key_prefix, $key_len, substr( $body_raw, 0, 300 ) ),
			'openrouter'
		);

		return [
			'status'  => 'error',
			'message' => sprintf( __( 'OpenRouter returned HTTP %d. %s', 'prautoblogger' ), $status_code, substr( $body_raw, 0, 200 ) ),
			'debug'   => sprintf( 'http_%d:key_prefix=%s,key_len=%d', $status_code, $key_prefix, $key_len ),
		];
	}

	/**
	 * Get the decrypted API key from options.
	 *
	 * @return string Decrypted API key, or empty string if not set.
	 */
	private function get_api_key(): string {
		$encrypted = get_option( 'prautoblogger_openrouter_api_key', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return PRAutoBlogger_Encryption::decrypt( $encrypted );
	}
}
