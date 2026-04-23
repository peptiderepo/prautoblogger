<?php
declare(strict_types=1);

/**
 * Opik REST API HTTP client.
 *
 * Minimal PHP HTTP client for posting traces and spans to Comet Opik.
 * Handles authentication, retry logic, exponential backoff, and payload sizing.
 *
 * Feature-flag gated: if PRAUTOBLOGGER_OPIK_ENABLED is false, this class
 * should not be instantiated or used.
 *
 * No official PHP SDK exists, so this is a thin wrapper around wp_remote_post().
 *
 * @see https://www.comet.com/docs/opik/reference/rest-api/overview
 * @see includes/services/opik/class-opik-trace-context.php
 * @see includes/services/opik/class-opik-dispatcher.php
 */
class PRAutoBlogger_Opik_Client {

	/**
	 * @var string Base URL for Opik API (can be overridden via constant).
	 */
	private string $base_url;

	/**
	 * @var string Opik API key (from PRAUTOBLOGGER_OPIK_API_KEY constant).
	 */
	private string $api_key;

	/**
	 * @var string Opik workspace name (from PRAUTOBLOGGER_OPIK_WORKSPACE constant).
	 */
	private string $workspace;

	/**
	 * @var int HTTP timeout in seconds.
	 */
	private int $timeout;

	/**
	 * Constructor.
	 *
	 * @param string $api_key   Opik API key. Use empty string if disabled.
	 * @param string $workspace Opik workspace (e.g. 'peptiderepo'). Use empty if disabled.
	 * @param string $base_url  Base URL for Opik API. Defaults to https://www.comet.com/opik/api/
	 * @param int    $timeout   HTTP timeout in seconds. Defaults to 30.
	 */
	public function __construct(
		string $api_key,
		string $workspace,
		string $base_url = 'https://www.comet.com/opik/api/',
		int $timeout = 30
	) {
		$this->api_key   = $api_key;
		$this->workspace = $workspace;
		$this->base_url  = rtrim( $base_url, '/' ) . '/';
		$this->timeout   = $timeout;
	}

	/**
	 * Create a trace (top-level span container for one article generation).
	 *
	 * @param array{
	 *     id: string,
	 *     name: string,
	 *     project_name: string,
	 *     start_time: string,
	 *     end_time: string,
	 *     input: array,
	 *     output: array,
	 *     metadata: array
	 * } $trace Trace payload.
	 *
	 * @return array|null Response from Opik, or null on persistent failure.
	 */
	public function create_trace( array $trace ): ?array {
		$endpoint = 'v1/private/traces';
		return $this->post( $endpoint, $trace );
	}

	/**
	 * Create a single span (LLM call record).
	 *
	 * @param array{
	 *     id: string,
	 *     trace_id: string,
	 *     parent_span_id?: string,
	 *     name: string,
	 *     type: string,
	 *     start_time: string,
	 *     end_time: string,
	 *     input: array,
	 *     output: array,
	 *     model: string,
	 *     provider: string,
	 *     usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
	 * } $span Span payload.
	 *
	 * @return array|null Response from Opik, or null on persistent failure.
	 */
	public function create_span( array $span ): ?array {
		$endpoint = 'v1/private/spans';
		return $this->post( $endpoint, $span );
	}

	/**
	 * Batch create spans (preferred for high volume).
	 *
	 * @param array $spans Array of span payloads (max 100 per batch).
	 *
	 * @return array|null Response from Opik, or null on persistent failure.
	 */
	public function batch_create_spans( array $spans ): ?array {
		// Enforce batch size limit.
		if ( count( $spans ) > 100 ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Batch span count %d exceeds 100 limit; truncating', count( $spans ) ),
				'opik'
			);
			$spans = array_slice( $spans, 0, 100 );
		}

		$endpoint = 'v1/private/spans/batch';
		$payload  = array( 'spans' => $spans );
		return $this->post( $endpoint, $payload );
	}

	/**
	 * Batch create traces.
	 *
	 * @param array $traces Array of trace payloads (max 100 per batch).
	 *
	 * @return array|null Response from Opik, or null on persistent failure.
	 */
	public function batch_create_traces( array $traces ): ?array {
		// Enforce batch size limit.
		if ( count( $traces ) > 100 ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Batch trace count %d exceeds 100 limit; truncating', count( $traces ) ),
				'opik'
			);
			$traces = array_slice( $traces, 0, 100 );
		}

		$endpoint = 'v1/private/traces/batch';
		$payload  = array( 'traces' => $traces );
		return $this->post( $endpoint, $payload );
	}

	/**
	 * POST to Opik with exponential backoff retry on transient errors.
	 *
	 * Retries on: HTTP 5xx, timeout, network error.
	 * Does not retry on: 4xx (permanent errors).
	 *
	 * @param string $endpoint Relative endpoint path (e.g. 'v1/private/traces').
	 * @param array  $payload  JSON-serializable payload.
	 * @param int    $attempt  Internal retry counter (start at 0).
	 *
	 * @return array|null Decoded JSON response, or null on final failure.
	 */
	private function post( string $endpoint, array $payload, int $attempt = 0 ): ?array {
		$max_attempts = 3;

		$url     = $this->base_url . $endpoint;
		$headers = $this->build_headers();
		$body    = wp_json_encode( $payload );

		if ( false === $body ) {
			PRAutoBlogger_Logger::instance()->error(
				'Failed to JSON-encode Opik payload',
				'opik'
			);
			return null;
		}

		$args = array(
			'method'      => 'POST',
			'headers'     => $headers,
			'body'        => $body,
			'timeout'     => $this->timeout,
			'data_format' => 'body',
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf(
					'Opik HTTP error (attempt %d/%d): %s',
					$attempt + 1,
					$max_attempts,
					$response->get_error_message()
				),
				'opik'
			);

			// Retry on network/timeout errors.
			if ( $attempt < $max_attempts - 1 ) {
				$delay = (int) ( 2 ** $attempt ); // 1s, 2s, 4s
				sleep( $delay );
				return $this->post( $endpoint, $payload, $attempt + 1 );
			}

			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// Success.
		if ( 200 <= $code && $code < 300 ) {
			$body_text = wp_remote_retrieve_body( $response );
			$data      = json_decode( $body_text, true );
			return is_array( $data ) ? $data : array();
		}

		// Permanent client error.
		if ( 400 <= $code && $code < 500 ) {
			$body_text = wp_remote_retrieve_body( $response );
			PRAutoBlogger_Logger::instance()->error(
				sprintf(
					'Opik API error (HTTP %d): %s',
					$code,
					mb_substr( $body_text, 0, 200 )
				),
				'opik'
			);
			return null;
		}

		// Transient server error; retry with exponential backoff.
		if ( 500 <= $code && $code < 600 ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf(
					'Opik server error (HTTP %d, attempt %d/%d)',
					$code,
					$attempt + 1,
					$max_attempts
				),
				'opik'
			);

			if ( $attempt < $max_attempts - 1 ) {
				$delay = (int) ( 2 ** $attempt );
				sleep( $delay );
				return $this->post( $endpoint, $payload, $attempt + 1 );
			}

			return null;
		}

		// Unexpected status.
		PRAutoBlogger_Logger::instance()->warning(
			sprintf( 'Opik unexpected HTTP %d', $code ),
			'opik'
		);
		return null;
	}

	/**
	 * Build HTTP headers for Opik API requests.
	 *
	 * Auth: Authorization: <api_key> (raw, not Bearer)
	 * Workspace: Comet-Workspace: peptiderepo
	 *
	 * @return array<string, string>
	 */
	private function build_headers(): array {
		return array(
			'Content-Type'    => 'application/json',
			'Authorization'   => $this->api_key,
			'Comet-Workspace' => $this->workspace,
		);
	}
}
