<?php
/**
 * Tests for the pre-publish session verification probe.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests;

use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\DPoP;
use Atmosphere\OAuth\Encryption;
use function Atmosphere\is_connected;
use function Atmosphere\needs_reauth;
use function Atmosphere\verify_connection;
use const Atmosphere\SESSION_VERIFIED_TRANSIENT;

/**
 * Session verification tests.
 */
class Test_Verify_Connection extends \WP_UnitTestCase {

	/**
	 * Token endpoint URL used in tests.
	 *
	 * @var string
	 */
	private const TOKEN_ENDPOINT = 'https://auth.example.com/oauth/token';

	/**
	 * Requests the mocked transport saw, by URL.
	 *
	 * @var string[]
	 */
	private array $requests = array();

	/**
	 * Set up a live, decryptable connection before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->requests = array();

		$dpop_jwk = DPoP::generate_key();

		\update_option(
			'atmosphere_connection',
			array(
				'access_token'   => Encryption::encrypt( 'test-access-token' ),
				'refresh_token'  => Encryption::encrypt( 'test-refresh-token' ),
				'dpop_jwk'       => Encryption::encrypt( \wp_json_encode( $dpop_jwk ) ),
				'did'            => 'did:plc:test123',
				'handle'         => 'test.example.com',
				'pds_endpoint'   => 'https://pds.example.com',
				'token_endpoint' => self::TOKEN_ENDPOINT,
				'expires_at'     => \time() + 3600,
				'needs_reauth'   => false,
			)
		);

		\update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'test.example.com',
				'pds_endpoint' => 'https://pds.example.com',
			)
		);
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( Client::REFRESH_LOCK_OPTION );
		\delete_option( Client::DISCONNECTED_OPTION );
		\delete_transient( SESSION_VERIFIED_TRANSIENT );
		\remove_all_filters( 'pre_http_request' );
		\remove_all_filters( 'atmosphere_session_verify_ttl' );

		parent::tear_down();
	}

	/**
	 * Mock the PDS session endpoint, and optionally the token endpoint.
	 *
	 * @param int   $session_status HTTP status for `getSession`.
	 * @param array $session_body   Response body for `getSession`.
	 * @param int   $token_status   HTTP status for the token endpoint.
	 * @param array $token_body     Response body for the token endpoint.
	 */
	private function mock_transport( int $session_status, array $session_body, int $token_status = 200, array $token_body = array() ): void {
		\add_filter(
			'pre_http_request',
			function ( $response, $args, $url ) use ( $session_status, $session_body, $token_status, $token_body ) {
				$this->requests[] = $url;

				if ( false !== \strpos( $url, 'oauth/token' ) ) {
					return array(
						'response' => array( 'code' => $token_status ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => \wp_json_encode( $token_body ),
					);
				}

				if ( false !== \strpos( $url, 'getSession' ) ) {
					return array(
						'response' => array( 'code' => $session_status ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => \wp_json_encode( $session_body ),
					);
				}

				return $response;
			},
			1,
			3
		);
	}

	/**
	 * How many times the session endpoint was called.
	 *
	 * @return int
	 */
	private function session_calls(): int {
		return \count(
			\array_filter(
				$this->requests,
				static fn( string $url ): bool => false !== \strpos( $url, 'getSession' )
			)
		);
	}

	/**
	 * A disconnected site is never probed: there is nothing to ask about,
	 * and the existing surfaces already explain the state.
	 */
	public function test_disconnected_site_is_not_probed() {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );

		$this->mock_transport( 200, array( 'did' => 'did:plc:test123' ) );

		$this->assertFalse( verify_connection() );
		$this->assertSame( 0, $this->session_calls(), 'A disconnected site must not reach the network.' );
	}

	/**
	 * A session the PDS accepts verifies, and the verdict is cached so the
	 * keystroke-adjacent panel does not re-probe on every open.
	 */
	public function test_accepted_session_verifies_and_caches() {
		$this->mock_transport(
			200,
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'test.example.com',
			)
		);

		$this->assertTrue( verify_connection() );
		$this->assertSame( 1, $this->session_calls() );

		$this->assertTrue( verify_connection() );
		$this->assertSame( 1, $this->session_calls(), 'The second call must be served from cache.' );
	}

	/**
	 * `$force` is the escape hatch for a caller that needs certainty now.
	 */
	public function test_force_bypasses_the_cache() {
		$this->mock_transport( 200, array( 'did' => 'did:plc:test123' ) );

		verify_connection();
		verify_connection( true );

		$this->assertSame( 2, $this->session_calls() );
	}

	/**
	 * A TTL of zero disables caching without disabling the probe.
	 */
	public function test_zero_ttl_disables_caching() {
		$this->mock_transport( 200, array( 'did' => 'did:plc:test123' ) );
		\add_filter( 'atmosphere_session_verify_ttl', '__return_zero' );

		verify_connection();
		verify_connection();

		$this->assertFalse( \get_transient( SESSION_VERIFIED_TRANSIENT ) );
		$this->assertSame( 2, $this->session_calls() );
	}

	/**
	 * A revoked session is the case this probe exists for.
	 *
	 * The PDS rejects the access token, the request path refreshes, the auth
	 * server refuses the refresh token, and the connection ends up flagged —
	 * all through the ordinary machinery, so every surface built on
	 * `is_connected()` reports it without new copy.
	 */
	public function test_revoked_session_fails_verification_and_flags_the_connection() {
		$this->mock_transport(
			401,
			array( 'error' => 'InvalidToken' ),
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Refresh token revoked.',
			)
		);

		$this->assertFalse( verify_connection(), 'A revoked session must not verify.' );
		$this->assertTrue( needs_reauth(), 'The probe must leave the connection flagged for reconnect.' );
		$this->assertFalse( is_connected(), 'Every surface reading local state must now agree.' );
		$this->assertFalse(
			\get_transient( SESSION_VERIFIED_TRANSIENT ),
			'A failed probe must never be cached as a success.'
		);
	}

	/**
	 * An inconclusive probe is not evidence of a dead session.
	 *
	 * A PDS 5xx says the check did not complete. Treating that as a
	 * disconnection would block an author over our own inability to ask,
	 * which is worse than the bug the probe fixes.
	 */
	public function test_unreachable_pds_leaves_the_connection_alone() {
		$this->mock_transport( 503, array( 'error' => 'InternalServerError' ) );

		$this->assertTrue( verify_connection(), 'An inconclusive probe must not report a dead session.' );
		$this->assertFalse( needs_reauth(), 'A transient PDS failure must not flag the connection.' );
		$this->assertTrue( is_connected() );
		$this->assertFalse(
			\get_transient( SESSION_VERIFIED_TRANSIENT ),
			'An inconclusive probe must not be cached as a success.'
		);
	}

	/**
	 * A rate-limited probe is inconclusive for the same reason.
	 */
	public function test_rate_limited_probe_leaves_the_connection_alone() {
		$this->mock_transport( 429, array( 'error' => 'RateLimitExceeded' ) );

		$this->assertTrue( verify_connection() );
		$this->assertFalse( needs_reauth() );
	}

	/**
	 * Disconnecting drops the cached verdict, so a reconnect — especially to
	 * a different account — is probed fresh instead of inheriting the
	 * previous account's clean bill of health.
	 */
	public function test_disconnect_clears_the_cached_verdict() {
		$this->mock_transport( 200, array( 'did' => 'did:plc:test123' ) );

		verify_connection();
		$this->assertNotFalse( \get_transient( SESSION_VERIFIED_TRANSIENT ) );

		Client::disconnect();

		$this->assertFalse( \get_transient( SESSION_VERIFIED_TRANSIENT ) );
	}
}
