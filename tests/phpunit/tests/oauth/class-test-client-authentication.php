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
	 * Remove the stored key between tests.
	 */
	public function tear_down(): void {
		\delete_option( Client_Authentication::KEY_OPTION );
		parent::tear_down();
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
	 * A key that no longer decrypts is reported, not regenerated.
	 */
	public function test_unreadable_key_is_an_error() {
		\update_option( Client_Authentication::KEY_OPTION, 'not-a-ciphertext', false );

		$jwks = Client_Authentication::jwks();

		$this->assertWPError( $jwks );
		$this->assertSame( 'atmosphere_client_authentication_key', $jwks->get_error_code() );
		$this->assertSame( 'not-a-ciphertext', \get_option( Client_Authentication::KEY_OPTION ) );
	}

	/**
	 * A decryptable row that is not a P-256 private key is rejected.
	 */
	public function test_malformed_key_is_an_error() {
		\update_option(
			Client_Authentication::KEY_OPTION,
			Encryption::encrypt( (string) \wp_json_encode( array( 'kty' => 'RSA' ) ) ),
			false
		);

		$body = Client_Authentication::sign_request( array( 'client_id' => Client::client_id() ), 'https://auth.example.com' );

		$this->assertWPError( $body );
		$this->assertSame( 'atmosphere_client_authentication_key', $body->get_error_code() );
	}
}
