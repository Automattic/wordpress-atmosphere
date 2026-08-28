<?php
/**
 * Tests for the Site Health async-test controller.
 *
 * The route exists so the reachability test can run asynchronously. It
 * lives in core's namespace, must be gated the way core gates its own
 * tests, and must hand back the test result unchanged.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group rest
 */

namespace Atmosphere\Tests\Rest\Admin;

use Atmosphere\OAuth\Client;
use Atmosphere\Rest\Admin\Health_Check_Controller;
use WP_REST_Request;

/**
 * Health check controller tests.
 */
class Test_Health_Check_Controller extends \WP_UnitTestCase {

	/**
	 * Boot a REST server with our routes.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		\do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Drop the server and user.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		\wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The route path the Site Health screen will call.
	 *
	 * @return string
	 */
	private function route(): string {
		return '/' . Health_Check_Controller::ROUTE_NAMESPACE . Health_Check_Controller::CLIENT_METADATA_ROUTE;
	}

	/**
	 * The route is registered in core's Site Health namespace.
	 */
	public function test_route_is_registered_in_the_core_namespace() {
		$this->assertArrayHasKey( $this->route(), \rest_get_server()->get_routes() );
	}

	/**
	 * Anonymous and low-privilege users are refused, as for core's tests.
	 */
	public function test_route_requires_site_health_capability() {
		$this->assertSame( 401, \rest_do_request( new WP_REST_Request( 'GET', $this->route() ) )->get_status() );

		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, \rest_do_request( new WP_REST_Request( 'GET', $this->route() ) )->get_status() );
	}

	/**
	 * An administrator gets the test result, in the shape Site Health
	 * expects, with the loopback stubbed to succeed.
	 */
	public function test_route_returns_the_test_result() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\add_filter( 'pre_option_home', static fn () => 'https://example.org' );
		\add_filter( 'pre_option_siteurl', static fn () => 'https://example.org' );
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( Client::client_id() !== $url ) {
					return $pre;
				}

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => (string) \wp_json_encode( array( 'client_id' => Client::client_id() ) ),
				);
			},
			10,
			3
		);

		$response = \rest_do_request( new WP_REST_Request( 'GET', $this->route() ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'good', $data['status'] );
		$this->assertSame( 'atmosphere_test_client_metadata', $data['test'] );
		$this->assertArrayHasKey( 'description', $data );
	}
}
