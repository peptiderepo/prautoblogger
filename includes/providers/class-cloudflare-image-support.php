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
		$stored = (string) get_option( 'prautoblogger_cloudflare_ai_token', '' );
		if ( '' === $stored ) {
			return '';
		}

		// If the token is already encrypted (has "enc:" prefix), decrypt it.
		if ( PRAutoBlogger_Encryption::is_encrypted( $stored ) ) {
			return (string) PRAutoBlogger_Encryption::decrypt( $stored );
		}

		// Legacy: token was saved as plaintext before the field was added to
		// the encrypted list in sanitize_field(). Encrypt it now so future
		// reads go through the normal path, then return the plaintext value.
		$cipher = PRAutoBlogger_Encryption::encrypt( $stored );
		if ( '' !== $cipher ) {
			update_option( 'prautoblogger_cloudflare_ai_token', $cipher );
		}
		return $stored;
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
	 * By default routes through the Cloudflare AI Gateway we already use for
	 * OpenRouter — the gateway route to Workers AI was observed 403ing in
	 * April 2026 (see ARCHITECTURE.md #16), but manual probe on 2026-04-21
	 * shows it's now returning 200. Gateway routing gives us response caching,
	 * cost logging, rate limiting, and provider fallback for free.
	 *
	 * Direct-API fallback kicks in automatically when either the
	 * `prautoblogger_ai_gateway_base_url` option is empty (no gateway
	 * configured at all) or the new `prautoblogger_cf_image_via_gateway`
	 * toggle is off (explicit opt-out for if the gateway route regresses).
	 *
	 * @param string $account_id Cloudflare account UUID.
	 * @param string $model      Fully-qualified Workers AI model id (e.g. @cf/black-forest-labs/flux-1-schnell).
	 * @return string            Fully-formed HTTPS URL.
	 */
	public function build_endpoint_url( string $account_id, string $model ): string {
		$gateway_url = $this->build_gateway_workers_ai_url( $account_id, $model );
		if ( '' !== $gateway_url ) {
			return $gateway_url;
		}
		return sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
			rawurlencode( $account_id ),
			ltrim( $model, '/' )
		);
	}

	/**
	 * Derive a Workers AI URL rooted at the same AI Gateway we already use
	 * for OpenRouter. Returns empty string when the gateway isn't configured
	 * or the admin toggle opts out — the caller falls back to direct API.
	 *
	 * The existing `prautoblogger_ai_gateway_base_url` option points at
	 * `https://gateway.ai.cloudflare.com/v1/{account}/{gateway}/openrouter`
	 * for the LLM path; we strip the `/openrouter` suffix and append
	 * `/workers-ai/{model}` for the image path. Defensive: only emits a URL
	 * that pattern-matches, so a bad option value quietly falls back.
	 *
	 * @param string $account_id Cloudflare account UUID (kept for symmetry; not used in URL derivation).
	 * @param string $model      Fully-qualified Workers AI model id.
	 * @return string Gateway URL, or empty string if unavailable.
	 */
	private function build_gateway_workers_ai_url( string $account_id, string $model ): string {
		unset( $account_id );
		if ( '1' !== (string) get_option( 'prautoblogger_cf_image_via_gateway', '1' ) ) {
			return '';
		}
		$gateway_base = rtrim( (string) get_option( 'prautoblogger_ai_gateway_base_url', '' ), '/' );
		if ( '' === $gateway_base ) {
			return '';
		}
		// Must be an https CF AI Gateway URL.
		if ( 0 !== stripos( $gateway_base, 'https://gateway.ai.cloudflare.com/' ) ) {
			return '';
		}
		// Strip the trailing OpenRouter path component (any path component, really);
		// everything before the last '/' is the gateway root shared by all provider routes.
		$gateway_root = substr( $gateway_base, 0, strrpos( $gateway_base, '/' ) ?: strlen( $gateway_base ) );
		if ( '' === $gateway_root ) {
			return '';
		}
		return sprintf( '%s/workers-ai/%s', $gateway_root, ltrim( $model, '/' ) );
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

	/**
	 * Cloudflare Workers AI error code for NSFW content rejection.
	 *
	 * Observed on FLUX.1 schnell on 2026-04-20 when backfilling the post
	 * "Semaglutide vs Tirzepatide". Documented upstream as
	 * `AiError: Input prompt contains NSFW content.`
	 */
	public const ERROR_CODE_NSFW = 3030;

	/**
	 * Return true when the Cloudflare response body contains the NSFW
	 * content-filter error code (3030).
	 *
	 * The body shape is `{"errors":[{"code":3030,"message":"..."}],...}`.
	 * Parsing is tolerant: any JSON decode failure or missing key returns
	 * false, so a malformed 4xx never masquerades as NSFW.
	 *
	 * @param string $raw Raw response body from Cloudflare.
	 * @return bool True when at least one error entry carries code 3030.
	 */
	public function is_nsfw_error( string $raw ): bool {
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['errors'] ) || ! is_array( $decoded['errors'] ) ) {
			return false;
		}
		foreach ( $decoded['errors'] as $err ) {
			if ( is_array( $err ) && (int) ( $err['code'] ?? 0 ) === self::ERROR_CODE_NSFW ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Throw a typed NSFW exception when the response body signals CF's
	 * content-filter rejection (code 3030). No-op otherwise.
	 *
	 * Centralised so callers stay at one line at the call site and so the
	 * exception-construction shape can't drift between call sites.
	 *
	 * @param string $raw Raw response body.
	 * @return void
	 * @throws PRAutoBlogger_Image_NSFW_Blocked When the body contains code 3030.
	 */
	public function throw_if_nsfw( string $raw ): void {
		if ( ! $this->is_nsfw_error( $raw ) ) {
			return;
		}
		throw new PRAutoBlogger_Image_NSFW_Blocked(
			sprintf(
				/* translators: %s: truncated error body. */
				esc_html__( 'Cloudflare Workers AI rejected the prompt as NSFW: %s', 'prautoblogger' ),
				esc_html( substr( $raw, 0, 300 ) )
			),
			$raw
		);
	}
}
