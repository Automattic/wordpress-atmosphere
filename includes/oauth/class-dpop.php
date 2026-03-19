<?php
/**
 * DPoP (Demonstration of Proof-of-Possession) proof generation.
 *
 * Produces ES256-signed JWT proofs directly using the web-token/jwt-library,
 * as required by AT Protocol auth servers and PDS instances.
 *
 * @package Atmosphere
 */

namespace Atmosphere\OAuth;

\defined( 'ABSPATH' ) || exit;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * Static helper for DPoP proof creation.
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

			$algorithm_manager = new AlgorithmManager( array( new ES256() ) );
			$jws_builder       = new JWSBuilder( $algorithm_manager );

			$jws = $jws_builder
				->create()
				->withPayload( (string) \wp_json_encode( $payload ) )
				->addSignature( new JWK( $jwk ), $header )
				->build();

			$serializer = new CompactSerializer();

			return $serializer->serialize( $jws, 0 );
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
	 * Base64url encode (no padding).
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private static function base64url( string $data ): string {
		return \rtrim( \strtr( \base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
