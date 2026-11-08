<?php
/**
 * Authenticated encryption for S3 credentials at rest.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts credentials before they are written to wp_options.
 *
 * Sodium secretbox is preferred. OpenSSL AES-256-GCM is used when Sodium is
 * unavailable. Ciphertext carries an algorithm prefix so legacy plaintext can
 * still be read until the one-time migration runs.
 */
final class Credential_Cipher {

	/**
	 * Sodium ciphertext prefix.
	 */
	private const SODIUM_PREFIX = 'sio1s:';

	/**
	 * OpenSSL ciphertext prefix.
	 */
	private const OPENSSL_PREFIX = 'sio1o:';

	/**
	 * Sodium nonce length.
	 */
	private const NONCE_BYTES = 24;

	/**
	 * AES-GCM IV length.
	 */
	private const IV_BYTES = 12;

	/**
	 * Whether any supported AEAD implementation is available.
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' );
	}

	/**
	 * Whether a stored value is ciphertext produced by this class.
	 *
	 * @param string $value Stored value.
	 */
	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::SODIUM_PREFIX ) || str_starts_with( $value, self::OPENSSL_PREFIX );
	}

	/**
	 * Encrypt a credential.
	 *
	 * @param string $plaintext Plaintext credential.
	 * @return string|false Ciphertext, empty string for empty input, false on failure.
	 */
	public static function encrypt( string $plaintext ) {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			try {
				$nonce  = random_bytes( self::NONCE_BYTES );
				$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::primary_key() );
				return self::SODIUM_PREFIX . base64_encode( $nonce . $cipher );
			} catch ( \Throwable $e ) {
				unset( $e );
				// Fall through to OpenSSL.
			}
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv  = random_bytes( self::IV_BYTES );
			$tag = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', self::primary_key(), OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $cipher ) {
				return self::OPENSSL_PREFIX . base64_encode( $iv . $tag . $cipher );
			}
		}

		return false;
	}

	/**
	 * Decrypt a stored credential.
	 *
	 * Legacy plaintext is returned unchanged so existing installs keep working
	 * until migrate_legacy_option() rewrites it.
	 *
	 * @param string $value Stored value.
	 * @return string|false Plaintext, empty string, or false when ciphertext cannot be opened.
	 */
	public static function decrypt( string $value ) {
		if ( '' === $value ) {
			return '';
		}
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}

		$payload = base64_decode( substr( $value, strlen( self::SODIUM_PREFIX ) ), true );
		if ( false === $payload ) {
			return false;
		}

		if ( str_starts_with( $value, self::SODIUM_PREFIX ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || strlen( $payload ) <= self::NONCE_BYTES ) {
				return false;
			}
			$nonce  = substr( $payload, 0, self::NONCE_BYTES );
			$cipher = substr( $payload, self::NONCE_BYTES );
			foreach ( self::key_candidates() as $key ) {
				try {
					$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
					if ( false !== $plain ) {
						return $plain;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return false;
		}

		if ( ! function_exists( 'openssl_decrypt' ) || strlen( $payload ) <= self::IV_BYTES + 16 ) {
			return false;
		}
		$iv     = substr( $payload, 0, self::IV_BYTES );
		$tag    = substr( $payload, self::IV_BYTES, 16 );
		$cipher = substr( $payload, self::IV_BYTES + 16 );
		foreach ( self::key_candidates() as $key ) {
			$plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $plain ) {
				return $plain;
			}
		}

		return false;
	}

	/**
	 * Encrypt legacy plaintext credentials once.
	 *
	 * Runs on every request but writes only when a plaintext non-empty value is
	 * present, so installs that upgrade in place are converted without an admin
	 * visit and without re-encrypting every request.
	 */
	public static function migrate_legacy_option(): void {
		if ( ! self::is_available() ) {
			return;
		}

		$raw = get_option( Options::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return;
		}

		$changed = false;
		foreach ( array( 'key', 'secret' ) as $field ) {
			$value = isset( $raw[ $field ] ) && is_string( $raw[ $field ] ) ? $raw[ $field ] : '';
			if ( '' === $value || self::is_encrypted( $value ) ) {
				continue;
			}
			$encrypted = self::encrypt( $value );
			if ( false !== $encrypted ) {
				$raw[ $field ] = $encrypted;
				$changed       = true;
			}
		}

		if ( $changed ) {
			update_option( Options::OPTION, $raw, false );
		}
	}

	/**
	 * Primary encryption key.
	 *
	 * SAKURA_TOOLS_CREDENTIAL_KEY is recommended in wp-config.php. Without it
	 * the key is derived from WordPress salts; rotating salts makes stored
	 * credentials unreadable, which is intentional and recoverable by
	 * re-entering the credentials.
	 */
	private static function primary_key(): string {
		$keys = self::key_candidates();
		return $keys[0];
	}

	/**
	 * Decryption key candidates, newest preference first.
	 *
	 * Both the constant-derived and salt-derived keys are tried on decrypt, so
	 * adding a constant after credentials were encrypted with salts does not
	 * destroy them.
	 *
	 * @return string[]
	 */
	private static function key_candidates(): array {
		$keys = array();

		if ( defined( 'SAKURA_TOOLS_CREDENTIAL_KEY' ) && is_string( SAKURA_TOOLS_CREDENTIAL_KEY ) && '' !== trim( SAKURA_TOOLS_CREDENTIAL_KEY ) ) {
			$keys[] = hash( 'sha256', SAKURA_TOOLS_CREDENTIAL_KEY . '|sakura-imgopt-s3-credential-v1', true );
		}

		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		if ( '' !== $salt ) {
			$keys[] = hash( 'sha256', $salt . '|sakura-imgopt-s3-credential-v1', true );
		}

		if ( array() === $keys ) {
			// Only reachable in isolated tests without WordPress salts.
			$keys[] = hash( 'sha256', 'sakura-imgopt-s3-credential-fallback', true );
		}

		return array_values( array_unique( $keys ) );
	}
}
