<?php
/**
 * Tests for decrypt-failure classification and recovery.
 *
 * A connection whose tokens no longer decrypt (rotated salts, corrupted
 * ciphertext) must flag itself for reauth and classify the failure via
 * the stored key fingerprint, instead of failing every publish with a
 * retry hint that can never succeed.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests\OAuth;

use WP_UnitTestCase;
use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\Encryption;
use function Atmosphere\is_connected;
use function Atmosphere\needs_reauth;

/**
 * Client decrypt-failure tests.
 */
class Test_Client_Decrypt extends WP_UnitTestCase {

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );

		parent::tear_down();
	}

	/**
	 * Seed a connection whose access token cannot be decrypted.
	 *
	 * The ciphertext is a validly base64-encoded blob of the right
	 * length that was never produced by `Encryption::encrypt()`, so
	 * `decrypt()` fails the secretbox authentication check — the same
	 * signature as tokens written under rotated salts.
	 *
	 * @param array $overrides Connection fields to override.
	 * @return array The stored connection.
	 */
	private function seed_undecryptable_connection( array $overrides = array() ): array {
		$garbage = \base64_encode( \str_repeat( "\0", SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 42 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$connection = \array_merge(
			array(
				'access_token'    => $garbage,
				'refresh_token'   => $garbage,
				'dpop_jwk'        => $garbage,
				'key_fingerprint' => Encryption::key_fingerprint(),
				'did'             => 'did:plc:test123',
				'handle'          => 'test.example.com',
				'pds_endpoint'    => 'https://pds.example.com',
				'token_endpoint'  => 'https://auth.example.com/oauth/token',
				'expires_at'      => \time() + 3600,
				'needs_reauth'    => false,
			),
			$overrides
		);

		\update_option( 'atmosphere_connection', $connection, false );

		return $connection;
	}

	/**
	 * A matching fingerprint means the key did not change: the failure is
	 * classified as corrupted data, and the connection is flagged so
	 * publishing short-circuits and the reconnect notice appears.
	 */
	public function test_decrypt_failure_with_matching_fingerprint_is_classified_as_corrupt() {
		$this->seed_undecryptable_connection();

		$result = Client::access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_decrypt', $result->get_error_code() );

		$stored = \get_option( 'atmosphere_connection' );
		$this->assertTrue( $stored['needs_reauth'] );
		$this->assertSame( 'decrypt_failed', $stored['reauth_reason'] );
		$this->assertSame( '', $stored['access_token'] );
		$this->assertTrue( needs_reauth() );
		$this->assertFalse( is_connected() );
	}

	/**
	 * A mismatching fingerprint means the site's key material changed
	 * (salt rotation, regenerated wp-config) — the error and the stored
	 * reason say so, so the admin notice can explain the actual cause.
	 */
	public function test_decrypt_failure_with_mismatching_fingerprint_is_classified_as_key_change() {
		$this->seed_undecryptable_connection(
			array( 'key_fingerprint' => \str_repeat( 'a', 32 ) )
		);

		$result = Client::access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_key_changed', $result->get_error_code() );

		$stored = \get_option( 'atmosphere_connection' );
		$this->assertTrue( $stored['needs_reauth'] );
		$this->assertSame( 'key_changed', $stored['reauth_reason'] );
	}

	/**
	 * Rows connected before fingerprints existed cannot be classified —
	 * they fall back to the generic decrypt error rather than falsely
	 * claiming the keys changed.
	 */
	public function test_decrypt_failure_without_fingerprint_falls_back_to_generic_error() {
		$connection = $this->seed_undecryptable_connection();
		unset( $connection['key_fingerprint'] );
		\update_option( 'atmosphere_connection', $connection, false );

		$result = Client::access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_decrypt', $result->get_error_code() );

		$stored = \get_option( 'atmosphere_connection' );
		$this->assertSame( 'decrypt_failed', $stored['reauth_reason'] );
	}

	/**
	 * The reauth stamp is guarded against concurrent reconnects: when the
	 * stored row no longer holds the ciphertext the failing caller read
	 * (the user reconnected mid-flight), the new connection is left alone.
	 */
	public function test_flag_decrypt_failure_leaves_replaced_connection_alone() {
		$stale = $this->seed_undecryptable_connection();

		/* Simulate a reconnect landing after the caller read the row. */
		$fresh                  = $stale;
		$fresh['access_token']  = Encryption::encrypt( 'new-access-token' );
		$fresh['refresh_token'] = Encryption::encrypt( 'new-refresh-token' );
		\update_option( 'atmosphere_connection', $fresh, false );

		$result = Client::flag_decrypt_failure( $stale, 'access_token' );

		$this->assertWPError( $result );

		$stored = \get_option( 'atmosphere_connection' );
		$this->assertFalse( $stored['needs_reauth'] );
		$this->assertArrayNotHasKey( 'reauth_reason', $stored );
		$this->assertTrue( is_connected() );
	}

	/**
	 * The guard compares the field that actually failed: when a concurrent
	 * refresh already repaired the access token (without rotating the
	 * refresh token), the stale caller must not wipe the repair — even
	 * though the refresh-token ciphertext still matches.
	 */
	public function test_flag_decrypt_failure_spares_concurrently_repaired_access_token() {
		$stale = $this->seed_undecryptable_connection();

		/* A refresh landed in-flight: new access token, same refresh token. */
		$repaired                 = $stale;
		$repaired['access_token'] = Encryption::encrypt( 'repaired-access-token' );
		\update_option( 'atmosphere_connection', $repaired, false );

		$result = Client::flag_decrypt_failure( $stale, 'access_token' );

		$this->assertWPError( $result );

		$stored = \get_option( 'atmosphere_connection' );
		$this->assertFalse( $stored['needs_reauth'] );
		$this->assertSame( $repaired['access_token'], $stored['access_token'] );
	}

	/**
	 * A later reason-less reauth stamp (the `invalid_grant` refresh path)
	 * clears a stale `reauth_reason` left by an earlier decrypt failure,
	 * so the admin notice explains the current cause, not a historic one.
	 */
	public function test_reasonless_reauth_stamp_clears_stale_reason() {
		$conn = $this->seed_undecryptable_connection(
			array(
				'reauth_reason' => Client::REAUTH_REASON_KEY_CHANGED,
			)
		);

		$method = new \ReflectionMethod( Client::class, 'mark_needs_reauth' );
		$method->invoke( null, $conn, 'refresh_token' );

		$stored = \get_option( 'atmosphere_connection' );

		$this->assertTrue( $stored['needs_reauth'] );
		$this->assertArrayNotHasKey( 'reauth_reason', $stored );
	}
}
