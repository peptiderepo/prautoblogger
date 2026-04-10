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
 * Dependencies: Autoblogger_Encryption (for API key decryption), wp_remote_post(), Autoblogger_OpenRouter_Pricing.
 *
 * @see interface-llm-provider.php      — Interface this class implements.
 * @see class-openrouter-pricing.php    — Pricing and model list lookups.
 * @see class-cost-tracker.php          — Called after every request to log token usage.
 * @see ARCHITECTURE.md                 — Data flow diagram showing where this fits.
 */
class Autoblogger_OpenRouter_Provider implements Autoblogger_LLM_Provider_Interface {

	private const API_BASE_URL = 'https://openrouter.ai/api/v1';

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
				__( 'OpenRouter API key is not configured. Go to AutoBlogger → Settings.', 'autoblogger' )
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

		for ( $attempt = 1; $attempt <= AUTOBLOGGER_MAX_RETRIES; $attempt++ ) {
			$response = wp_remote_post(
				self::API_BASE_URL . '/chat/completions',
				[
					'timeout' => AUTOBLOGGER_API_TIMEOUT_SECONDS,
					'headers' => [
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
						'HTTP-Referer'  => home_url(),
						'X-Title'       => 'AutoBlogger WordPress Plugin',
					],
					'body'    => wp_json_encode( $body ),
				]
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				Autoblogger_Logger::instance()->warning(
					sprintf( 'OpenRouter request failed (attempt %d/%d): %s', $attempt, AUTOBLOGGER_MAX_RETRIES, $last_error ),
					'openrouter'
				);

				if ( $attempt < AUTOBLOGGER_MAX_RETRIES ) {
					$delay = AUTOBLOGGER_RETRY_BASE_DELAY_SECONDS * pow( 2, $attempt - 1 );
					sleep( (int) $delay );
				}
				continue;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body_raw    = wp_remote_retrieve_body( $response );
			$data        = json_decode( $body_raw, true );

			// Rate limited or server error — retry.
			if ( $status_code >= 429 || $status_code >= 500 ) {
				$last_error = sprintf( 'HTTP %d: %s', $status_code, $body_raw );
				Autoblogger_Logger::instance()->warning(
					sprintf( 'OpenRouter HTTP %d (attempt %d/%d): %s', $status_code, $attempt, AUTOBLOGGER_MAX_RETRIES, substr( $body_raw, 0, 500 ) ),
					'openrouter'
				);

				if ( $attempt < AUTOBLOGGER_MAX_RETRIES ) {
					// Respect Retry-After header if present.
					$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
					$delay       = $retry_after
						? min( (int) $retry_after, 60 )
						: AUTOBLOGGER_RETRY_BASE_DELAY_SECONDS * pow( 2, $attempt - 1 );
					sleep( (int) $delay );
				}
				continue;
			}

			// Client error — don't retry, fail immediately.
			if ( $status_code >= 400 ) {
				$error_msg = isset( $data['error']['message'] )
					? $data['error']['message']
					: 'HTTP ' . $status_code;
				throw new \RuntimeException(
					sprintf(
						/* translators: %d: HTTP status code, %s: error message */
						__( 'OpenRouter API error (HTTP %1$d): %2$s', 'autoblogger' ),
						$status_code,
						$error_msg
					)
				);
			}

			// Success — parse response.
			if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
				throw new \RuntimeException(
					__( 'OpenRouter returned unexpected response format.', 'autoblogger' )
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
				__( 'OpenRouter API failed after %1$d attempts. Last error: %2$s', 'autoblogger' ),
				AUTOBLOGGER_MAX_RETRIES,
				$last_error
			)
		);
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
		$pricing = new Autoblogger_OpenRouter_Pricing();
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
		$pricing = new Autoblogger_OpenRouter_Pricing();
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
	 * Side effects: HTTP request to OpenRouter.
	 *
	 * @return bool
	 */
	public function validate_credentials(): bool {
		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return false;
		}

		$response = wp_remote_get(
			self::API_BASE_URL . '/auth/key',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Get the decrypted API key from options.
	 *
	 * @return string Decrypted API key, or empty string if not set.
	 */
	private function get_api_key(): string {
		$encrypted = get_option( 'autoblogger_openrouter_api_key', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return Autoblogger_Encryption::decrypt( $encrypted );
	}
}
