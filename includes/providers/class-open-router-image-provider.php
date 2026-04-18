<?php
declare(strict_types=1);

/**
 * OpenRouter image provider — FLUX.2, Seedream, GPT-Image, Gemini Flash.
 *
 * Calls the standard OpenRouter chat/completions endpoint with
 * `modalities: ["image"]`. Reuses the same API key and optional
 * Cloudflare AI Gateway as the LLM provider.
 *
 * Triggered by: PRAutoBlogger_Image_Pipeline during the content generation
 *               run, after editorial review, before publish. Also triggered
 *               by the admin "Test Connection" action.
 * Dependencies: PRAutoBlogger_OpenRouter_Image_Support (key, parsing, retry),
 *               PRAutoBlogger_OpenRouter_Config (base URL + gateway),
 *               PRAutoBlogger_OpenRouter_Request_Builder (headers + cURL fix),
 *               PRAutoBlogger_OpenRouter_Image_Pricing (model + cost),
 *               PRAutoBlogger_Logger.
 *
 * @see interface-image-provider.php              Interface this class implements.
 * @see class-openrouter-image-support.php        Key lookup, response parsing, retry.
 * @see class-openrouter-image-pricing.php        Cost + model helpers.
 * @see class-open-router-config.php              Base URL / gateway resolution.
 * @see class-open-router-request-builder.php     Header building + cURL auth filter.
 * @see class-cloudflare-image-provider.php       Sibling provider; same retry pattern.
 * @see ARCHITECTURE.md                           External API Integrations table.
 */
class PRAutoBlogger_OpenRouter_Image_Provider implements PRAutoBlogger_Image_Provider_Interface {

	private const PROVIDER_ID      = 'openrouter';
	private const MIN_DIMENSION_PX = 64;
	private const MAX_DIMENSION_PX = 2048;

	/** @var PRAutoBlogger_OpenRouter_Image_Pricing */
	private PRAutoBlogger_OpenRouter_Image_Pricing $pricing;

	/** @var PRAutoBlogger_OpenRouter_Image_Support */
	private PRAutoBlogger_OpenRouter_Image_Support $support;

	/** @var PRAutoBlogger_OpenRouter_Config */
	private PRAutoBlogger_OpenRouter_Config $config;

	/** @var PRAutoBlogger_OpenRouter_Request_Builder */
	private PRAutoBlogger_OpenRouter_Request_Builder $request_builder;

	public function __construct() {
		$this->pricing         = new PRAutoBlogger_OpenRouter_Image_Pricing();
		$this->support         = new PRAutoBlogger_OpenRouter_Image_Support();
		$this->config          = new PRAutoBlogger_OpenRouter_Config();
		$this->request_builder = new PRAutoBlogger_OpenRouter_Request_Builder();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Sends a chat/completions request with modalities=["image"] and parses
	 * the base64 image from the response. Retries 5xx/429 with exponential
	 * backoff. 4xx (except 429) fail immediately.
	 *
	 * Side effects: HTTP request per attempt, Logger line per attempt.
	 *
	 * @throws \InvalidArgumentException If dimensions out of range or prompt empty.
	 * @throws \RuntimeException         On auth failure, bad response, or retry exhaustion.
	 */
	public function generate_image( string $prompt, int $width, int $height, array $options = [] ): array {
		$this->assert_valid_dimensions( $width, $height );
		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			throw new \InvalidArgumentException(
				esc_html__( 'Image prompt cannot be empty.', 'prautoblogger' )
			);
		}

		$api_key = $this->support->get_api_key();
		$model   = $this->pricing->resolve_model( (string) ( $options['model'] ?? '' ) );
		$body    = $this->build_request_body( $prompt, $width, $height, $model );

		$base_url = $this->config->get_api_base_url();
		$url      = $base_url . '/chat/completions';
		$headers  = $this->request_builder->build_headers(
			$api_key,
			$this->config->is_via_gateway(),
			0 // No caching for image gen — every call should be unique.
		);

		$curl_filter = $this->request_builder->register_curl_auth_filter(
			$headers,
			wp_parse_url( $base_url, PHP_URL_HOST ) ?: ''
		);

		$started_at = microtime( true );
		$last_error = '';

		try {
			for ( $attempt = 1; $attempt <= PRAUTOBLOGGER_MAX_RETRIES; $attempt++ ) {
				$response = wp_remote_post( $url, [
					'timeout' => PRAUTOBLOGGER_API_TIMEOUT_SECONDS,
					'headers' => $headers,
					'body'    => wp_json_encode( $body ),
				] );

				if ( is_wp_error( $response ) ) {
					$last_error = $response->get_error_message();
					$this->support->log_retry( $attempt, 'network', $last_error );
					$this->support->backoff( $attempt );
					continue;
				}

				$status = (int) wp_remote_retrieve_response_code( $response );
				$raw    = (string) wp_remote_retrieve_body( $response );

				if ( 429 === $status || $status >= 500 ) {
					$last_error = sprintf( 'HTTP %d: %s', $status, substr( $raw, 0, 300 ) );
					$this->support->log_retry( $attempt, (string) $status, $last_error );
					if ( $attempt < PRAUTOBLOGGER_MAX_RETRIES ) {
						$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
						$this->support->backoff( $attempt, $retry_after );
					}
					continue;
				}

				if ( $status >= 400 ) {
					PRAutoBlogger_Logger::instance()->error(
						sprintf( 'OpenRouter image gen HTTP %d: %s', $status, substr( $raw, 0, 300 ) ),
						'openrouter-image'
					);
					throw new \RuntimeException(
						sprintf(
							esc_html__( 'OpenRouter image error (HTTP %1$d): %2$s', 'prautoblogger' ),
							$status,
							esc_html( substr( $raw, 0, 300 ) )
						)
					);
				}

				$image_bytes = $this->support->extract_image_bytes( $raw );

				return [
					'bytes'      => $image_bytes,
					'mime_type'  => 'image/png',
					'width'      => $width,
					'height'     => $height,
					'model'      => $model,
					'seed'       => null,
					'cost_usd'   => $this->pricing->estimate_cost( $width, $height, $model ),
					'latency_ms' => (int) ( ( microtime( true ) - $started_at ) * 1000 ),
				];
			}
		} finally {
			remove_action( 'http_api_curl', $curl_filter, 99 );
		}

		throw new \RuntimeException(
			sprintf(
				esc_html__( 'OpenRouter image gen failed after %1$d attempts. Last error: %2$s', 'prautoblogger' ),
				PRAUTOBLOGGER_MAX_RETRIES,
				esc_html( $last_error )
			)
		);
	}

	/** {@inheritDoc} */
	public function estimate_cost( int $width, int $height, array $options = [] ): float {
		$model = $this->pricing->resolve_model( (string) ( $options['model'] ?? '' ) );
		return $this->pricing->estimate_cost( $width, $height, $model );
	}

	/** {@inheritDoc} */
	public function get_provider_name(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * Verify credentials by calling OpenRouter's /auth/key endpoint.
	 *
	 * {@inheritDoc}
	 */
	public function validate_credentials_detailed(): array {
		try {
			$api_key = $this->support->get_api_key();
		} catch ( \RuntimeException $e ) {
			return [
				'status'  => 'error',
				'message' => $e->getMessage(),
			];
		}

		$response = wp_remote_get( 'https://openrouter.ai/api/v1/auth/key', [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'status'  => 'error',
				'message' => __( 'Could not reach OpenRouter API.', 'prautoblogger' ),
				'debug'   => $response->get_error_message(),
			];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			return [
				'status'  => 'ok',
				'message' => __( 'OpenRouter API key valid. Image generation ready.', 'prautoblogger' ),
			];
		}

		return [
			'status'  => 'error',
			'message' => sprintf( __( 'OpenRouter returned HTTP %d.', 'prautoblogger' ), $status ),
			'debug'   => substr( (string) wp_remote_retrieve_body( $response ), 0, 200 ),
		];
	}

	/**
	 * Build the chat/completions request body for image generation.
	 *
	 * @param string $prompt Image generation prompt.
	 * @param int    $width  Target width.
	 * @param int    $height Target height.
	 * @param string $model  Resolved model id.
	 * @return array<string, mixed>
	 */
	private function build_request_body( string $prompt, int $width, int $height, string $model ): array {
		$gcd    = $this->support->gcd( $width, $height );
		$ar_w   = (int) ( $width / $gcd );
		$ar_h   = (int) ( $height / $gcd );
		$aspect = $ar_w . ':' . $ar_h;

		return [
			'model'      => $model,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => 'Generate an image: ' . $prompt,
				],
			],
			// Use ["image", "text"] — some providers (Gemini) require both
			// modalities even when we only want the image output.
			'modalities'   => [ 'image', 'text' ],
			'image_config' => [
				'aspect_ratio' => $aspect,
			],
		];
	}

	/**
	 * Enforce the interface's dimension contract.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @throws \InvalidArgumentException If outside [MIN, MAX].
	 */
	private function assert_valid_dimensions( int $width, int $height ): void {
		if (
			$width < self::MIN_DIMENSION_PX || $width > self::MAX_DIMENSION_PX ||
			$height < self::MIN_DIMENSION_PX || $height > self::MAX_DIMENSION_PX
		) {
			throw new \InvalidArgumentException(
				sprintf(
					esc_html__( 'Image dimensions %1$dx%2$d outside supported range (%3$d–%4$d px).', 'prautoblogger' ),
					$width, $height, self::MIN_DIMENSION_PX, self::MAX_DIMENSION_PX
				)
			);
		}
	}
}
