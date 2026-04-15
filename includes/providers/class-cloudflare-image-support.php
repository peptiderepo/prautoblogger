<?php
declare(strict_types=1);

/**
 * Small support helpers for the Cloudflare image provider — credential
 * lookups, URL building, and structured log lines.
 *
 * Split out of the provider so the provider stays under the 300-line cap
 * and so these tiny pieces are individually testable. Pure functions plus
 * one logger forward; no state.
 *
 * Triggered by: PRAutoBlogger_Cloudflare_Image_Provider only.
 * Dependencies: PRAutoBlogger_Encryption (token decryption),
 *               PRAutoBlogger_Logger (WARN/ERROR forwarding).
 *
 * @see class-cloudflare-image-provider.php Sole caller.
 */
class PRAutoBlogger_Cloudflare_Image_Support {

	/**
	 * Decrypt the Cloudflare API token from the settings option.
	 *
	 * @return string Plaintext token, or empty string if not set / decrypt fails.
	 */
	public function get_api_token(): string {
		$encrypted = (string) get_option( 'prautoblogger_cloudflare_ai_token', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return (string) PRAutoBlogger_Encryption::decrypt( $encrypted );
	}

	/**
	 * Read the Cloudflare account ID from settings.
	 *
	 * @return string Account UUID, or empty string.
	 */
	public function get_account_id(): string {
		return trim( (string) get_option( 'prautoblogger_cloudflare_account_id', '' ) );
	}

	/**
	 * Build the Workers AI run URL for a given account + model.
	 *
	 * @param string $account_id Cloudflare account UUID.
	 * @param string $model      Fully-qualified Workers AI model id.
	 * @return string            Fully-formed HTTPS URL.
	 */
	public function build_endpoint_url( string $account_id, string $model ): string {
		return sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
			rawurlencode( $account_id ),
			ltrim( $model, '/' )
		);
	}

	/**
	 * Emit a WARN line for a retryable failure.
	 *
	 * @param int    $attempt       1-based attempt number.
	 * @param string $failure_class Either 'network' or HTTP status code as string.
	 * @param string $detail        Short failure detail for the log.
	 * @return void
	 */
	public function log_retryable_failure( int $attempt, string $failure_class, string $detail ): void {
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

	/**
	 * Log an unrecoverable 4xx with triage context. Never logs the full
	 * secret — only a 4-char token prefix and a 6-char account prefix.
	 *
	 * @param int    $status     HTTP status returned by Cloudflare.
	 * @param string $api_token  Plaintext token.
	 * @param string $account_id Account UUID.
	 * @param string $raw        Raw response body (truncated to 300 chars in the log).
	 * @return void
	 */
	public function log_client_error( int $status, string $api_token, string $account_id, string $raw ): void {
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
}
