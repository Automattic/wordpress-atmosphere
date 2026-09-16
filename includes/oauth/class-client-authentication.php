<?php
/**
 * Persistent confidential-client authentication credentials.
 *
 * @package Atmosphere
 */

namespace Atmosphere\OAuth;

\defined( 'ABSPATH' ) || exit;

use function Atmosphere\debug_log;
use function Atmosphere\get_connection;
use function Atmosphere\is_connected;

/**
 * Creates, protects, and exposes this site's OAuth client signing key.
 */
class Client_Authentication {

	/**
	 * Option holding the encrypted private JWK.
	 *
	 * @var string
	 */
	public const KEY_OPTION = 'atmosphere_oauth_client_authentication_key';

	/**
	 * Client-assertion type sent alongside a `private_key_jwt` assertion.
	 *
	 * @var string
	 */
	public const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

	/**
	 * Attach a fresh client assertion to an auth-server request body.
	 *
	 * Call it again for every retry: each assertion carries a unique `jti`
	 * and a 60-second lifetime, and the reference server rejects replays.
	 *
	 * @param array  $body   Request body carrying `client_id`.
	 * @param string $issuer Authorization server issuer URL.
	 * @return array|\WP_Error Body with the assertion fields, or an actionable error.
	 */
	public static function sign_request( array $body, string $issuer ): array|\WP_Error {
		$assertion = self::assertion( (string) ( $body['client_id'] ?? '' ), $issuer );
		if ( \is_wp_error( $assertion ) ) {
			return $assertion;
		}

		$body['client_assertion_type'] = self::ASSERTION_TYPE;
		$body['client_assertion']      = $assertion;

		return $body;
	}

	/**
	 * Public JWKS document advertised in OAuth client metadata.
	 *
	 * @return array|\WP_Error
	 */
	public static function jwks(): array|\WP_Error {
		$key = self::key();
		if ( \is_wp_error( $key ) ) {
			return $key;
		}

		$public = array(
			'kty' => $key['kty'],
			'crv' => $key['crv'],
			'x'   => $key['x'],
			'y'   => $key['y'],
			'kid' => self::key_id( $key ),
			'use' => 'sig',
			'alg' => 'ES256',
		);

		return array( 'keys' => array( $public ) );
	}

	/**
	 * Create an assertion for the confidential client.
	 *
	 * @param string $client_id OAuth client identifier.
	 * @param string $issuer    Authorization server issuer URL.
	 * @return string|\WP_Error Signed assertion or an actionable error.
	 */
	private static function assertion( string $client_id, string $issuer ): string|\WP_Error {
		$key = self::key();
		if ( \is_wp_error( $key ) ) {
			return $key;
		}

		$assertion = DPoP::create_client_assertion( $key, $client_id, $issuer, self::key_id( $key ) );
		if ( false === $assertion ) {
			return new \WP_Error( 'atmosphere_client_authentication', \__( 'Failed to authenticate this site to the authorization server.', 'atmosphere' ) );
		}

		return $assertion;
	}

	/**
	 * Get or create the encrypted private signing key.
	 *
	 * @return array|\WP_Error
	 */
	private static function key(): array|\WP_Error {
		$key = self::stored_key();
		if ( null !== $key ) {
			return $key;
		}

		$generated = DPoP::generate_key();
		if ( \is_wp_error( $generated ) ) {
			return $generated;
		}

		\add_option( self::KEY_OPTION, Encryption::encrypt( (string) \wp_json_encode( $generated ) ), '', false );

		/*
		 * Re-read rather than trusting the in-memory key: `add_option()`
		 * inserts with ON DUPLICATE KEY UPDATE, so when two first-time
		 * callers race the last write wins. Whichever row is stored now is
		 * the key whose public half the JWKS publishes, and every caller
		 * has to sign with that one.
		 */
		\wp_cache_delete( self::KEY_OPTION, 'options' );

		return self::stored_key() ?? new \WP_Error(
			'atmosphere_client_authentication_key',
			\__( 'The OAuth client-authentication key could not be saved.', 'atmosphere' )
		);
	}

	/**
	 * Decrypt the stored signing key.
	 *
	 * A key that no longer decrypts (rotated salts, a regenerated
	 * `wp-config.php`) or fails validation is unusable. While a live session
	 * still matches the current key material, it stays put and the error is
	 * reported, because the session is bound to that key's `kid`. Once no
	 * such session exists, the row is discarded so the caller mints a fresh
	 * key and the site can connect again.
	 *
	 * @return array|\WP_Error|null The key, an error for an unreadable row, or null when none is stored.
	 */
	private static function stored_key(): array|\WP_Error|null {
		$stored = \get_option( self::KEY_OPTION, '' );
		if ( ! \is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$json = Encryption::decrypt( $stored );
		$key  = false === $json ? null : \json_decode( $json, true );

		if ( self::valid_key( $key ) ) {
			return $key;
		}

		if ( self::session_bound_to_key() ) {
			return new \WP_Error( 'atmosphere_client_authentication_key', \__( 'The site’s Bluesky signing key could not be read. Disconnect and connect again to create a new one.', 'atmosphere' ) );
		}

		debug_log( 'client-authentication key is unreadable and no live session uses it; generating a new one.' );
		\delete_option( self::KEY_OPTION );

		return null;
	}

	/**
	 * Whether a live session was minted under the current key material.
	 *
	 * A connection whose tokens were encrypted under a different key is
	 * already lost, so nothing is left for the signing key to protect.
	 *
	 * @return bool
	 */
	private static function session_bound_to_key(): bool {
		if ( ! is_connected() ) {
			return false;
		}

		$fingerprint = (string) ( get_connection()['key_fingerprint'] ?? '' );

		return '' === $fingerprint || \hash_equals( Encryption::key_fingerprint(), $fingerprint );
	}

	/**
	 * Validate the required P-256 private JWK members.
	 *
	 * Byte-level checks happen when DPoP converts the key to PEM.
	 *
	 * @param mixed $key Candidate key.
	 * @return bool
	 */
	private static function valid_key( $key ): bool {
		return \is_array( $key )
			&& 'EC' === ( $key['kty'] ?? '' )
			&& 'P-256' === ( $key['crv'] ?? '' )
			&& \is_string( $key['x'] ?? null )
			&& \is_string( $key['y'] ?? null )
			&& \is_string( $key['d'] ?? null );
	}

	/**
	 * Stable identifier for the published public key.
	 *
	 * @param array $key Validated private JWK.
	 * @return string
	 */
	private static function key_id( array $key ): string {
		return \substr( \hash( 'sha256', $key['x'] . '|' . $key['y'] ), 0, 32 );
	}
}
