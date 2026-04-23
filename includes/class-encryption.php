<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Encrypts and decrypts sensitive option values (API keys, secrets).
 *
 * Uses OpenSSL AES-256-CBC with a key derived from wp_salt('auth').
 * Encrypted values are prefixed with "enc:" so the system can ALWAYS
 * distinguish encrypted from plaintext — this eliminates the double-encryption
 * bug where WordPress's sanitize callback re-encrypts an already-encrypted value.
 *
 * Triggered by: Any code that reads/writes encrypted options (settings page, providers).
 * Dependencies: OpenSSL PHP extension, WordPress wp_salt() function.
 *
 * @see admin/class-admin-page.php — Uses this to store/retrieve API keys.
 */
class PRAutoBlogger_Encryption {

	private const METHOD = 'aes-256-cbc';

	/**
	 * Prefix that identifies an encrypted value in the database.
	 * Any string starting with this prefix is treated as already encrypted.
	 */
	public const PREFIX = 'enc:';

	/**
	 * Check if a value is already encrypted (has our prefix).
	 *
	 * @param string $value The value to check.
	 *
	 * @return bool
	 */
	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * Returns empty string for empty input. If the value is already encrypted
	 * (starts with "enc:"), returns it unchanged — this is the key defence
	 * against double-encryption from WordPress calling sanitize_option twice.
	 *
	 * @param string $plaintext The value to encrypt.
	 *
	 * @return string Prefixed base64-encoded ciphertext, or empty string.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		// Already encrypted — return as-is. This prevents double-encryption
		// regardless of how many times the sanitize callback fires.
		if ( self::is_encrypted( $plaintext ) ) {
			return $plaintext;
		}

		$key    = self::get_key();
		$iv_len = (int) openssl_cipher_iv_length( self::METHOD );
		$iv     = openssl_random_pseudo_bytes( $iv_len );

		$ciphertext = openssl_encrypt( $plaintext, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			PRAutoBlogger_Logger::instance()->error( 'Encryption failed: ' . openssl_error_string(), 'encryption' );
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::PREFIX . base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt a previously encrypted string.
	 *
	 * Handles both prefixed ("enc:...") and legacy non-prefixed values for
	 * backward compatibility during the transition.
	 *
	 * @param string $encrypted Prefixed base64-encoded ciphertext.
	 *
	 * @return string The original plaintext, or empty string on failure.
	 */
	public static function decrypt( string $encrypted ): string {
		if ( '' === $encrypted ) {
			return '';
		}

		// Strip the prefix if present.
		$payload = $encrypted;
		if ( self::is_encrypted( $encrypted ) ) {
			$payload = substr( $encrypted, strlen( self::PREFIX ) );
		}

		$key    = self::get_key();
		$iv_len = (int) openssl_cipher_iv_length( self::METHOD );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = base64_decode( $payload, true );
		if ( false === $raw || strlen( $raw ) <= $iv_len ) {
			PRAutoBlogger_Logger::instance()->error( 'Decryption failed: invalid ciphertext format.', 'encryption' );
			return '';
		}

		$iv         = substr( $raw, 0, $iv_len );
		$ciphertext = substr( $raw, $iv_len );

		$plaintext = openssl_decrypt( $ciphertext, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plaintext ) {
			PRAutoBlogger_Logger::instance()->error( 'Decryption failed: ' . openssl_error_string(), 'encryption' );
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
