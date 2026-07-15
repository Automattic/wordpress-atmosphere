<?php
/**
 * Connection REST controller.
 *
 * Backs the "Connect" / "Disconnect" buttons on the core Settings → Connectors
 * card (see {@see \Atmosphere\Connectors}). The card is a script module and
 * cannot POST to the WordPress Settings API the way the plugin's own settings
 * page does, so it drives the OAuth flow through these authenticated routes
 * instead:
 *
 *  - `authorize` resolves the submitted handle and returns the AT Protocol
 *     authorization URL for the browser to navigate to.
 *  - `disconnect` tears down the stored session.
 *
 * Lives in the admin REST namespace (`atmosphere/1.0`), mirroring
 * {@see \Atmosphere\Rest\Admin\Pre_Publish_Controller}: it is an
 * authenticated, admin-only surface, not a public route.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Rest\Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Client;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Connection controller.
 */
class Connection_Controller extends \WP_REST_Controller {

	/**
	 * The REST namespace for this controller's routes.
	 *
	 * Reuses the existing admin namespace shared by the plugin's other
	 * authenticated controllers (e.g. {@see Pre_Publish_Controller}); the
	 * public OAuth routes live under `atmosphere/v1`. Access is gated by this
	 * controller's own `permission_callback`, not by the namespace.
	 *
	 * @var string
	 */
	public const ROUTE_NAMESPACE = 'atmosphere/1.0';

	/**
	 * The base of this controller's routes.
	 *
	 * @var string
	 */
	public const ROUTE_BASE = 'admin/connection';

	/**
	 * The namespace of this controller's routes.
	 *
	 * @var string
	 */
	protected $namespace = self::ROUTE_NAMESPACE;

	/**
	 * The base of this controller's routes.
	 *
	 * @var string
	 */
	protected $rest_base = self::ROUTE_BASE;

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/authorize',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'authorize' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'show_in_index'       => false,
					'args'                => array(
						'handle' => array(
							'description'       => \__( 'The AT Protocol handle to connect, e.g. alice.bsky.social.', 'atmosphere' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => '\sanitize_text_field',
						),
					),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/disconnect',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'disconnect' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'show_in_index'       => false,
				),
			)
		);
	}

	/**
	 * Permission check shared by every route.
	 *
	 * Same capability the settings-page connect/disconnect handlers require;
	 * connecting or tearing down the site's AT Protocol session is a
	 * site-configuration action.
	 *
	 * @return bool|WP_Error True if allowed, error otherwise.
	 */
	public function check_permission() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'atmosphere_forbidden',
				\__( 'You are not allowed to manage the AT Protocol connection.', 'atmosphere' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Start the OAuth flow and return the authorization URL.
	 *
	 * The handle is normalized the same way the settings-page path does (a
	 * leading `@` stripped) before handing off to {@see Client::authorize()},
	 * which resolves the handle, mints the PKCE/DPoP material, and returns the
	 * URL to redirect the browser to. The card navigates there client-side.
	 *
	 * This route is only ever reached from the Connectors card, so on success it
	 * flags the flow as Connectors-initiated: the OAuth callback reads that flag
	 * and sends the browser back to the Connectors screen instead of the settings
	 * page. The flag is a boolean, not a URL — the callback hardcodes the
	 * destination, so nothing off the wire can steer the redirect. (The settings
	 * page uses its own admin-post handler, not this route, and returns to itself
	 * after clearing the flag.)
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error `{ url }` on success, error otherwise.
	 */
	public function authorize( WP_REST_Request $request ) {
		$handle = \ltrim( (string) $request['handle'], '@' );

		if ( '' === $handle ) {
			return new WP_Error(
				'atmosphere_missing_handle',
				\__( 'Enter your AT Protocol handle to connect.', 'atmosphere' ),
				array( 'status' => 400 )
			);
		}

		$url = Client::authorize( $handle );

		if ( \is_wp_error( $url ) ) {
			$url->add_data( array( 'status' => 400 ) );
			return $url;
		}

		\set_transient( 'atmosphere_oauth_from_connectors', 1, HOUR_IN_SECONDS );

		return new WP_REST_Response( array( 'url' => $url ) );
	}

	/**
	 * Tear down the stored AT Protocol session.
	 *
	 * @return WP_REST_Response `{ success: true }`.
	 */
	public function disconnect() {
		Client::disconnect();

		return new WP_REST_Response( array( 'success' => true ) );
	}
}
