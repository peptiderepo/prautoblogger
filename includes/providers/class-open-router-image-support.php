<?php
declare(strict_types=1);

/**
 * Support helpers for the OpenRouter image provider — API key lookup,
 * response parsing, retry backoff, and logging.
 *
 * Split from the provider to keep both files under the 300-line cap.
 *
 * Triggered by: PRAutoBlogger_OpenRouter_Image_Provider only.
 * Dependencies: PRAutoBlogger_Encryption, PRAutoBlogger_Logger.
 *
 * @see class-openrouter-image-provider.php — Sole caller.
 */
class PRAutoBlogger_OpenRouter_Image_Support {

	/**
	 * Get the decrypted OpenRouter API key from settings.
	 *
	 * @return string Plaintext key.
	 * @throws \RuntimeException If key is not configured or decrypt fails.
	 */
	public function get_api_key(): string {
		$stored = (string) get_option( 'prautoblogger_openrouter_api_key', '' );
		if ( '' === $stored ) {
			throw new \RuntimeException(
				__( 'OpenRouter API key is not configured. Go to PRAutoBlogger → Settings.', 'prautoblogger' )
			);
		}

		if ( PRAutoBlogger_Encryption::is_encrypted( $stored ) ) {
			$decrypted = PRAutoBlogger_Encryption::decrypt( $stored );
			if ( '' === $decrypted ) {
				throw new \RuntimeException(
					__( 'OpenRouter API key could not be decrypted. Re-enter in Settings.', 'prautoblogger' )
				);
			}
			return $decrypted;
		}

		return $stored;
	}

	/**
	 * Extract raw image bytes from OpenRouter's chat/completions response.
	 *
	 * The response contains choices[0].message.images[0].image_url.url
	 * as a data URI: "data:image/png;base64,{bytes}".
	 *
	 * @param string $raw Raw JSON response body.
	 * @return string Decoded image bytes.
	 * @throws \RuntimeException If the response doesn't contain an image.
	 */
	public function extract_image_bytes( string $raw ): string {
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException(
				esc_html__( 'OpenRouter returned invalid JSON.', 'prautoblogger' )
			);
		}

		$images = $decoded['choices'][0]['message']['images'] ?? [];
		if ( empty( $images ) ) {
			throw new \RuntimeException(
				esc_html__( 'OpenRouter response contained no images.', 'prautoblogger' )
			);
		}

		$data_url = $images[0]['image_url']['url'] ?? '';
		if ( '' === $data_url ) {
			throw new \RuntimeException(
				esc_html__( 'OpenRouter image URL is empty.', 'prautoblogger' )
			);
		}

		$comma_pos = strpos( $data_url, ',' );
		if ( false === $comma_pos ) {
			throw new \RuntimeException(
				esc_html__( 'OpenRouter image data URI is malformed.', 'prautoblogger' )
			);
		}

		$b64   = substr( $data_url, $comma_pos + 1 );
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || '' === $bytes ) {
			throw new \RuntimeException(
				esc_html__( 'OpenRouter image base64 decode failed.', 'prautoblogger' )
			);
		}

		return $bytes;
	}

	/**
	 * Emit a WARN line for a retryable image-gen failure.
	 *
	 * @param int    $attempt 1-based attempt number.
	 * @param string $class   'network' or HTTP status code.
	 * @param string $detail  Short failure detail.
	 */
	public function log_retry( int $attempt, string $class, string $detail ): void {
		PRAutoBlogger_Logger::instance()->warning(
			sprintf(
				'OpenRouter image gen %s failure (attempt %d/%d): %s',
				$class,
				$attempt,
				PRAUTOBLOGGER_MAX_RETRIES,
				$detail
			),
			'openrouter-image'
		);
	}

	/**
	 * Sleep between retries with exponential backoff.
	 *
	 * @param int $attempt     1-based attempt that just failed.
	 * @param int $retry_after Retry-After header value (0 if absent).
	 */
	public function backoff( int $attempt, int $retry_after = 0 ): void {
		$delay = $retry_after > 0
			? min( $retry_after, 60 )
			: PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS * (int) pow( 2, $attempt - 1 );
		if ( $delay > 0 ) {
			sleep( $delay );
		}
	}

	/**
	 * Greatest common divisor (for aspect ratio calculation).
	 *
	 * @param int $a First number.
	 * @param int $b Second number.
	 * @return int GCD.
	 */
	public function gcd( int $a, int $b ): int {
		while ( 0 !== $b ) {
			$t = $b;
			$b = $a % $b;
			$a = $t;
		}
		return max( $a, 1 );
	}
}
