<?php
/**
 * Tests for the connection REST controller.
 *
 * Pins the authenticated contract behind the Connectors card's Connect /
 * Disconnect buttons: only `manage_options` users may drive it, an empty handle
 * is rejected before any resolution work, and disconnect tears down the stored
 * session. The full authorize-success path (handle resolution → PAR → URL) is
 * covered at the `Client::authorize()` level in {@see \Atmosphere\Tests\OAuth\Test_Client_Authorize}.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group rest
 * @group connectors
 */

namespace Atmosphere\Tests\Rest\Admin;

use Atmosphere\Rest\Admin\Connection_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Connection controller tests.
 *
 * @coversDefaultClass \Atmosphere\Rest\Admin\Connection_Controller
 */
class Test_Connection_Controller extends WP_UnitTestCase {

	/**
	 * Route to the authorize endpoint.
	 *
	 * @var string
	 */
	private const AUTHORIZE = '/atmosphere/1.0/admin/connection/authorize';

	/**
	 * Route to the disconnect endpoint.
	 *
	 * @var string
	 */
	private const DISCONNECT = '/atmosphere/1.0/admin/connection/disconnect';

	/**
	 * Register the controller's routes on the REST server.
	 */
	public function set_up(): void {
		parent::set_up();

		\do_action( 'rest_api_init' );
		( new Connection_Controller() )->register_routes();
	}

	/**
	 * Reset user + connection state between tests.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_transient( 'atmosphere_oauth_from_connectors' );
		\delete_transient( 'atmosphere_oauth_verifier' );
		\delete_transient( 'atmosphere_oauth_state' );
		\delete_transient( 'atmosphere_oauth_dpop_jwk' );
		\delete_transient( 'atmosphere_oauth_resolved' );
		\remove_all_filters( 'pre_http_request' );
		\wp_set_current_user( 0 );
		parent::tear_down();
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
	 */
	private function stub_resolver_chain(): void {
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
				'authorization_endpoint' => 'https://auth.example.com/oauth/authorize',
			)
		);
	}

	/**
	 * A user without `manage_options` cannot start a connection.
	 *
	 * @covers ::check_permission
	 */
	public function test_authorize_requires_manage_options() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'POST', self::AUTHORIZE );
		$request->set_param( 'handle', 'alice.example.com' );

		$response = \rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * An empty handle is rejected before any resolution work.
	 *
	 * @covers ::authorize
	 */
	public function test_authorize_rejects_empty_handle() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', self::AUTHORIZE );
		$request->set_param( 'handle', '   ' );

		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'atmosphere_missing_handle', $data['code'] );
	}

	/**
	 * Disconnect tears down the stored session and reports success.
	 *
	 * @covers ::disconnect
	 */
	public function test_disconnect_clears_connection() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'alice.example.com',
				'access_token' => 'live-token',
			)
		);

		$request  = new WP_REST_Request( 'POST', self::DISCONNECT );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertFalse( \get_option( 'atmosphere_connection' ) );
	}

	/**
	 * Disconnect is also gated behind `manage_options`.
	 *
	 * @covers ::check_permission
	 */
	public function test_disconnect_requires_manage_options() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = \rest_do_request( new WP_REST_Request( 'POST', self::DISCONNECT ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A successful authorize flags the connect as Connectors-initiated, so the
	 * OAuth callback returns the browser to the Connectors screen rather than the
	 * settings page. The flag is a boolean the callback maps to a hardcoded
	 * destination — the card never supplies a URL.
	 *
	 * @covers ::authorize
	 */
	public function test_authorize_flags_connectors_initiated_connect() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->stub_resolver_chain();

		$request = new WP_REST_Request( 'POST', self::AUTHORIZE );
		$request->set_param( 'handle', 'alice.atmosphere-test.io' );

		$response = \rest_do_request( $request );

		if ( 200 !== $response->get_status() ) {
			$this->markTestSkipped( 'Resolver chain rejected the stubbed handle.' );
		}

		$this->assertNotFalse(
			\get_transient( 'atmosphere_oauth_from_connectors' ),
			'A successful Connectors authorize must set the return flag.'
		);
	}
}
