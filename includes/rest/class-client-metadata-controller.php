<?php
/**
 * OAuth client-metadata REST controller.
 *
 * Serves the AT Protocol OAuth client metadata document. The endpoint URL
 * itself IS the `client_id` per the spec, so the route is a stable public
 * contract and must stay on the `atmosphere/v1` namespace.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Rest;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Client;
use WP_REST_Response;
use WP_REST_Server;
use function Atmosphere\debug_log;
use function Atmosphere\sanitize_text;

/**
 * Client-metadata controller.
 */
class Client_Metadata_Controller extends \WP_REST_Controller {

	/**
	 * REST namespace for this controller's route.
	 *
	 * The metadata URL is the OAuth `client_id`, an external contract, so
	 * this stays on the original public `atmosphere/v1` namespace — it is
	 * deliberately *not* moved to the admin `atmosphere/1.0` namespace.
	 *
	 * @var string
	 */
	public const ROUTE_NAMESPACE = 'atmosphere/v1';

	/**
	 * The base of this controller's route.
	 *
	 * @var string
	 */
	public const ROUTE_BASE = 'client-metadata';

	/**
	 * The namespace of this controller's route.
	 *
	 * @var string
	 */
	protected $namespace = self::ROUTE_NAMESPACE;

	/**
	 * The base of this controller's route.
	 *
	 * @var string
	 */
	protected $rest_base = self::ROUTE_BASE;

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_metadata' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Serve the OAuth client metadata JSON.
	 *
	 * This endpoint URL IS the client_id per AT Protocol OAuth spec.
	 *
	 * @return WP_REST_Response
	 */
	public function get_metadata(): WP_REST_Response {
		$metadata = array(
			'client_id'                  => Client::client_id(),
			'client_name'                => sanitize_text( \get_bloginfo( 'name' ) ) . ' (ATmosphere)',
			'client_uri'                 => \home_url( '/' ),
			'redirect_uris'              => array( Client::redirect_uri() ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',

			/*
			 * MUST match the scope string requested by
			 * Client::authorize(). The auth server validates the
			 * request scope against the metadata; a drift here
			 * silently downgrades to the smaller of the two.
			 */
			'scope'                      => Client::scopes(),
			'dpop_bound_access_tokens'   => true,
			'application_type'           => 'web',
		);

		/**
		 * Filters the OAuth client metadata served at the REST endpoint.
		 *
		 * Filters MUST return an array containing:
		 *
		 *  - `client_id`: non-empty string (advertised as the OAuth client
		 *    identifier; should match `Client::client_id()`).
		 *  - `redirect_uris`: non-empty list of non-empty strings, where
		 *    every entry is rooted at this site's admin over HTTPS
		 *    (`admin_url('', 'https')` prefix). Off-site / empty /
		 *    non-string / HTTP-scheme / nested-array entries cause the
		 *    entire filter result to be rejected.
		 *
		 * Anything else falls back to the unfiltered metadata. The
		 * metadata endpoint is public and the document advertises
		 * `token_endpoint_auth_method: 'none'` (public client), so an
		 * attacker-supplied `redirect_uris` entry would let them drive
		 * this site's `client_id` with their own redirect target. Gate
		 * entries individually, matching the validation
		 * {@see \Atmosphere\OAuth\Client::redirect_uri()} applies to
		 * the inbound `atmosphere_oauth_redirect_uri` filter.
		 *
		 * @param array $metadata Client metadata.
		 */
		$filtered = \apply_filters( 'atmosphere_client_metadata', $metadata );

		if ( self::filter_is_valid( $filtered ) ) {
			$metadata = $filtered;
		} elseif ( $filtered !== $metadata ) {
			/*
			 * Surface only when the filter actually fired and returned
			 * something that failed validation — without this guard
			 * every page load on a site with no filter would trip the
			 * notice because the equality check above is the cheap
			 * shorthand for "nothing changed".
			 */
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_client_metadata must return an array with a non-empty string client_id and a redirect_uris list of admin URLs; falling back to the unfiltered metadata.', 'atmosphere' ),
				'1.0.0'
			);

			/*
			 * `_doing_it_wrong()` is silent in production. Also route the
			 * failure through debug_log() so operators can opt into the
			 * signal via the `atmosphere_debug_log` filter without enabling
			 * WP_DEBUG site-wide — an OAuth client_id served from a
			 * misbehaving filter is worth surfacing.
			 */
			debug_log( 'atmosphere_client_metadata filter returned an invalid value; using the unfiltered metadata.' );
		}

		$response = new WP_REST_Response( $metadata, 200 );

		// Cap intermediate-cache TTL well under the AT Protocol auth
		// server's own metadata cache (10 min in Bluesky's reference impl),
		// so that when the metadata document changes — e.g. a new OAuth
		// scope is added in an Atmosphere release — every layer between
		// us and the auth server has refreshed before the auth server
		// itself does its next refresh. Without an explicit header,
		// hosted environments like wp.com Atomic apply their own (much
		// longer) heuristic-based edge cache and can serve a stale scope
		// to every auth server that asks, surfacing as "Scope X is not
		// declared in the client metadata" on every authorization attempt.
		// 5 minutes gives the auth-server cache cycle plenty of room
		// without flat-out disabling cheap caching of an otherwise
		// rarely-changing document.
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * Validate the return value of the `atmosphere_client_metadata` filter.
	 *
	 * Container shape:
	 *
	 *  - Must be an array.
	 *  - `client_id` present, non-empty string.
	 *  - `redirect_uris` present, non-empty array (list of strings).
	 *
	 * Per-entry `redirect_uris` rules:
	 *
	 *  - Each entry is a non-empty string.
	 *  - Each entry begins with this site's HTTPS admin URL prefix
	 *    (`admin_url('', 'https')`), the same gate
	 *    {@see \Atmosphere\OAuth\Client::redirect_uri()} applies to
	 *    the inbound filter. An off-site / HTTP-scheme /
	 *    scheme-mismatched / empty entry disqualifies the entire
	 *    filter result.
	 *
	 * Returns true only if every check passes; the caller falls back
	 * to the unfiltered metadata on false.
	 *
	 * @param mixed $filtered Filter return value.
	 * @return bool
	 */
	private static function filter_is_valid( $filtered ): bool {
		if ( ! \is_array( $filtered ) ) {
			return false;
		}

		if ( ! isset( $filtered['client_id'] )
			|| ! \is_string( $filtered['client_id'] )
			|| '' === $filtered['client_id']
		) {
			return false;
		}

		if ( ! isset( $filtered['redirect_uris'] )
			|| ! \is_array( $filtered['redirect_uris'] )
			|| array() === $filtered['redirect_uris']
		) {
			return false;
		}

		/*
		 * Match the HTTPS scheme `Client::redirect_uri()` produces. The
		 * OAuth code is delivered to the browser via this URL and must
		 * not travel in cleartext, even if `admin_url()` itself defaults
		 * to HTTP on the host.
		 */
		$admin_prefix = \admin_url( '', 'https' );

		foreach ( $filtered['redirect_uris'] as $uri ) {
			if ( ! \is_string( $uri )
				|| '' === $uri
				|| ! \str_starts_with( $uri, 'https://' )
				|| ! \str_starts_with( $uri, $admin_prefix )
			) {
				return false;
			}
		}

		return true;
	}
}
