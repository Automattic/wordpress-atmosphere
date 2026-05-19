<?php
/**
 * Tests for OAuth token refresh resilience.
 *
 * Verifies that the connection is only deleted for permanent OAuth
 * errors and preserved for transient failures.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests\OAuth;

use WP_UnitTestCase;
use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\DPoP;
use Atmosphere\OAuth\Encryption;
use function Atmosphere\has_identity;
use function Atmosphere\is_connected;
use function Atmosphere\needs_reauth;

/**
 * Client refresh tests.
 */
class Test_Client_Refresh extends WP_UnitTestCase {

	/**
	 * Token endpoint URL used in tests.
	 *
	 * @var string
	 */
	private const TOKEN_ENDPOINT = 'https://auth.example.com/oauth/token';

	/**
	 * Set up a valid encrypted connection before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$dpop_jwk = DPoP::generate_key();

		\update_option(
			'atmosphere_connection',
			array(
				'access_token'   => Encryption::encrypt( 'old-access-token' ),
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
		\delete_transient( 'atmosphere_refresh_lock' );
		\remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * Mock the token endpoint response.
	 *
	 * @param int    $status HTTP status code.
	 * @param array  $body   Response body.
	 * @param string $nonce  Optional DPoP nonce header.
	 */
	private function mock_token_response( int $status, array $body, string $nonce = '' ): void {
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( $status, $body, $nonce ) {
				if ( false !== \strpos( $url, 'oauth/token' ) ) {
					$headers = array();
					if ( $nonce ) {
						$headers['dpop-nonce'] = $nonce;
					}

					return array(
						'response' => array( 'code' => $status ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
						'body'     => \wp_json_encode( $body ),
					);
				}

				return $response;
			},
			1,
			3
		);
	}

	/**
	 * Test that a successful refresh updates the connection.
	 */
	public function test_successful_refresh_updates_connection() {
		$this->mock_token_response(
			200,
			array(
				'access_token'  => 'new-access-token',
				'refresh_token' => 'new-refresh-token',
				'expires_in'    => 3600,
			)
		);

		$result = Client::refresh();

		$this->assertTrue( $result );

		$conn = \get_option( 'atmosphere_connection' );
		$this->assertNotEmpty( $conn['access_token'] );
		$this->assertNotEmpty( $conn['refresh_token'] );

		// Verify the new token can be decrypted.
		$decrypted = Encryption::decrypt( $conn['access_token'] );
		$this->assertSame( 'new-access-token', $decrypted );
	}

	/**
	 * Test that invalid_grant marks the connection for reauth without
	 * deleting it, and preserves the identity option for the public
	 * verification headers.
	 */
	public function test_invalid_grant_marks_needs_reauth() {
		$this->mock_token_response(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Refresh token expired.',
			)
		);

		$result = Client::refresh();

		$this->assertWPError( $result );

		$conn = \get_option( 'atmosphere_connection' );
		$this->assertNotFalse( $conn );
		$this->assertTrue( ! empty( $conn['needs_reauth'] ) );
		$this->assertEmpty( $conn['access_token'] );

		$identity = \get_option( 'atmosphere_identity' );
		$this->assertNotFalse( $identity );
		$this->assertSame( 'did:plc:test123', $identity['did'] );
		$this->assertSame( 'test.example.com', $identity['handle'] );
	}

	/**
	 * Test that invalid_client marks the connection for reauth.
	 */
	public function test_invalid_client_marks_needs_reauth() {
		$this->mock_token_response(
			401,
			array(
				'error'             => 'invalid_client',
				'error_description' => 'Client authentication failed.',
			)
		);

		$result = Client::refresh();

		$this->assertWPError( $result );

		$conn = \get_option( 'atmosphere_connection' );
		$this->assertNotFalse( $conn );
		$this->assertTrue( ! empty( $conn['needs_reauth'] ) );
		$this->assertNotFalse( \get_option( 'atmosphere_identity' ) );
	}

	/**
	 * Test that unauthorized_client marks the connection for reauth.
	 */
	public function test_unauthorized_client_marks_needs_reauth() {
		$this->mock_token_response(
			403,
			array(
				'error'             => 'unauthorized_client',
				'error_description' => 'Client not authorized.',
			)
		);

		$result = Client::refresh();

		$this->assertWPError( $result );

		$conn = \get_option( 'atmosphere_connection' );
		$this->assertNotFalse( $conn );
		$this->assertTrue( ! empty( $conn['needs_reauth'] ) );
		$this->assertNotFalse( \get_option( 'atmosphere_identity' ) );
	}

	/**
	 * Test that a successful refresh clears any prior needs_reauth flag.
	 */
	public function test_successful_refresh_clears_needs_reauth_flag() {
		$conn                 = \get_option( 'atmosphere_connection' );
		$conn['needs_reauth'] = true;
		\update_option( 'atmosphere_connection', $conn );

		$this->mock_token_response(
			200,
			array(
				'access_token'  => 'fresh-token',
				'refresh_token' => 'fresh-refresh',
				'expires_in'    => 3600,
			)
		);

		$result = Client::refresh();

		$this->assertTrue( $result );

		$conn = \get_option( 'atmosphere_connection' );
		$this->assertEmpty( $conn['needs_reauth'] );
	}

	/**
	 * Invariant the whole PR rests on: after a permanent refresh
	 * failure, `is_connected()` flips to false (so publish/comment
	 * paths short-circuit) while `has_identity()` stays true (so
	 * verification headers keep serving) and `needs_reauth()` is true
	 * (so the admin notice and reconnect flow surface).
	 */
	public function test_needs_reauth_decouples_session_from_identity() {
		$this->mock_token_response(
			400,
			array( 'error' => 'invalid_grant' )
		);

		$result = Client::refresh();

		$this->assertWPError( $result );
		$this->assertFalse( is_connected(), 'Publish path should short-circuit while needs_reauth.' );
		$this->assertTrue( has_identity(), 'Verification headers should keep serving while needs_reauth.' );
		$this->assertTrue( needs_reauth(), 'Admin notice gate should fire while needs_reauth.' );
	}

	/**
	 * Test that the refresh lock blocks a concurrent caller and that the
	 * blocked caller returns success when the option already shows a
	 * freshly rotated token.
	 */
	public function test_refresh_lock_short_circuits_when_token_already_fresh() {
		// Simulate another caller holding the lock with a future expiry.
		\set_transient( 'atmosphere_refresh_lock', \time(), 30 );

		// Pretend that caller already wrote a fresh token.
		$conn                 = \get_option( 'atmosphere_connection' );
		$conn['access_token'] = Encryption::encrypt( 'someone-elses-fresh-token' );
		$conn['expires_at']   = \time() + 3600;
		$conn['needs_reauth'] = false;
		\update_option( 'atmosphere_connection', $conn );

		$result = Client::refresh();

		$this->assertTrue( $result );
	}

	/**
	 * Test that the refresh lock returns a soft error if a concurrent
	 * caller is mid-refresh and the stored token is not yet fresh.
	 */
	public function test_refresh_lock_returns_soft_error_when_token_still_stale() {
		\set_transient( 'atmosphere_refresh_lock', \time(), 30 );

		// Force the stored token to look stale.
		$conn               = \get_option( 'atmosphere_connection' );
		$conn['expires_at'] = \time() - 60;
		\update_option( 'atmosphere_connection', $conn );

		$result = Client::refresh();

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_refresh_locked', $result->get_error_code() );

		// The lock should still be held by the (mocked) other caller.
		$this->assertNotFalse( \get_transient( 'atmosphere_refresh_lock' ) );
	}

	/**
	 * Test that a 500 server error preserves the connection.
	 */
	public function test_server_error_preserves_connection() {
		$this->mock_token_response(
			500,
			array( 'error' => 'server_error' )
		);

		$result = Client::refresh();

		$this->assertWPError( $result );
		$this->assertNotFalse( \get_option( 'atmosphere_connection' ) );
	}

	/**
	 * Test that a 429 rate-limit preserves the connection.
	 */
	public function test_rate_limit_preserves_connection() {
		$this->mock_token_response(
			429,
			array( 'error' => 'too_many_requests' )
		);

		$result = Client::refresh();

		$this->assertWPError( $result );
		$this->assertNotFalse( \get_option( 'atmosphere_connection' ) );
	}

	/**
	 * Test that a 503 service unavailable preserves the connection.
	 */
	public function test_service_unavailable_preserves_connection() {
		$this->mock_token_response(
			503,
			array()
		);

		$result = Client::refresh();

		$this->assertWPError( $result );
		$this->assertNotFalse( \get_option( 'atmosphere_connection' ) );
	}

	/**
	 * Test that a non-JSON response (e.g., HTML 502 from a load balancer)
	 * preserves the connection.
	 */
	public function test_non_json_response_preserves_connection() {
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				if ( false !== \strpos( $url, 'oauth/token' ) ) {
					return array(
						'response' => array( 'code' => 502 ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => '<html><body>Bad Gateway</body></html>',
					);
				}

				return $response;
			},
			1,
			3
		);

		$result = Client::refresh();

		$this->assertWPError( $result );
		$this->assertNotFalse( \get_option( 'atmosphere_connection' ) );
	}

	/**
	 * Test that missing refresh token returns error without deleting connection.
	 */
	public function test_missing_refresh_token_returns_error() {
		$conn = \get_option( 'atmosphere_connection' );
		unset( $conn['refresh_token'] );
		\update_option( 'atmosphere_connection', $conn );

		$result = Client::refresh();

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_no_refresh', $result->get_error_code() );
		$this->assertNotFalse( \get_option( 'atmosphere_connection' ) );
	}
}
