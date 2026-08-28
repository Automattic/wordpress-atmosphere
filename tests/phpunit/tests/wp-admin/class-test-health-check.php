<?php
/**
 * Tests for the Site Health integration.
 *
 * The connection test must map each connection state to the right
 * severity and copy — in particular, a key-change failure must guide
 * the user toward the dedicated encryption key constant so the next
 * salt rotation doesn't break the connection again.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\Encryption;
use Atmosphere\WP_Admin\Health_Check;

/**
 * Health check tests.
 */
class Test_Health_Check extends \WP_UnitTestCase {

	/**
	 * Clean options after each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( Client::DISCONNECTED_OPTION );

		parent::tear_down();
	}

	/**
	 * Seed identity + a live connection.
	 *
	 * @param array $overrides Connection fields to override.
	 */
	private function seed_connection( array $overrides = array() ): void {
		\update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://pds.example.com',
			),
			true
		);

		\update_option(
			'atmosphere_connection',
			\array_merge(
				array(
					'did'          => 'did:plc:test',
					'handle'       => 'example.com',
					'pds_endpoint' => 'https://pds.example.com',
					'access_token' => Encryption::encrypt( 'access-token' ),
					'needs_reauth' => false,
				),
				$overrides
			),
			false
		);
	}

	/**
	 * The test is registered as a direct (non-async) Site Health test.
	 */
	public function test_registers_direct_test() {
		$tests = Health_Check::add_tests(
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);

		$this->assertArrayHasKey( 'atmosphere_test_connection', $tests['direct'] );
		$this->assertArrayNotHasKey( 'atmosphere_test_client_metadata', $tests['direct'], 'A test that makes a request must not block the screen.' );

		$async = $tests['async']['atmosphere_test_client_metadata'];
		$this->assertArrayNotHasKey( 'has_rest', $async, 'Driven over admin-ajax, so a REST restriction cannot stop the test from running.' );
		$this->assertSame( Health_Check::REACHABILITY_TEST, $async['test'] );
		$this->assertSame( 1, \substr_count( $async['test'], '_' ), 'Core replaces only the first underscore when building the ajax action.' );
		$this->assertSame( 'health-check-' . \str_replace( '_', '-', $async['test'] ), Health_Check::REACHABILITY_ACTION );
		$this->assertNotFalse( \has_action( 'wp_ajax_' . Health_Check::REACHABILITY_ACTION, array( Health_Check::class, 'ajax_client_metadata' ) ) );
		$this->assertIsCallable( $async['async_direct_test'] );
		$this->assertTrue( $async['skip_cron'], 'The cron run discards the description, so the loopback is skipped there.' );
	}

	/**
	 * A live connection reports "good".
	 */
	public function test_connected_site_is_good() {
		$this->seed_connection();

		$result = Health_Check::test_connection();

		$this->assertSame( 'good', $result['status'] );
	}

	/**
	 * A never-connected site is a recommendation, not a critical issue.
	 */
	public function test_never_connected_site_is_recommended() {
		$result = Health_Check::test_connection();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'not connected', $result['label'] );
	}

	/**
	 * An operator-initiated disconnect is a chosen state — recommended,
	 * with copy that doesn't claim a failure.
	 */
	public function test_operator_disconnect_is_recommended() {
		$this->seed_connection();
		\delete_option( 'atmosphere_connection' );
		\update_option( Client::DISCONNECTED_OPTION, \time(), false );

		$result = Health_Check::test_connection();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'disconnected', $result['label'] );
	}

	/**
	 * A key-change reauth is critical and guides the user to the
	 * dedicated encryption key constant (the constant is not defined in
	 * the test environment, so the "add it" branch renders).
	 */
	public function test_key_changed_is_critical_and_recommends_the_constant() {
		$this->seed_connection(
			array(
				'access_token'  => '',
				'needs_reauth'  => true,
				'reauth_reason' => 'key_changed',
			)
		);

		$result = Health_Check::test_connection();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'security keys have changed', $result['description'] );
		$this->assertStringContainsString( 'ATMOSPHERE_ENCRYPTION_KEY', $result['description'] );
		$this->assertStringContainsString( 'define(', $result['description'] );
		$this->assertStringContainsString( 'options-general.php?page=atmosphere', $result['actions'] );

		/*
		 * The snippet must contain a freshly generated key, not a
		 * static placeholder — two renders differ only in the key.
		 */
		$this->assertNotSame( $result['description'], Health_Check::test_connection()['description'] );
	}

	/**
	 * A generic decrypt failure is critical but must not blame the
	 * security keys (the fingerprint matched — the data is corrupt).
	 */
	public function test_decrypt_failed_is_critical_without_key_guidance() {
		$this->seed_connection(
			array(
				'access_token'  => '',
				'needs_reauth'  => true,
				'reauth_reason' => 'decrypt_failed',
			)
		);

		$result = Health_Check::test_connection();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringNotContainsString( 'ATMOSPHERE_ENCRYPTION_KEY', $result['description'] );
		$this->assertStringContainsString( 'no longer read', $result['description'] );
	}

	/**
	 * A legacy needs_reauth row without a reason marker falls back to
	 * the session-expired copy.
	 */
	public function test_expired_session_is_critical_with_expiry_copy() {
		$this->seed_connection(
			array(
				'access_token' => '',
				'needs_reauth' => true,
			)
		);

		$result = Health_Check::test_connection();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'session has expired', $result['description'] );
	}

	/**
	 * The debug panel reports the connection status and the encryption
	 * key source (the constant is undefined in the test environment, so
	 * the source is the WordPress security keys).
	 */
	public function test_debug_information_reports_status_and_key_source() {
		$this->seed_connection(
			array(
				'access_token'  => '',
				'needs_reauth'  => true,
				'reauth_reason' => 'key_changed',
			)
		);

		$info = Health_Check::debug_information( array() );

		$this->assertArrayHasKey( 'atmosphere', $info );

		$fields = $info['atmosphere']['fields'];
		$this->assertStringContainsString( 'security keys changed', $fields['connection_status']['value'] );
		$this->assertSame( 'example.com', $fields['handle']['value'] );
		$this->assertStringContainsString( 'AUTH_KEY', $fields['encryption_key']['value'] );
	}

	/**
	 * On a healthy connection the debug panel reports "Connected" plus
	 * the publishing configuration (auto-publish state, post types).
	 */
	public function test_debug_information_reports_connected_state_and_publishing_config() {
		$this->seed_connection();
		\update_option( 'atmosphere_support_post_types', array( 'post' ) );

		$info = Health_Check::debug_information( array() );

		$fields = $info['atmosphere']['fields'];
		$this->assertSame( 'Connected', $fields['connection_status']['value'] );
		$this->assertSame( 'Enabled', $fields['auto_publish']['value'] );
		$this->assertStringContainsString( 'post', $fields['post_types']['value'] );

		\delete_option( 'atmosphere_support_post_types' );
	}

	/**
	 * Serve the test site over https, so the reachability check gets past
	 * its scheme guard and actually makes the loopback.
	 */
	private function use_https_site(): void {
		\add_filter( 'pre_option_home', static fn () => 'https://example.org' );
		\add_filter( 'pre_option_siteurl', static fn () => 'https://example.org' );
	}

	/**
	 * Stub every loopback to the client metadata URL with one response.
	 *
	 * @param mixed $response Array response or WP_Error to return.
	 */
	private function stub_loopback( $response ): void {
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $response ) {
				return Client::client_id() === $url ? $response : $pre;
			},
			10,
			3
		);
	}

	/**
	 * A well-formed metadata document that names this site.
	 *
	 * @return array HTTP API response array.
	 */
	private function good_metadata_response(): array {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => (string) \wp_json_encode( array( 'client_id' => Client::client_id() ) ),
		);
	}

	/**
	 * A reachable metadata document passes.
	 */
	public function test_client_metadata_passes_when_reachable() {
		$this->use_https_site();
		$this->stub_loopback( $this->good_metadata_response() );

		$this->assertSame( 'good', Health_Check::test_client_metadata()['status'] );
	}

	/**
	 * A REST block returns an error page, which is critical and shows
	 * the status so the cause is recognisable.
	 */
	public function test_client_metadata_is_critical_on_error_status() {
		$this->use_https_site();
		$this->stub_loopback(
			array(
				'response' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
				'body'     => '<html><body><h1>REST API disabled</h1></body></html>',
			)
		);

		$result = Health_Check::test_client_metadata();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( '(403) Forbidden', $result['description'] );
		$this->assertStringContainsString( 'REST API disabled', $result['description'], 'The body excerpt names the error page.' );
		$this->assertStringNotContainsString( '<h1>', $result['description'], 'Body markup is stripped, not rendered.' );
	}

	/**
	 * A transport failure is critical and carries the error.
	 */
	public function test_client_metadata_is_critical_on_transport_error() {
		$this->use_https_site();
		$this->stub_loopback( new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );

		$result = Health_Check::test_client_metadata();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'Failed to connect', $result['description'] );
	}

	/**
	 * A 200 that is not our document (a caching plugin, a wrong site
	 * behind the URL) is still a failure.
	 */
	public function test_client_metadata_is_critical_when_document_names_another_site() {
		$this->use_https_site();
		$this->stub_loopback(
			array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => (string) \wp_json_encode( array( 'client_id' => 'https://other.example/metadata' ) ),
			)
		);

		$this->assertSame( 'critical', Health_Check::test_client_metadata()['status'] );
	}

	/**
	 * The loopback must look like the auth server's request: no cookies
	 * and no credentials, or a site behind HTTP auth would pass here and
	 * still fail to connect.
	 */
	public function test_client_metadata_loopback_sends_no_credentials() {
		$this->use_https_site();
		$captured = array();
		\add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				if ( Client::client_id() !== $url ) {
					return $pre;
				}
				$captured = $args;

				return $this->good_metadata_response();
			},
			10,
			3
		);

		Health_Check::test_client_metadata();

		$this->assertNotEmpty( $captured, 'The loopback must have been made.' );
		$this->assertEmpty( $captured['cookies'] ?? array() );
		$this->assertArrayNotHasKey( 'Authorization', $captured['headers'] ?? array() );
		$this->assertTrue( $captured['sslverify'], 'The certificate is validated, as the auth server would.' );
		$this->assertSame( 'no-cache', $captured['headers']['Cache-Control'] ?? null, 'A cached answer could hide a fresh block.' );
		$this->assertSame( 8 * KB_IN_BYTES, $captured['limit_response_size'] ?? null );
	}

	/**
	 * A plain-http site cannot be reached by Bluesky at all, and must be
	 * told that rather than shown a failed request against an https URL
	 * that was never going to answer. No loopback is made.
	 */
	public function test_client_metadata_explains_missing_https_without_a_loopback() {
		$called = false;
		\add_filter(
			'pre_http_request',
			static function ( $pre ) use ( &$called ) {
				$called = true;

				return $pre;
			}
		);

		$result = Health_Check::test_client_metadata();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'HTTPS', $result['description'] );
		$this->assertStringNotContainsString( 'security plugin', $result['description'] );
		$this->assertFalse( $called, 'No request is made when the scheme already rules it out.' );
	}

	/**
	 * Stub the loopback so that only the unverified retry succeeds.
	 *
	 * @param int $calls Receives the number of loopbacks made.
	 */
	private function stub_certificate_failure( int &$calls ): void {
		$good = $this->good_metadata_response();

		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $good, &$calls ) {
				if ( Client::client_id() !== $url ) {
					return $pre;
				}
				++$calls;

				return $args['sslverify']
					? new \WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: self-signed certificate' )
					: $good;
			},
			10,
			3
		);
	}

	/**
	 * A document that is reachable but whose certificate cannot be
	 * validated from the server is recommended, not critical, and says
	 * what the validation error was. Exactly two requests are made.
	 */
	public function test_client_metadata_recommends_when_only_the_certificate_fails() {
		$this->use_https_site();
		$calls = 0;
		$this->stub_certificate_failure( $calls );

		$result = Health_Check::test_client_metadata();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'certificate', $result['label'] );
		$this->assertStringContainsString( 'self-signed certificate', $result['description'] );
		$this->assertSame( 2, $calls, 'The verified fetch, then one unverified retry.' );
	}

	/**
	 * The retry is only for transport errors. An error status is the same
	 * with or without validation, so it is reported at once.
	 */
	public function test_client_metadata_does_not_retry_an_error_status() {
		$this->use_https_site();
		$calls = 0;
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$calls ) {
				if ( Client::client_id() !== $url ) {
					return $pre;
				}
				++$calls;

				return array(
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
					'body'     => '',
				);
			},
			10,
			3
		);

		$this->assertSame( 'critical', Health_Check::test_client_metadata()['status'] );
		$this->assertSame( 1, $calls );
	}

	/**
	 * A reason phrase from a misbehaving proxy is capped like the body.
	 */
	public function test_client_metadata_caps_a_runaway_reason_phrase() {
		$this->use_https_site();
		$this->stub_loopback(
			array(
				'response' => array(
					'code'    => 503,
					'message' => \str_repeat( 'x', 2000 ),
				),
				'body'     => '',
			)
		);

		$result = Health_Check::test_client_metadata();

		$this->assertStringNotContainsString( \str_repeat( 'x', 300 ), $result['description'] );
	}

	/**
	 * Behind a TLS-terminating proxy the admin request itself arrives
	 * over http (`is_ssl()` is false) while the site is configured as
	 * https. The guard reads the configured scheme, not the request, so
	 * the loopback is still made.
	 */
	public function test_client_metadata_checks_the_configured_scheme_not_the_request() {
		$this->use_https_site();
		unset( $_SERVER['HTTPS'] );
		$this->assertFalse( \is_ssl(), 'Precondition: the request is plain http.' );

		$calls = 0;
		$good  = $this->good_metadata_response();
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $good, &$calls ) {
				if ( Client::client_id() !== $url ) {
					return $pre;
				}
				++$calls;

				return $good;
			},
			10,
			3
		);

		$this->assertSame( 'good', Health_Check::test_client_metadata()['status'] );
		$this->assertSame( 1, $calls, 'The configured https scheme lets the check run.' );
	}

	/**
	 * Run the ajax handler and return what it printed.
	 *
	 * Outside an ajax request `wp_send_json()` ends in a bare `die`, which
	 * would take PHPUnit down with it and exit 0, so the run would look
	 * green with the rest of the suite never executed. Marking the
	 * request as ajax routes it through `wp_die()` instead, and the ajax
	 * die handler is made to throw, the same way the base test case
	 * already treats the non-ajax one. The buffer then holds exactly the
	 * response.
	 *
	 * @return string
	 */
	private function run_ajax_handler(): string {
		$die_handler = static function () {
			return static function ( $message ) {
				throw new \WPDieException( \esc_html( (string) $message ) );
			};
		};

		\add_filter( 'wp_doing_ajax', '__return_true' );
		\add_filter( 'wp_die_ajax_handler', $die_handler );

		/*
		 * The base test case restores all hooks in tear_down(), so these
		 * cannot leak into another test. They are still removed here so
		 * the rest of the calling test runs in a normal, non-ajax context.
		 */
		\ob_start();
		try {
			Health_Check::ajax_client_metadata();
			$this->fail( 'Expected the handler to end in wp_die().' );
		} catch ( \WPDieException $e ) {
			return (string) \ob_get_clean();
		} finally {
			if ( \ob_get_level() > 0 && '' !== (string) \ob_get_contents() ) {
				\ob_end_clean();
			}
			\remove_filter( 'wp_doing_ajax', '__return_true' );
			\remove_filter( 'wp_die_ajax_handler', $die_handler );
		}
	}

	/**
	 * A valid request from a user who may view Site Health gets the test
	 * result wrapped the way the screen reads it.
	 */
	public function test_ajax_handler_returns_the_result_for_site_health_viewers() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = \wp_create_nonce( 'health-check-site-status' );
		$this->use_https_site();
		$this->stub_loopback( $this->good_metadata_response() );

		$json = \json_decode( $this->run_ajax_handler(), true );

		$this->assertTrue( $json['success'] );
		$this->assertSame( 'good', $json['data']['status'] );
		$this->assertSame( 'atmosphere_test_client_metadata', $json['data']['test'] );
	}

	/**
	 * A missing or wrong nonce stops the request before any loopback.
	 */
	public function test_ajax_handler_requires_the_site_health_nonce() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'wrong';
		$called               = false;
		\add_filter(
			'pre_http_request',
			static function ( $pre ) use ( &$called ) {
				$called = true;

				return $pre;
			}
		);

		$output = $this->run_ajax_handler();

		$this->assertStringNotContainsString( '"success":true', $output );
		$this->assertFalse( $called, 'No loopback without a valid nonce.' );
	}

	/**
	 * A valid nonce is not enough: the user must be allowed to view Site
	 * Health, as for core's own tests.
	 */
	public function test_ajax_handler_refuses_users_without_the_capability() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = \wp_create_nonce( 'health-check-site-status' );

		$json = \json_decode( $this->run_ajax_handler(), true );

		$this->assertFalse( $json['success'] );
	}
}
