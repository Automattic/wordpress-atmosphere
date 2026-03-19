<?php
/**
 * DPoP nonce storage backed by WordPress transients.
 *
 * AT Protocol auth servers issue short-lived nonces that must be
 * included in subsequent DPoP proofs.  We persist them in transients
 * so they survive across separate HTTP requests.
 *
 * @package Atmosphere
 */

namespace Atmosphere\OAuth;

\defined( 'ABSPATH' ) || exit;

/**
 * Simple key-value nonce storage via transients.
 */
class Nonce_Storage {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private const PREFIX = 'atmo_dpop_nonce_';

	/**
	 * Time-to-live in seconds.
	 *
	 * @var int
	 */
	private const TTL = 300;

	/**
	 * Retrieve a stored nonce for a URL.
	 *
	 * @param string $url Request URL.
	 * @return string|null
	 */
	public static function get( string $url ): ?string {
		$value = \get_transient( self::PREFIX . self::hash( $url ) );

		return false !== $value ? $value : null;
	}

	/**
	 * Store a nonce for a URL.
	 *
	 * @param string $url   Request URL.
	 * @param string $nonce Nonce value.
	 */
	public static function set( string $url, string $nonce ): void {
		\set_transient( self::PREFIX . self::hash( $url ), $nonce, self::TTL );
	}

	/**
	 * Hash a URL to a safe transient suffix.
	 *
	 * @param string $url URL.
	 * @return string 32-char hex string.
	 */
	private static function hash( string $url ): string {
		return \md5( $url );
	}
}
