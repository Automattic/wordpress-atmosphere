<?php
/**
 * Symmetric encryption at rest via libsodium.
 *
 * Tokens are encrypted before they touch the database and decrypted
 * only when they are about to be used in an HTTP request.
 *
 * @package Atmosphere
 */

namespace Atmosphere\OAuth;

\defined( 'ABSPATH' ) || exit;

/**
 * Encryption helper using sodium secretbox.
 */
class Encryption {

	/**
	 * Secret material the encryption key is derived from.
	 *
	 * Precedence:
	 *
	 *  1. `ATMOSPHERE_ENCRYPTION_KEY` — an explicit opt-in for sites
	 *     whose WordPress salts are not stable: scheduled salt rotation
	 *     by a security plugin, or hosts/migrations that regenerate
	 *     `wp-config.php`. Salt-derived keys stop decrypting after every
	 *     rotation; a pinned constant keeps the stored tokens readable
	 *     while still living outside the database. Changing or removing
	 *     the constant orphans existing tokens like any other key change.
	 *  2. `AUTH_KEY . AUTH_SALT`, so previously encrypted tokens still
	 *     decrypt.
	 *  3. `wp_salt( 'auth' )` on sites that don't define those constants.
	 *
	 * @since unreleased Recognizes `ATMOSPHERE_ENCRYPTION_KEY`.
	 *
	 * @return string
	 */
	private static function key_material(): string {
		if ( \defined( 'ATMOSPHERE_ENCRYPTION_KEY' ) && '' !== ATMOSPHERE_ENCRYPTION_KEY ) {
			return ATMOSPHERE_ENCRYPTION_KEY;
		}

		if (
			\defined( 'AUTH_KEY' )
			&& \defined( 'AUTH_SALT' )
			&& '' !== AUTH_KEY
			&& '' !== AUTH_SALT
		) {
			return AUTH_KEY . AUTH_SALT;
		}

		return \wp_salt( 'auth' );
	}

	/**
	 * Derive the 32-byte secretbox key from the key material.
	 *
	 * @return string
	 */
	private static function key(): string {
		return \sodium_crypto_generichash(
			self::key_material(),
			'',
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}

	/**
	 * Fingerprint of the current encryption key, safe to persist.
	 *
	 * Stored alongside the encrypted tokens so a later decrypt failure
	 * can be classified: a mismatch means the key material changed (salt
	 * rotation, regenerated `wp-config.php`), while a match points at
	 * corrupted ciphertext. Domain-separated from the key derivation so
	 * the stored value cannot double as key material.
	 *
	 * @since unreleased
	 *
	 * @return string 32-character hex string.
	 */
	public static function key_fingerprint(): string {
		return \sodium_bin2hex(
			\sodium_crypto_generichash( 'atmosphere-key-fingerprint' . self::key(), '', 16 )
		);
	}

	/**
	 * Encrypt a plaintext value.
	 *
	 * @param string $plaintext Value to protect.
	 * @return string Base-64 encoded nonce‖ciphertext.
	 */
	public static function encrypt( string $plaintext ): string {
		$nonce      = \random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = \sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

		return \base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * @param string $encoded Base-64 blob produced by encrypt().
	 * @return string|false Plaintext or false on failure.
	 */
	public static function decrypt( string $encoded ): string|false {
		$raw = \base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw ) {
			return false;
		}

		$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

		if ( \strlen( $raw ) < $nonce_len + 1 ) {
			return false;
		}

		return \sodium_crypto_secretbox_open(
			\substr( $raw, $nonce_len ),
			\substr( $raw, 0, $nonce_len ),
			self::key()
		);
	}
}
