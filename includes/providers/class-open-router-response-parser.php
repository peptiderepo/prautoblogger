<?php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
declare(strict_types=1);

/**
 * Response parser for OpenRouter API responses.
 *
 * Encapsulates the logic for parsing and validating OpenRouter chat completion
 * responses, including error classification and retry-decision logic.
 *
 * Triggered by: PRAutoBlogger_OpenRouter_Provider::send_chat_completion()
 * Dependencies: PRAutoBlogger_Logger (for error/warning logging).
 *
 * @see class-open-router-provider.php — Parent class that uses this parser.
 */
class PRAutoBlogger_OpenRouter_Response_Parser {

	/**
	 * Determine if an HTTP status code indicates a retryable error.
	 *
	 * Returns true if the failure is transient (5xx, 429) and the retry loop
	 * should continue with backoff. Returns false for 4xx errors, which should
	 * fail immediately without retry.
	 *
	 * @param int $status_code HTTP status code.
	 *
	 * @return bool
	 */
	public function is_retryable( int $status_code ): bool {
		return 429 === $status_code || $status_code >= 500;
	}

	/**
	 * Parse a successful OpenRouter response.
	 *
	 * Validates the response contains the required fields and extracts
	 * the completion content and token usage.
	 *
	 * @param array  $data    Decoded JSON response from OpenRouter.
	 * @param string $model   The model that was requested (fallback if not in response).
	 *
	 * @return array{
	 *     content: string,
	 *     model: string,
	 *     prompt_tokens: int,
	 *     completion_tokens: int,
	 *     reasoning_tokens: int,
	 *     total_tokens: int,
	 *     finish_reason: string,
	 *     reasoning_content: string,
	 * }
	 *
	 * @throws \RuntimeException If response structure is invalid.
	 */
	public function parse_success( array $data, string $model ): array {
		// OpenRouter routers (e.g. openrouter/free) may wrap upstream provider
		// errors inside an HTTP 200 body with an `error` object. Surface them
		// as RuntimeExceptions so the retry loop or caller can handle them.
		if ( isset( $data['error']['message'] ) ) {
			$upstream_code = (int) ( $data['error']['code'] ?? 0 );
			throw new \RuntimeException(
				sprintf(
					/* translators: %1$d: upstream HTTP code, %2$s: error message */
					__( 'OpenRouter upstream error (code %1$d): %2$s', 'prautoblogger' ),
					$upstream_code,
					$data['error']['message']
				)
			);
		}

		// Validate we have a choices array with at least one message.
		if ( ! isset( $data['choices'][0]['message'] ) ) {
			// Log the raw shape so we can diagnose what the model actually returned.
			PRAutoBlogger_Logger::instance()->error(
				sprintf(
					'Unexpected response shape from %s: %s',
					$data['model'] ?? $model,
					substr( (string) wp_json_encode( $data ), 0, 500 )
				),
				'openrouter'
			);
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: model name */
					__( 'OpenRouter model %s returned unexpected response format.', 'prautoblogger' ),
					$data['model'] ?? $model
				)
			);
		}

		// Content can be null (some models use tool_calls or refusal instead).
		// Treat null as empty string — downstream callers handle empty content.
		$content = $data['choices'][0]['message']['content'] ?? '';
		if ( null === $content ) {
			$content = '';
		}

		$usage = $data['usage'] ?? array();

		// Reasoning content lives alongside 'content' in the message object.
		$reasoning_content = $data['choices'][0]['message']['reasoning'] ?? '';

		return array(
			'content'           => (string) $content,
			'model'             => $data['model'] ?? $model,
			'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
			'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
			'reasoning_tokens'  => (int) ( $usage['reasoning_tokens'] ?? 0 ),
			'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
			'finish_reason'     => $data['choices'][0]['finish_reason'] ?? 'unknown',
			'reasoning_content' => (string) $reasoning_content,
		);
	}

	/**
	 * Extract the error message from a failed response.
	 *
	 * Prefers the `error.message` field if present; falls back to the
	 * HTTP status code as a string.
	 *
	 * @param array $data   Decoded JSON response (may be empty or malformed).
	 * @param int   $status HTTP status code.
	 *
	 * @return string Human-readable error message.
	 */
	public function get_error_message( array $data, int $status ): string {
		return isset( $data['error']['message'] )
			? $data['error']['message']
			: 'HTTP ' . $status;
	}
}
