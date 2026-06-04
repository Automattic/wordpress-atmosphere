<?php
/**
 * Tests for the PDS API client.
 *
 * Covers the recovery branches in {@see API::request()} — the
 * `use_dpop_nonce` retry is exercised in other suites; here we focus
 * on the proactive token-refresh retry that recovers from a 401
 * `InvalidToken` / `ExpiredToken` / `AuthenticationRequired` server
 * response.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests;

use Atmosphere\API;
use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\DPoP;
use Atmosphere\OAuth\Encryption;

/**
 * API request tests.
 */
class Test_API extends \WP_UnitTestCase {

	/**
	 * Token endpoint URL used in tests.
	 *
	 * @var string
	 */
	private const TOKEN_ENDPOINT = 'https://auth.example.com/oauth/token';

	/**
	 * PDS endpoint URL used in tests.
	 *
	 * @var string
	 */
	private const PDS_ENDPOINT = 'https://pds.example.com';

	/**
	 * Seed a valid encrypted connection before each test so
	 * `Client::access_token()` succeeds without triggering a refresh
	 * on the first request — the test stubs assert behaviour from the
	 * 401-recovery branch specifically.
	 */
	public function set_up(): void {
		parent::set_up();

		$dpop_jwk = DPoP::generate_key();

		\update_option(
			'atmosphere_connection',
			array(
				'access_token'   => Encryption::encrypt( 'stale-access-token' ),
				'refresh_token'  => Encryption::encrypt( 'test-refresh-token' ),
				'dpop_jwk'       => Encryption::encrypt( (string) \wp_json_encode( $dpop_jwk ) ),
				'did'            => 'did:plc:test123',
				'handle'         => 'test.example.com',
				'pds_endpoint'   => self::PDS_ENDPOINT,
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
				'pds_endpoint' => self::PDS_ENDPOINT,
			)
		);
	}

	/**
	 * Tear down test state.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( Client::REFRESH_LOCK_OPTION );
		\remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * Stub `pre_http_request` to route token-endpoint and PDS calls
	 * to per-URL callables. Any unmatched URL falls through to the
	 * bootstrap's `http_disable_request` and surfaces as a cancelled
	 * `WP_Error`, which is exactly the signal we want if a test
	 * accidentally makes an unexpected request.
	 *
	 * @param callable $token_handler Returns an array shape suitable for `pre_http_request`.
	 * @param callable $pds_handler   Returns an array shape suitable for `pre_http_request`.
	 */
	private function stub_http( callable $token_handler, callable $pds_handler ): void {
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( $token_handler, $pds_handler ) {
				if ( false !== \strpos( $url, 'oauth/token' ) ) {
					return $token_handler();
				}

				if ( false !== \strpos( $url, '/xrpc/' ) ) {
					return $pds_handler();
				}

				return $response;
			},
			10,
			3
		);
	}

	/**
	 * Build a synthetic `pre_http_request` response payload.
	 *
	 * @param int   $status HTTP status code.
	 * @param array $body   Response body to JSON-encode.
	 * @return array
	 */
	private function http_response( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
			'body'     => (string) \wp_json_encode( $body ),
		);
	}

	/**
	 * When the PDS rejects the access token with a 401 `InvalidToken`,
	 * `API::request()` must call `Client::refresh()` exactly once and
	 * replay the request with the rotated token.
	 */
	public function test_request_recovers_from_invalid_token_via_refresh() {
		$pds_calls   = 0;
		$token_calls = 0;

		$this->stub_http(
			function () use ( &$token_calls ) {
				++$token_calls;
				return $this->http_response(
					200,
					array(
						'access_token'  => 'rotated-access-token',
						'refresh_token' => 'rotated-refresh-token',
						'expires_in'    => 3600,
					)
				);
			},
			function () use ( &$pds_calls ) {
				++$pds_calls;

				if ( 1 === $pds_calls ) {
					return $this->http_response(
						401,
						array(
							'error'   => 'InvalidToken',
							'message' => 'Token has been revoked.',
						)
					);
				}

				return $this->http_response( 200, array( 'ok' => true ) );
			}
		);

		$result = API::get( '/xrpc/com.atproto.repo.getRecord' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] ?? false, 'Retry should surface the success body.' );
		$this->assertSame( 2, $pds_calls, 'PDS should be called twice: initial + one retry.' );
		$this->assertSame( 1, $token_calls, 'Token endpoint should be called exactly once.' );

		$conn = \get_option( 'atmosphere_connection' );
		$this->assertSame(
			'rotated-access-token',
			Encryption::decrypt( $conn['access_token'] ),
			'Rotated access token must be persisted before the retry.'
		);
	}

	/**
	 * If the retried request also fails with a 401, the `auth_retried`
	 * guard must prevent another refresh-and-retry — the second 401 is
	 * surfaced as a regular `atmosphere_pds` error.
	 */
	public function test_request_does_not_retry_twice_on_persistent_invalid_token() {
		$pds_calls   = 0;
		$token_calls = 0;

		$this->stub_http(
			function () use ( &$token_calls ) {
				++$token_calls;
				return $this->http_response(
					200,
					array(
						'access_token'  => 'rotated-access-token',
						'refresh_token' => 'rotated-refresh-token',
						'expires_in'    => 3600,
					)
				);
			},
			function () use ( &$pds_calls ) {
				++$pds_calls;
				return $this->http_response(
					401,
					array(
						'error'   => 'InvalidToken',
						'message' => 'Token has been revoked.',
					)
				);
			}
		);

		$result = API::get( '/xrpc/com.atproto.repo.getRecord' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_pds', $result->get_error_code() );
		$this->assertSame( 2, $pds_calls, 'PDS must be called twice maximum (initial + one retry).' );
		$this->assertSame( 1, $token_calls, 'Token endpoint must be called only once across the retry.' );
	}

	/**
	 * If `Client::refresh()` itself fails with a non-locked error
	 * (no refresh token, decrypt failure, transient network blip),
	 * surface that error directly instead of retrying the PDS call
	 * with the same stale token.
	 */
	public function test_request_surfaces_non_locked_refresh_error_without_retry() {
		// Wipe the refresh token so `Client::refresh()` returns
		// `atmosphere_no_refresh` immediately.
		$conn                  = \get_option( 'atmosphere_connection' );
		$conn['refresh_token'] = '';
		\update_option( 'atmosphere_connection', $conn );

		$pds_calls = 0;

		$this->stub_http(
			function () {
				$this->fail( 'Token endpoint must not be called when there is no refresh token.' );
			},
			function () use ( &$pds_calls ) {
				++$pds_calls;
				return $this->http_response(
					401,
					array(
						'error'   => 'ExpiredToken',
						'message' => 'Token has expired.',
					)
				);
			}
		);

		$result = API::get( '/xrpc/com.atproto.repo.getRecord' );

		$this->assertWPError( $result );
		$this->assertSame(
			'atmosphere_no_refresh',
			$result->get_error_code(),
			'Refresh-side errors must propagate instead of being masked by a second PDS error.'
		);
		$this->assertSame( 1, $pds_calls, 'PDS should only be hit once — no retry on non-locked refresh error.' );
	}

	/**
	 * A malformed `atmosphere_pre_upload_blob` return (scalar / object)
	 * is coerced into a WP_Error rather than fataling against the
	 * `array|WP_Error` return type. Mirrors `atmosphere_pre_apply_writes`.
	 */
	public function test_upload_blob_pre_filter_rejects_invalid_return() {
		$invalid = static function () {
			return 'not-an-array';
		};
		\add_filter( 'atmosphere_pre_upload_blob', $invalid, 10, 0 );

		$result = API::upload_blob( '/tmp/whatever.jpg', 'image/jpeg' );

		\remove_filter( 'atmosphere_pre_upload_blob', $invalid, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_invalid_pre_upload_blob_return', $result->get_error_code() );
	}

	/**
	 * A well-formed array return from `atmosphere_pre_upload_blob`
	 * short-circuits the upload and is returned verbatim.
	 */
	public function test_upload_blob_pre_filter_short_circuits_with_array() {
		$blob    = array( 'blob' => array( 'cid' => 'bafyshort' ) );
		$shorter = static function () use ( $blob ) {
			return $blob;
		};
		\add_filter( 'atmosphere_pre_upload_blob', $shorter, 10, 0 );

		$result = API::upload_blob( '/tmp/does-not-exist.jpg', 'image/jpeg' );

		\remove_filter( 'atmosphere_pre_upload_blob', $shorter, 10 );

		$this->assertSame( $blob, $result );
	}
}
