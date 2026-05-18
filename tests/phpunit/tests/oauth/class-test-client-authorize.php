<?php
/**
 * Tests for OAuth `authorize()` session-storage hygiene.
 *
 * Specifically: the ES256 private DPoP key must never sit in a
 * plaintext transient where any reader of `wp_options` (or the
 * object cache) can lift it.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests\OAuth;

use WP_UnitTestCase;
use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\Encryption;

/**
 * Authorize-flow transient encryption tests.
 */
class Test_Client_Authorize extends WP_UnitTestCase {

	/**
	 * Tear down transients between tests.
	 */
	public function tear_down(): void {
		\delete_transient( 'atmosphere_oauth_dpop_jwk' );
		\delete_transient( 'atmosphere_oauth_state' );
		\delete_transient( 'atmosphere_oauth_verifier' );
		\delete_transient( 'atmosphere_oauth_resolved' );
		\remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * The DPoP JWK transient set during `authorize()` is encrypted
	 * with `Encryption::encrypt()`. Decrypts back to a JWK array
	 * with a private `d` parameter (ES256 key material).
	 *
	 * We don't exercise the full `authorize()` path here — it would
	 * require mocking PAR + the full resolution chain. Instead this
	 * test pins the storage contract: any plaintext JWK in the
	 * transient is a regression of ATM-002.
	 */
	public function test_dpop_jwk_transient_encrypted_round_trips() {
		$jwk = array(
			'kty' => 'EC',
			'crv' => 'P-256',
			'x'   => 'public-x',
			'y'   => 'public-y',
			'd'   => 'private-d',
		);

		$ciphertext = Encryption::encrypt( (string) \wp_json_encode( $jwk ) );

		\set_transient( 'atmosphere_oauth_dpop_jwk', $ciphertext, HOUR_IN_SECONDS );

		$stored = \get_transient( 'atmosphere_oauth_dpop_jwk' );

		$this->assertIsString( $stored, 'Transient should be a string blob, not the raw array.' );
		$this->assertStringNotContainsString(
			'private-d',
			$stored,
			'Private DPoP key material must not appear in the transient ciphertext.'
		);
		$this->assertStringNotContainsString(
			'"d":',
			$stored,
			'JWK JSON markers must not appear unencrypted in the transient.'
		);

		$decrypted = Encryption::decrypt( $stored );
		$this->assertIsString( $decrypted );

		$round_tripped = \json_decode( $decrypted, true );
		$this->assertIsArray( $round_tripped );
		$this->assertSame( $jwk, $round_tripped );
	}

	/**
	 * A user who started the OAuth flow on the pre-encryption version
	 * of the plugin sees a non-string (plain PHP array) in the
	 * `atmosphere_oauth_dpop_jwk` transient when they return after
	 * upgrading. `handle_callback()` MUST treat the session as expired
	 * and surface a clear, recoverable error — never attempt to use
	 * the orphaned key material, never fall through to decryption.
	 */
	public function test_handle_callback_rejects_plaintext_dpop_jwk_from_pre_upgrade_session() {
		\set_transient( 'atmosphere_oauth_state', 'state-abc', HOUR_IN_SECONDS );
		\set_transient( 'atmosphere_oauth_verifier', 'verifier-xyz', HOUR_IN_SECONDS );
		// Plaintext array — what the pre-encryption code wrote.
		\set_transient(
			'atmosphere_oauth_dpop_jwk',
			array(
				'kty' => 'EC',
				'd'   => 'leaked',
			),
			HOUR_IN_SECONDS
		);
		\set_transient(
			'atmosphere_oauth_resolved',
			array(
				'did'          => 'did:plc:test',
				'pds_endpoint' => 'https://pds.example.com',
				'auth_server'  => array(
					'token_endpoint' => 'https://auth.example.com/oauth/token',
					'issuer_url'     => 'https://auth.example.com',
				),
				'handle'       => 'alice.example.com',
			),
			HOUR_IN_SECONDS
		);

		$result = Client::handle_callback( 'code-123', 'state-abc' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_legacy_session', $result->get_error_code() );
	}
}
