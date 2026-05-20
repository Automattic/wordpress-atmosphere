<?php
/**
 * Tests for `Sanitize::handle()` redirect contract.
 *
 * The method is the front-door entry to the OAuth flow: an admin
 * types a handle, this method runs as a `register_setting()`
 * sanitize callback, resolves the handle, and either redirects to
 * the resolved auth server OR surfaces a settings error. Both
 * outcomes need pinning so the AUTH_URL safety gate and the
 * scoped `allowed_redirect_hosts` filter don't regress silently.
 *
 * The handler calls `exit` after `wp_safe_redirect`; like
 * `Test_Admin_Handle`, we catch `WPDieException` to keep the
 * PHPUnit run alive.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\Sanitize;
use WP_UnitTestCase;
use WPDieException;

/**
 * Sanitize-handle tests.
 */
class Test_Sanitize_Handle extends WP_UnitTestCase {

	/**
	 * Filters registered during a test that must be removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $tracked_filters = array();

	/**
	 * Reset settings-error global before each test.
	 *
	 * `$wp_settings_errors` is a PHP global that persists across
	 * tests in the same process. Earlier suites (notably
	 * `Test_Admin_Handle`) write notices keyed on the same
	 * `'atmosphere'` setting slug we read here, so without an
	 * explicit reset our `$errors[0]['code']` assertion would pick
	 * up a leaked notice rather than the one this test produced.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Drop tracked filters and settings errors between tests.
	 */
	public function tear_down(): void {
		foreach ( $this->tracked_filters as $entry ) {
			\remove_filter( $entry[0], $entry[1], $entry[2] );
		}
		$this->tracked_filters = array();
		\remove_all_filters( 'pre_http_request' );

		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		\wp_set_current_user( 0 );
		\delete_transient( 'atmosphere_oauth_state' );
		\delete_transient( 'atmosphere_oauth_verifier' );
		\delete_transient( 'atmosphere_oauth_dpop_jwk' );
		\delete_transient( 'atmosphere_oauth_resolved' );

		parent::tear_down();
	}

	/**
	 * Become an admin so `current_user_can('manage_options')` passes.
	 */
	private function become_admin(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin_id );
	}

	/**
	 * Register a filter and remember it for tearDown removal.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function add_filter_tracked( string $hook, callable $callback, int $priority = 10 ): void {
		\add_filter( $hook, $callback, $priority, PHP_INT_MAX );
		$this->tracked_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Stub the next HTTP response with a fixed body for a URL substring.
	 *
	 * @param string $url_match Substring to match against the request URL.
	 * @param int    $status    HTTP status code.
	 * @param mixed  $body      Response body (array → JSON encoded).
	 */
	private function stub_response( string $url_match, int $status, $body ): void {
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( $url_match, $status, $body ) {
				if ( false !== \strpos( $url, $url_match ) ) {
					return array(
						'response' => array( 'code' => $status ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => \is_array( $body ) ? (string) \wp_json_encode( $body ) : (string) $body,
					);
				}

				return $response;
			},
			10,
			3
		);
	}

	/**
	 * Stub the resolver chain so `Client::authorize()` can complete.
	 *
	 * @param string $auth_endpoint Authorization endpoint URL to advertise.
	 */
	private function stub_resolver_chain( string $auth_endpoint ): void {
		$this->stub_response( '/.well-known/atproto-did', 200, 'did:plc:test' );
		$this->stub_response(
			'plc.directory/did:plc:test',
			200,
			array(
				'id'      => 'did:plc:test',
				'service' => array(
					array(
						'id'              => '#atproto_pds',
						'type'            => 'AtprotoPersonalDataServer',
						'serviceEndpoint' => 'https://pds.example.com',
					),
				),
			)
		);
		$this->stub_response(
			'oauth-protected-resource',
			200,
			array( 'authorization_servers' => array( 'https://auth.example.com' ) )
		);
		$this->stub_response(
			'oauth-authorization-server',
			200,
			array(
				'token_endpoint'         => 'https://auth.example.com/oauth/token',
				'authorization_endpoint' => $auth_endpoint,
			)
		);
	}

	/**
	 * A non-HTTPS auth URL (poisoned auth-server metadata) is caught
	 * by `sanitize_handle()`'s defence-in-depth scheme check — surfaces
	 * a settings error and never reaches `wp_safe_redirect`.
	 */
	public function test_http_auth_url_surfaces_error_and_does_not_redirect(): void {
		$this->become_admin();
		$this->stub_resolver_chain( 'http://auth.example.com/oauth/authorize' );

		$redirected = false;
		$this->add_filter_tracked(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = $location;
				throw new WPDieException( 'unexpected_redirect' );
			}
		);

		Sanitize::handle( 'alice.bsky-test-handle.io' );

		$this->assertFalse( $redirected, 'sanitize_handle must not redirect on a non-HTTPS auth URL.' );

		$errors = \get_settings_errors( 'atmosphere' );
		$this->assertNotEmpty( $errors, 'sanitize_handle must add a settings error on a non-HTTPS auth URL.' );
		$this->assertSame( 'auth_failed', $errors[0]['code'] );
	}

	/**
	 * `Client::authorize()` returning `WP_Error` (resolver failure, etc.)
	 * surfaces a settings error and never redirects.
	 */
	public function test_wp_error_from_authorize_surfaces_error_and_does_not_redirect(): void {
		$this->become_admin();

		// Stub the well-known fetch to return a 404 — resolver will return
		// `atmosphere_resolve_handle` WP_Error.
		$this->stub_response( '/.well-known/atproto-did', 404, '' );

		$redirected = false;
		$this->add_filter_tracked(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = $location;
				throw new WPDieException( 'unexpected_redirect' );
			}
		);

		Sanitize::handle( 'alice.bsky-test-handle.io' );

		$this->assertFalse( $redirected, 'sanitize_handle must not redirect when authorize() returns WP_Error.' );

		$errors = \get_settings_errors( 'atmosphere' );
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'auth_failed', $errors[0]['code'] );
	}

	/**
	 * A valid HTTPS auth URL produces a redirect AND the auth-server
	 * host appears in `allowed_redirect_hosts` for the call. Pins the
	 * scoped-filter invariant so it can't regress to either (a) no
	 * redirect (filter not added) or (b) leaking the filter beyond the
	 * redirect call.
	 */
	public function test_valid_auth_url_redirects_with_scoped_allowed_redirect_hosts(): void {
		$this->become_admin();
		$this->stub_resolver_chain( 'https://auth.example.com/oauth/authorize' );

		$captured_target = null;
		$captured_hosts  = null;
		$this->add_filter_tracked(
			'wp_redirect',
			static function ( $location ) use ( &$captured_target, &$captured_hosts ) {
				$captured_target = $location;
				$captured_hosts  = \apply_filters( 'allowed_redirect_hosts', array(), '' );
				throw new WPDieException( 'redirect_intercepted' );
			}
		);

		try {
			Sanitize::handle( 'alice.bsky-test-handle.io' );
			$this->fail( 'Expected redirect to be intercepted.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'redirect_intercepted', $e->getMessage() );
		}

		$this->assertIsString( $captured_target );
		$this->assertStringStartsWith( 'https://auth.example.com/oauth/authorize', $captured_target );

		$this->assertIsArray( $captured_hosts );
		$this->assertContains(
			'auth.example.com',
			$captured_hosts,
			'auth-server host must be in allowed_redirect_hosts during wp_safe_redirect.'
		);
	}
}
