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
	 * Derive a 32-byte key from the site's auth salt.
	 *
	 * Prefers `AUTH_KEY . AUTH_SALT` so previously encrypted tokens still
	 * decrypt; falls back to `wp_salt( 'auth' )` on sites that don't
	 * define those constants.
	 *
	 * @return string
	 */
	private static function key(): string {
		if (
			\defined( 'AUTH_KEY' )
			&& \defined( 'AUTH_SALT' )
			&& '' !== AUTH_KEY
			&& '' !== AUTH_SALT
		) {
			$material = AUTH_KEY . AUTH_SALT;
		} else {
			$material = \wp_salt( 'auth' );
		}

		return \sodium_crypto_generichash(
			$material,
			'',
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
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
