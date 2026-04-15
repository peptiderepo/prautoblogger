<?php
declare(strict_types=1);

/**
 * Cloudflare Workers AI image provider — FLUX.1 family.
 *
 * Calls `https://api.cloudflare.com/client/v4/accounts/{id}/ai/run/{model}`
 * directly. We bypass the Cloudflare AI Gateway for v1 because the gateway
 * route to Workers AI currently 403s (see decision D-001); the OpenRouter
 * gateway path is unaffected.
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline (commit 1b) during the content
 *               generation run, after editorial review, before publish. Also
 *               triggered by the admin "Test Connection" action.
 * Dependencies: PRAutoBlogger_Encryption (token decryption), PRAutoBlogger_Logger
 *               (WARN/ERROR lines), PRAutoBlogger_Cloudflare_Image_Pricing
 *               (model resolution + cost estimation).
 *
 * @see interface-image-provider.php           Interface this class implements.
 * @see class-cloudflare-image-pricing.php     Cost + model id helpers.
 * @see class-cloudflare-image-validator.php   Credential check helper.
 * @see class-open-router-provider.php         Sibling provider; same retry pattern.
 * @see ARCHITECTURE.md                        External API Integrations, key decision #16.
 */
class PRAutoBlogger_Cloudflare_Image_Provider implements PRAutoBlogger_Image_Provider_Interface {

	private const PROVIDER_ID           = 'cloudflare_workers_ai';
	private const MIN_DIMENSION_PX      = 64;
	private const MAX_DIMENSION_PX      = 2048;
	private const DEFAULT_STEPS_SCHNELL = 4;
	private const RETRY_AFTER_CAP_S     = 60;

	/**
	 * Pricing + model-id resolver. Constructed once per provider instance.
	 *
	 * @var PRAutoBlogger_Cloudflare_Image_Pricing
	 */
	private PRAutoBlogger_Cloudflare_Image_Pricing $pricing;

	public function __construct() {
		$this->pricing = new PRAutoBlogger_Cloudflare_Image_Pricing();
	}

	/**
	 * Generate a single image from a prompt at the requested dimensions.
	 *
	 * Retries 5xx / 429 / network errors with exponential backoff bounded
	 * by PRAUTOBLOGGER_MAX_RETRIES. 4xx other than 429 fail loudly on the
	 * first attempt — retrying a bad prompt or a bad token wastes money.
	 *
	 * Side effects: one HTTP request per attempt; one Logger line per attempt.
	 *
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If dimensions out of range or prompt empty.
	 * @throws \RuntimeException         On auth failure, bad response shape, or retry exhaustion.
	 */
	public function generate_image( string $prompt, int $width, int $height, array $options = [] ): array {
		$this->assert_valid_dimensions( $width, $height );
		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			throw new \InvalidArgumentException(
				esc_html__( 'Image prompt cannot be empty.', 'prautoblogger' )
			);
		}

		$account_id = $this->get_account_id();
		$api_token  = $this->get_api_token();
		$model      = $this->pricing->resolve_model( (string) ( $options['model'] ?? '' ) );

		$body = [
			'prompt' => $prompt,
			'width'  => $width,
			'height' => $height,
			'steps'  => (int) ( $options['steps'] ?? self::DEFAULT_STEPS_SCHNELL ),
		];
		if ( isset( $options['seed'] ) ) {
			$body['seed'] = (int) $options['seed'];
		}

		$url        = $this->build_endpoint_url( $account_id, $model );
		$headers    = [
			'Authorization' => 'Bearer ' . $api_token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'image/*',
		];
		$started_at = microtime( true );
		$last_error = '';

		for ( $attempt = 1; $attempt <= PRAUTOBLOGGER_MAX_RETRIES; $attempt++ ) {
			$response = wp_remote_post(
				$url,
				[
					'timeout' => PRAUTOBLOGGER_API_TIMEOUT_SECONDS,
					'headers' => $headers,
					'body'    => wp_json_encode( $body ),
				]
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				$this->log_retryable_failure( $attempt, 'network', $last_error );
				$this->sleep_for_attempt( $attempt );
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );

			if ( 429 === $status || $status >= 500 ) {
				$last_error = sprintf( 'HTTP %d: %s', $status, substr( $raw, 0, 300 ) );
				$this->log_retryable_failure( $attempt, (string) $status, $last_error );
				if ( $attempt < PRAUTOBLOGGER_MAX_RETRIES ) {
					$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
					$this->sleep_for_attempt( $attempt, $retry_after );
				}
				continue;
			}

			if ( $status >= 400 ) {
				$this->log_client_error( $status, $api_token, $account_id, $raw );
				throw new \RuntimeException(
					sprintf(
						/* translators: %1$d: HTTP status, %2$s: error body. */
						esc_html__( 'Cloudflare Workers AI error (HTTP %1$d): %2$s', 'prautoblogger' ),
						$status,
						substr( $raw, 0, 300 )
					)
				);
			}

			// Success. FLUX on Workers AI returns either raw image bytes
			// (Content-Type: image/*) or a JSON envelope with base64 under
			// `result.image`. Handle both without branching the caller.
			$mime           = (string) wp_remote_retrieve_header( $response, 'content-type' );
			[ $raw, $mime ] = $this->normalize_response_body( $raw, $mime );

			return [
				'bytes'      => $raw,
				'mime_type'  => $mime,
				'width'      => $width,
				'height'     => $height,
				'model'      => $model,
				'seed'       => isset( $body['seed'] ) ? (int) $body['seed'] : null,
				'cost_usd'   => $this->pricing->estimate_cost( $width, $height, $model ),
				'latency_ms' => (int) ( ( microtime( true ) - $started_at ) * 1000 ),
			];
		}

		throw new \RuntimeException(
			sprintf(
				/* translators: %1$d: max retries, %2$s: last error. */
				esc_html__( 'Cloudflare Workers AI failed after %1$d attempts. Last error: %2$s', 'prautoblogger' ),
				PRAUTOBLOGGER_MAX_RETRIES,
				$last_error
			)
		);
	}

	/**
	 * Estimate the USD cost of a single generation call.
	 *
	 * {@inheritDoc}
	 */
	public function estimate_cost( int $width, int $height, array $options = [] ): float {
		$model = $this->pricing->resolve_model( (string) ( $options['model'] ?? '' ) );
		return $this->pricing->estimate_cost( $width, $height, $model );
	}

	/**
	 * @return string Machine-readable provider id used in logs and settings.
	 */
	public function get_provider_name(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * Verify credentials are present and the API is reachable.
	 *
	 * Delegates to PRAutoBlogger_Cloudflare_Image_Validator so the HTTP probe
	 * and error-shape code can be tested and sized independently.
	 *
	 * Side effects: one HTTP GET via the validator; one Logger line on failure.
	 *
	 * @return array{status: string, message: string, debug?: string}
	 */
	public function validate_credentials_detailed(): array {
		return ( new PRAutoBlogger_Cloudflare_Image_Validator() )->run();
	}

	/** Enforce the interface's dimension contract. */
	private function assert_valid_dimensions( int $width, int $height ): void {
		if (
			$width < self::MIN_DIMENSION_PX || $width > self::MAX_DIMENSION_PX ||
			$height < self::MIN_DIMENSION_PX || $height > self::MAX_DIMENSION_PX
		) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %1$d: width, %2$d: height, %3$d: min, %4$d: max. */
					esc_html__( 'Image dimensions %1$dx%2$d are outside the supported range (%3$d–%4$d px per axis).', 'prautoblogger' ),
					$width,
					$height,
					self::MIN_DIMENSION_PX,
					self::MAX_DIMENSION_PX
				)
			);
		}
	}

	/** Normalize a 2xx response into [bytes, mime-type] whether raw or JSON-wrapped. */
	private function normalize_response_body( string $raw, string $mime ): array {
		if ( '' !== $mime && 0 === stripos( $mime, 'image/' ) ) {
			return [ $raw, $mime ];
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && isset( $decoded['result']['image'] ) && is_string( $decoded['result']['image'] ) ) {
			$bytes = (string) base64_decode( $decoded['result']['image'], true );
			if ( '' !== $bytes ) {
				return [ $bytes, 'image/png' ];
			}
		}
		throw new \RuntimeException(
			esc_html__( 'Cloudflare Workers AI returned an unexpected response shape.', 'prautoblogger' )
		);
	}

	private function build_endpoint_url( string $account_id, string $model ): string {
		return sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
			rawurlencode( $account_id ),
			ltrim( $model, '/' )
		);
	}

	private function get_api_token(): string {
		$encrypted = (string) get_option( 'prautoblogger_cloudflare_ai_token', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return (string) PRAutoBlogger_Encryption::decrypt( $encrypted );
	}

	private function get_account_id(): string {
		return trim( (string) get_option( 'prautoblogger_cloudflare_account_id', '' ) );
	}

	private function log_retryable_failure( int $attempt, string $failure_class, string $detail ): void {
		PRAutoBlogger_Logger::instance()->warning(
			sprintf(
				'Cloudflare image gen %s failure (attempt %d/%d): %s',
				$failure_class,
				$attempt,
				PRAUTOBLOGGER_MAX_RETRIES,
				$detail
			),
			'cloudflare-image'
		);
	}

	/** Log an unrecoverable 4xx with triage context (never the full secret). */
	private function log_client_error( int $status, string $api_token, string $account_id, string $raw ): void {
		PRAutoBlogger_Logger::instance()->error(
			sprintf(
				'Cloudflare image gen HTTP %d (token_prefix=%s, token_len=%d, account=%s): %s',
				$status,
				substr( $api_token, 0, 4 ),
				strlen( $api_token ),
				substr( $account_id, 0, 6 ),
				substr( $raw, 0, 300 )
			),
			'cloudflare-image'
		);
	}

	/** Sleep between retries; Retry-After header wins when present, else exponential backoff. */
	private function sleep_for_attempt( int $attempt, int $retry_after_secs = 0 ): void {
		$backoff = PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS * (int) pow( 2, $attempt - 1 );
		$delay   = $retry_after_secs > 0 ? min( $retry_after_secs, self::RETRY_AFTER_CAP_S ) : $backoff;
		if ( $delay > 0 ) {
			sleep( $delay );
		}
	}

}
