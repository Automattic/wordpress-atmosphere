<?php
/**
 * DPoP (Demonstration of Proof-of-Possession) proof generation.
 *
 * Produces ES256-signed JWT proofs using PHP's native OpenSSL extension,
 * as required by AT Protocol auth servers and PDS instances.
 *
 * @package Atmosphere
 */

namespace Atmosphere\OAuth;

\defined( 'ABSPATH' ) || exit;

/**
 * Static helper for DPoP proof creation.
 *
 * Produces ES256-signed JWTs using PHP's OpenSSL extension directly,
 * avoiding any third-party JWT library dependency.
 */
class DPoP {

	/**
	 * Generate an ES256 key pair suitable for DPoP.
	 *
	 * @return array JWK array with private key material.
	 */
	public static function generate_key(): array {
		$key = \openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);

		\openssl_pkey_export( $key, $pem );
		$details = \openssl_pkey_get_details( $key );
		$ec      = $details['ec'];

		return array(
			'kty' => 'EC',
			'crv' => 'P-256',
			'x'   => self::base64url( $ec['x'] ),
			'y'   => self::base64url( $ec['y'] ),
			'd'   => self::base64url( $ec['d'] ),
		);
	}

	/**
	 * Create a DPoP proof JWT.
	 *
	 * @param array       $jwk          JWK array with private key.
	 * @param string      $method       HTTP method (GET, POST, …).
	 * @param string      $url          Absolute URL being requested.
	 * @param string|null $nonce        Server-provided nonce, if any.
	 * @param string|null $access_token Access token to bind (ath claim).
	 * @return string|false Compact-serialized JWT, or false on error.
	 */
	public static function create_proof(
		array $jwk,
		string $method,
		string $url,
		?string $nonce = null,
		?string $access_token = null,
	): string|false {
		try {
			// If no explicit nonce provided, look up a stored one.
			if ( null === $nonce ) {
				$nonce = Nonce_Storage::get( $url );
			}

			// Build public JWK (strip private key 'd' parameter).
			$public_jwk = $jwk;
			unset( $public_jwk['d'] );

			$header = array(
				'alg' => 'ES256',
				'typ' => 'dpop+jwt',
				'jwk' => $public_jwk,
			);

			$payload = array(
				'jti' => self::base64url( \random_bytes( 16 ) ),
				'htm' => \strtoupper( $method ),
				'htu' => $url,
				'iat' => \time(),
			);

			if ( null !== $nonce ) {
				$payload['nonce'] = $nonce;
			}

			if ( null !== $access_token ) {
				// ath = base64url(SHA-256(access_token)).
				$payload['ath'] = self::base64url( \hash( 'sha256', $access_token, true ) );
			}

			return self::sign_es256( $header, $payload, $jwk );
		} catch ( \Throwable $e ) {
			\wp_trigger_error( __METHOD__, 'DPoP proof generation failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Store a nonce received from a server response.
	 *
	 * @param array  $jwk   JWK (unused, kept for API compatibility).
	 * @param string $url   Target URL.
	 * @param string $nonce Nonce from DPoP-Nonce header.
	 */
	public static function persist_nonce( array $jwk, string $url, string $nonce ): void {
		Nonce_Storage::set( $url, $nonce );
	}

	/**
	 * Sign a JWT with ES256 (ECDSA using P-256 and SHA-256).
	 *
	 * Produces a compact-serialized JWS: base64url(header).base64url(payload).base64url(signature).
	 *
	 * @param array $header  JWT header claims.
	 * @param array $payload JWT payload claims.
	 * @param array $jwk     JWK array with private key material (d, x, y, crv).
	 * @return string|false Compact-serialized JWT, or false on error.
	 */
	private static function sign_es256( array $header, array $payload, array $jwk ): string|false {
		$header_b64    = self::base64url( (string) \wp_json_encode( $header ) );
		$payload_b64   = self::base64url( (string) \wp_json_encode( $payload ) );
		$signing_input = $header_b64 . '.' . $payload_b64;

		// Reconstruct PEM from JWK parameters.
		$pem = self::jwk_to_pem( $jwk );
		if ( false === $pem ) {
			return false;
		}

		$key = \openssl_pkey_get_private( $pem );
		if ( false === $key ) {
			return false;
		}

		// OpenSSL produces a DER-encoded ASN.1 signature; JWT needs raw R‖S.
		$der_signature = '';
		if ( ! \openssl_sign( $signing_input, $der_signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return false;
		}

		$raw_signature = self::der_to_raw( $der_signature, 64 );
		if ( false === $raw_signature ) {
			return false;
		}

		return $signing_input . '.' . self::base64url( $raw_signature );
	}

	/**
	 * Convert a JWK EC private key to PEM format.
	 *
	 * @param array $jwk JWK with kty=EC, crv=P-256, x, y, d.
	 * @return string|false PEM-encoded private key, or false on error.
	 */
	private static function jwk_to_pem( array $jwk ): string|false {
		if ( ! isset( $jwk['d'], $jwk['x'], $jwk['y'] ) ) {
			return false;
		}

		/*
		 * Build a DER-encoded SEC1 EC private key structure:
		 *
		 * ECPrivateKey ::= SEQUENCE {
		 *   version        INTEGER { ecPrivkeyVer1(1) },
		 *   privateKey     OCTET STRING,
		 *   parameters [0] OID (prime256v1),
		 *   publicKey  [1] BIT STRING
		 * }
		 */
		$d = self::base64url_decode( $jwk['d'] );
		$x = self::base64url_decode( $jwk['x'] );
		$y = self::base64url_decode( $jwk['y'] );

		if ( false === $d || false === $x || false === $y ) {
			return false;
		}

		// Pad to 32 bytes (P-256 field size).
		$d = \str_pad( $d, 32, "\0", STR_PAD_LEFT );
		$x = \str_pad( $x, 32, "\0", STR_PAD_LEFT );
		$y = \str_pad( $y, 32, "\0", STR_PAD_LEFT );

		// Uncompressed public key point: 0x04 || x || y.
		$public_key = "\x04" . $x . $y;

		// OID for prime256v1 (P-256): 1.2.840.10045.3.1.7.
		$oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

		// Build the SEC1 structure.
		$private_key = self::asn1_sequence(
			self::asn1_integer( "\x01" )                                   // version.
			. self::asn1_octet_string( $d )                                // privateKey.
			. self::asn1_explicit_tag( 0, $oid )                           // parameters.
			. self::asn1_explicit_tag( 1, self::asn1_bit_string( $public_key ) ) // publicKey.
		);

		return "-----BEGIN EC PRIVATE KEY-----\n"
			. \chunk_split( \base64_encode( $private_key ), 64, "\n" ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			. '-----END EC PRIVATE KEY-----';
	}

	/**
	 * Convert a DER-encoded ECDSA signature to raw R‖S format.
	 *
	 * @param string $der    DER-encoded signature.
	 * @param int    $length Expected total length (64 for P-256).
	 * @return string|false Raw signature, or false on error.
	 */
	private static function der_to_raw( string $der, int $length ): string|false {
		$half = $length / 2;

		// Parse SEQUENCE.
		if ( \ord( $der[0] ) !== 0x30 ) {
			return false;
		}

		$offset = 2; // Skip tag + length byte.

		// Parse R.
		if ( \ord( $der[ $offset ] ) !== 0x02 ) {
			return false;
		}
		$r_len   = \ord( $der[ $offset + 1 ] );
		$r       = \substr( $der, $offset + 2, $r_len );
		$offset += 2 + $r_len;

		// Parse S.
		if ( \ord( $der[ $offset ] ) !== 0x02 ) {
			return false;
		}
		$s_len = \ord( $der[ $offset + 1 ] );
		$s     = \substr( $der, $offset + 2, $s_len );

		// Strip leading zero padding, then pad to field size.
		$r = \str_pad( \ltrim( $r, "\x00" ), $half, "\0", STR_PAD_LEFT );
		$s = \str_pad( \ltrim( $s, "\x00" ), $half, "\0", STR_PAD_LEFT );

		return $r . $s;
	}

	/**
	 * ASN.1 DER helpers.
	 *
	 * @param string $content DER content.
	 * @return string DER-encoded SEQUENCE.
	 */
	private static function asn1_sequence( string $content ): string {
		return "\x30" . self::asn1_length( $content ) . $content;
	}

	/**
	 * Encode an ASN.1 INTEGER.
	 *
	 * @param string $data Integer bytes.
	 * @return string DER-encoded INTEGER.
	 */
	private static function asn1_integer( string $data ): string {
		return "\x02" . self::asn1_length( $data ) . $data;
	}

	/**
	 * Encode an ASN.1 OCTET STRING.
	 *
	 * @param string $data Octet string bytes.
	 * @return string DER-encoded OCTET STRING.
	 */
	private static function asn1_octet_string( string $data ): string {
		return "\x04" . self::asn1_length( $data ) . $data;
	}

	/**
	 * Encode an ASN.1 BIT STRING.
	 *
	 * @param string $data Bit string content.
	 * @return string DER-encoded BIT STRING.
	 */
	private static function asn1_bit_string( string $data ): string {
		return "\x03" . self::asn1_length( "\x00" . $data ) . "\x00" . $data;
	}

	/**
	 * Encode an ASN.1 context-specific explicit tag.
	 *
	 * @param int    $tag     Tag number.
	 * @param string $content Tag content.
	 * @return string DER-encoded tagged value.
	 */
	private static function asn1_explicit_tag( int $tag, string $content ): string {
		$tag_byte = \chr( 0xA0 | $tag );
		return $tag_byte . self::asn1_length( $content ) . $content;
	}

	/**
	 * Encode an ASN.1 DER length.
	 *
	 * @param string $content Content to measure.
	 * @return string DER-encoded length bytes.
	 */
	private static function asn1_length( string $content ): string {
		$length = \strlen( $content );
		if ( $length < 0x80 ) {
			return \chr( $length );
		}
		$length_bytes = \ltrim( \pack( 'N', $length ), "\x00" );
		return \chr( 0x80 | \strlen( $length_bytes ) ) . $length_bytes;
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $data Base64url-encoded string.
	 * @return string|false Decoded bytes, or false on error.
	 */
	private static function base64url_decode( string $data ): string|false {
		return \base64_decode( \strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Base64url encode (no padding).
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private static function base64url( string $data ): string {
		return \rtrim( \strtr( \base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
