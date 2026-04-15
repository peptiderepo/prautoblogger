<?php
declare(strict_types=1);

/**
 * Credential + connectivity validator for the Cloudflare image provider.
 *
 * Split out of the provider to keep each file under the 300-line cap and
 * to keep the validation path easy to unit-test without spinning up the
 * retry loop. The provider's `validate_credentials_detailed()` delegates
 * straight to `run()` here.
 *
 * Triggered by: PRAutoBlogger_Cloudflare_Image_Provider::validate_credentials_detailed()
 *               which is called by the admin "Test Connection" action.
 * Dependencies: PRAutoBlogger_Encryption (token decryption), PRAutoBlogger_Logger
 *               (WARN/ERROR lines), wp_remote_get().
 *
 * @see class-cloudflare-image-provider.php Parent class that delegates here.
 * @see interface-image-provider.php        Return-shape contract.
 */
class PRAutoBlogger_Cloudflare_Image_Validator {

	private const TIMEOUT_SECONDS = 15;

	/**
	 * Run a non-destructive credential check.
	 *
	 * Lightweight probe: hits the account-scoped AI models listing endpoint.
	 * Never generates a real image — that would cost money on every click.
	 *
	 * Side effects: one HTTP GET; one Logger line on failure.
	 *
	 * @return array{status: string, message: string, debug?: string}
	 */
	public function run(): array {
		$account_id = trim( (string) get_option( 'prautoblogger_cloudflare_account_id', '' ) );
		if ( '' === $account_id ) {
			return $this->err(
				'account_id_empty',
				__( 'Cloudflare account ID is not set. Go to PRAutoBlogger → Settings → Images.', 'prautoblogger' )
			);
		}

		$encrypted = (string) get_option( 'prautoblogger_cloudflare_ai_token', '' );
		$api_token = '' === $encrypted ? '' : (string) PRAutoBlogger_Encryption::decrypt( $encrypted );
		if ( '' === $api_token ) {
			return $this->err(
				'token_empty',
				__( 'Cloudflare API token is not set. Go to PRAutoBlogger → Settings → Images.', 'prautoblogger' )
			);
		}

		$response = wp_remote_get(
			sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai/models/search?per_page=1',
				rawurlencode( $account_id )
			),
			[
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Cloudflare credential check wp_error: %s', $msg ),
				'cloudflare-image'
			);
			return $this->err(
				'wp_error:' . $msg,
				sprintf(
					/* translators: %s: transport-layer error message. */
					esc_html__( 'Network error reaching Cloudflare: %s', 'prautoblogger' ),
					esc_html( $msg )
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			return [
				'status'  => 'ok',
				'message' => __( 'Cloudflare Workers AI connected.', 'prautoblogger' ),
			];
		}

		$body = (string) wp_remote_retrieve_body( $response );
		PRAutoBlogger_Logger::instance()->warning(
			sprintf( 'Cloudflare credential check HTTP %d: %s', $status, substr( $body, 0, 200 ) ),
			'cloudflare-image'
		);
		return $this->err(
			sprintf( 'http_%d', $status ),
			sprintf(
				/* translators: %1$d: HTTP status, %2$s: response body. */
				esc_html__( 'Cloudflare returned HTTP %1$d. %2$s', 'prautoblogger' ),
				$status,
				esc_html( substr( $body, 0, 200 ) )
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
