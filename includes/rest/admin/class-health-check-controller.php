<?php
/**
 * Site Health async-test REST controller.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Rest\Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\WP_Admin\Health_Check;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves the plugin's asynchronous Site Health tests.
 *
 * Lives in core's `wp-site-health/v1` namespace on purpose. The test it
 * serves exists to catch plugins that restrict the REST API to an allow
 * list, and those lists are keyed by namespace with core's own namespaces
 * allowed by default. A route under `atmosphere/1.0` would be blocked by
 * the very configuration the test is meant to report, and Site Health
 * would show a test that could not run instead of a result. Core's
 * namespace gets through, and the test's own unauthenticated fetch of
 * `atmosphere/v1/client-metadata` is then what fails, as it should.
 *
 * @since unreleased
 */
class Health_Check_Controller extends \WP_REST_Controller {

	/**
	 * Core's Site Health namespace, shared on purpose (see the class docblock).
	 *
	 * @var string
	 */
	public const ROUTE_NAMESPACE = 'wp-site-health/v1';

	/**
	 * Route for the client-metadata reachability test.
	 *
	 * @var string
	 */
	public const CLIENT_METADATA_ROUTE = '/tests/atmosphere-client-metadata';

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		\register_rest_route(
			self::ROUTE_NAMESPACE,
			self::CLIENT_METADATA_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'test_client_metadata' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * The same capability core requires for its own Site Health tests.
	 *
	 * @return bool
	 */
	public function permissions_check(): bool {
		return \current_user_can( 'view_site_health_checks' );
	}

	/**
	 * Run the client-metadata reachability test.
	 *
	 * @return WP_REST_Response The Site Health test result.
	 */
	public function test_client_metadata(): WP_REST_Response {
		return new WP_REST_Response( Health_Check::test_client_metadata() );
	}
}
