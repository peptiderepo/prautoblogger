<?php
declare(strict_types=1);

/**
 * Encrypts and decrypts sensitive option values (API keys, secrets).
 *
 * Uses OpenSSL AES-256-CBC with a key derived from wp_salt('auth').
 * This is not bulletproof — anyone who can read wp-config.php can decrypt — but it
 * prevents casual exposure of API keys in database dumps or phpMyAdmin.
 *
 * Triggered by: Any code that reads/writes encrypted options (settings page, providers).
 * Dependencies: OpenSSL PHP extension, WordPress wp_salt() function.
 *
 * @see admin/class-admin-page.php — Uses this to store/retrieve API keys.
 */
class Autoblogger_Encryption {

	private const METHOD = 'aes-256-cbc';

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext The value to encrypt.
	 *
	 * @return string Base64-encoded ciphertext with IV prepended.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key    = self::get_key();
		$iv_len = (int) openssl_cipher_iv_length( self::METHOD );
		$iv     = openssl_random_pseudo_bytes( $iv_len );

		$ciphertext = openssl_encrypt( $plaintext, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			Autoblogger_Logger::instance()->error( 'Encryption failed: ' . openssl_error_string(), 'encryption' );
			return '';
		}

		// Prepend IV to ciphertext so we can extract it on decrypt.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt a previously encrypted string.
	 *
	 * @param string $encrypted Base64-encoded ciphertext with IV prepended.
	 *
	 * @return string The original plaintext, or empty string on failure.
	 */
	public static function decrypt( string $encrypted ): string {
		if ( '' === $encrypted ) {
			return '';
		}

		$key    = self::get_key();
		$iv_len = (int) openssl_cipher_iv_length( self::METHOD );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = base64_decode( $encrypted, true );
		if ( false === $raw || strlen( $raw ) <= $iv_len ) {
			Autoblogger_Logger::instance()->error( 'Decryption failed: invalid ciphertext format.', 'encryption' );
			return '';
		}

		$iv         = substr( $raw, 0, $iv_len );
		$ciphertext = substr( $raw, $iv_len );

		$plaintext = openssl_decrypt( $ciphertext, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plaintext ) {
			Autoblogger_Logger::instance()->error( 'Decryption failed: ' . openssl_error_string(), 'encryption' );
			return '';
		}

		return $plaintext;
	}

	/**
	 * Derive the encryption key from WordPress salts.
	 *
	 * @return string 32-byte key suitable for AES-256.
	 */
	private static function get_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}
}
