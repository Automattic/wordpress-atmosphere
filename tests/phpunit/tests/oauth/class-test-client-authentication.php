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
	 * @param string $fingerprint Key fingerprint stored on the connection.
	 */
	private function seed_connection( string $fingerprint ): void {
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
