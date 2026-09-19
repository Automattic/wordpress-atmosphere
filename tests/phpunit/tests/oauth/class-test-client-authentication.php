<?php
/**
 * Tests for the confidential-client signing key.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests\OAuth;

use WP_UnitTestCase;
use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\Client_Authentication;
use Atmosphere\OAuth\Encryption;
use Atmosphere\Tests\JWT_Claims;

/**
 * Client authentication key tests.
 */
class Test_Client_Authentication extends WP_UnitTestCase {

	use JWT_Claims;

	/**
	 * Remove the stored key and any seeded session between tests.
	 */
	public function tear_down(): void {
		\delete_option( Client_Authentication::KEY_OPTION );
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_connection' );
		parent::tear_down();
	}

	/**
	 * Seed a live session.
	 *
	 * @param string      $fingerprint Key fingerprint stored on the connection.
	 * @param string|null $client_id   Stored client_id; null seeds a legacy session.
	 */
	private function seed_connection( string $fingerprint, ?string $client_id = 'confidential' ): void {
		\update_option(
			'atmosphere_identity',
			array(
				'did'    => 'did:plc:test',
				'handle' => 'example.com',
			),
			false
		);
		\update_option(
			'atmosphere_connection',
			array(
				'did'             => 'did:plc:test',
				'access_token'    => Encryption::encrypt( 'access-token' ),
				'needs_reauth'    => false,
				'key_fingerprint' => $fingerprint,
				'client_id'       => 'confidential' === $client_id ? Client::client_id() : $client_id,
			),
			false
		);
	}

	/**
	 * The first call creates the key; later calls publish the same one.
	 */
	public function test_jwks_creates_and_reuses_the_key() {
		$this->assertFalse( \get_option( Client_Authentication::KEY_OPTION ) );

		$first = Client_Authentication::jwks();
		$this->assertIsArray( $first );
		$this->assertNotFalse( \get_option( Client_Authentication::KEY_OPTION ) );

		$key = $first['keys'][0];
		$this->assertSame( 'EC', $key['kty'] );
		$this->assertSame( 'P-256', $key['crv'] );
		$this->assertSame( 'ES256', $key['alg'] );
		$this->assertArrayNotHasKey( 'd', $key );

		$second = Client_Authentication::jwks();
		$this->assertSame( $key['kid'], $second['keys'][0]['kid'] );
	}

	/**
	 * The assertion is signed by the published key and addressed to the issuer.
	 */
	public function test_sign_request_attaches_an_assertion() {
		$body = Client_Authentication::sign_request( array( 'client_id' => Client::client_id() ), 'https://auth.example.com' );

		$this->assertIsArray( $body );
		$this->assertSame( Client_Authentication::ASSERTION_TYPE, $body['client_assertion_type'] );

		$claims = $this->jwt_payload( $body['client_assertion'] );
		$this->assertSame( 'https://auth.example.com', $claims['aud'] );
		$this->assertSame( Client::client_id(), $claims['iss'] );
		$this->assertSame( Client::client_id(), $claims['sub'] );
		$this->assertNotEmpty( $claims['jti'] );
		$this->assertSame( 60, $claims['exp'] - $claims['iat'] );
	}

	/**
	 * The assertion verifies against the published key, under the published
	 * key ID. If the two ever drift, every connect and refresh fails.
	 */
	public function test_assertion_verifies_against_the_published_jwks() {
		$body = Client_Authentication::sign_request( array( 'client_id' => Client::client_id() ), 'https://auth.example.com' );
		$jwk  = Client_Authentication::jwks()['keys'][0];

		list( $header_b64, $payload_b64, $signature_b64 ) = \explode( '.', $body['client_assertion'] );

		$header = (array) \json_decode( $this->base64url_decode( $header_b64 ), true );
		$this->assertSame( 'ES256', $header['alg'] );
		$this->assertSame( $jwk['kid'], $header['kid'], 'The assertion must name the published key.' );

		$verified = \openssl_verify(
			$header_b64 . '.' . $payload_b64,
			$this->raw_to_der( $this->base64url_decode( $signature_b64 ) ),
			$this->public_key_pem( $jwk ),
			OPENSSL_ALGO_SHA256
		);
		$this->assertSame( 1, $verified, 'The assertion signature must verify against the published public key.' );
	}

	/**
	 * Build a PEM public key from a P-256 JWK.
	 *
	 * @param array $jwk Public JWK with `x` and `y`.
	 * @return string
	 */
	private function public_key_pem( array $jwk ): string {
		// SubjectPublicKeyInfo prefix for an uncompressed P-256 point.
		$der = \hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' )
			. "\x04" . $this->base64url_decode( $jwk['x'] ) . $this->base64url_decode( $jwk['y'] );

		return "-----BEGIN PUBLIC KEY-----\n" . \chunk_split( \base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Convert a raw R||S ECDSA signature into the DER form OpenSSL verifies.
	 *
	 * @param string $raw 64-byte signature.
	 * @return string
	 */
	private function raw_to_der( string $raw ): string {
		$this->assertSame( 64, \strlen( $raw ), 'ES256 signatures are 64 raw bytes.' );

		$integer = static function ( string $bytes ): string {
			$bytes = \ltrim( $bytes, "\x00" );
			if ( '' === $bytes || \ord( $bytes[0] ) > 0x7f ) {
				$bytes = "\x00" . $bytes;
			}

			return "\x02" . \chr( \strlen( $bytes ) ) . $bytes;
		};

		$sequence = $integer( \substr( $raw, 0, 32 ) ) . $integer( \substr( $raw, 32 ) );

		return "\x30" . \chr( \strlen( $sequence ) ) . $sequence;
	}

	/**
	 * Decode base64url.
	 *
	 * @param string $data Encoded value.
	 * @return string
	 */
	private function base64url_decode( string $data ): string {
		$decoded = \base64_decode( \strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertIsString( $decoded );

		return $decoded;
	}

	/**
	 * While a live session matches the current key material, a key that no
	 * longer decrypts is reported and left in place.
	 */
	public function test_unreadable_key_is_an_error_while_a_session_uses_it() {
		$this->seed_connection( Encryption::key_fingerprint() );
		\update_option( Client_Authentication::KEY_OPTION, 'not-a-ciphertext', false );

		$jwks = Client_Authentication::jwks();

		$this->assertWPError( $jwks );
		$this->assertSame( 'atmosphere_client_authentication_key', $jwks->get_error_code() );
		$this->assertSame( 'not-a-ciphertext', \get_option( Client_Authentication::KEY_OPTION ) );
	}

	/**
	 * Without a live session an unreadable key is replaced, so the site can
	 * connect again after its salts were rotated.
	 */
	public function test_unreadable_key_is_regenerated_without_a_session() {
		\update_option( Client_Authentication::KEY_OPTION, 'not-a-ciphertext', false );

		$jwks = Client_Authentication::jwks();

		$this->assertIsArray( $jwks );
		$this->assertNotSame( 'not-a-ciphertext', \get_option( Client_Authentication::KEY_OPTION ) );
		$this->assertSame( $jwks['keys'][0]['kid'], Client_Authentication::jwks()['keys'][0]['kid'], 'The new key must persist.' );
	}

	/**
	 * A legacy session never signs with this key, so it must not keep a
	 * broken one alive: the reconnect it is asked for has to work.
	 */
	public function test_unreadable_key_is_regenerated_under_a_legacy_session() {
		$this->seed_connection( Encryption::key_fingerprint(), null );
		\update_option( Client_Authentication::KEY_OPTION, 'not-a-ciphertext', false );

		$this->assertIsArray( Client_Authentication::jwks() );
	}

	/**
	 * A session encrypted under old key material is already lost, so the
	 * signing key is replaced as well.
	 */
	public function test_unreadable_key_is_regenerated_when_the_session_key_changed() {
		$this->seed_connection( 'stale-fingerprint' );
		\update_option( Client_Authentication::KEY_OPTION, 'not-a-ciphertext', false );

		$this->assertIsArray( Client_Authentication::jwks() );
	}

	/**
	 * A decryptable row that is not a P-256 private key is treated the same
	 * as an unreadable one.
	 */
	public function test_malformed_key_is_an_error_while_a_session_uses_it() {
		$this->seed_connection( Encryption::key_fingerprint() );
		\update_option(
			Client_Authentication::KEY_OPTION,
			Encryption::encrypt( (string) \wp_json_encode( array( 'kty' => 'RSA' ) ) ),
			false
		);

		$body = Client_Authentication::sign_request( array( 'client_id' => Client::client_id() ), 'https://auth.example.com' );

		$this->assertWPError( $body );
		$this->assertSame( 'atmosphere_client_authentication_key', $body->get_error_code() );
	}

	/**
	 * A key with the right shape but corrupt members is unusable and must
	 * not be published; without a session it is replaced.
	 *
	 * @dataProvider provide_corrupt_members
	 *
	 * @param array $overrides Members to corrupt on an otherwise valid key.
	 */
	public function test_corrupt_key_members_are_rejected( array $overrides ) {
		$key = \array_merge( \Atmosphere\OAuth\DPoP::generate_key(), $overrides );
		\update_option( Client_Authentication::KEY_OPTION, Encryption::encrypt( (string) \wp_json_encode( $key ) ), false );

		$jwks = Client_Authentication::jwks();

		$this->assertIsArray( $jwks );
		$this->assertNotSame( $key['x'], $jwks['keys'][0]['x'], 'The corrupt key must not be published.' );
	}

	/**
	 * Corruptions a decryptable row can carry.
	 *
	 * @return array<string, array{0: array}>
	 */
	public function provide_corrupt_members(): array {
		return array(
			'empty coordinate'     => array( array( 'x' => '' ) ),
			'not base64url'        => array( array( 'y' => 'not*base64url' ) ),
			'short private scalar' => array( array( 'd' => 'AAAA' ) ),
			'coordinate too long'  => array( array( 'x' => \rtrim( \strtr( \base64_encode( \str_repeat( 'a', 33 ) ), '+/', '-_' ), '=' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * Without a session a malformed row is replaced too.
	 */
	public function test_malformed_key_is_regenerated_without_a_session() {
		\update_option(
			Client_Authentication::KEY_OPTION,
			Encryption::encrypt( (string) \wp_json_encode( array( 'kty' => 'RSA' ) ) ),
			false
		);

		$body = Client_Authentication::sign_request( array( 'client_id' => Client::client_id() ), 'https://auth.example.com' );

		$this->assertIsArray( $body );
		$this->assertNotEmpty( $body['client_assertion'] );
	}
}
