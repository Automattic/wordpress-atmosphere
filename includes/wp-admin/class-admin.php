<?php
/**
 * Admin settings page, meta box, and hook wiring.
 *
 * @package Atmosphere
 */

namespace Atmosphere\WP_Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Handle;
use Atmosphere\OAuth\Client;
use Atmosphere\Publisher;
use function Atmosphere\get_supported_post_types;
use function Atmosphere\is_connected;
use function Atmosphere\needs_reauth;

/**
 * Admin class.
 */
class Admin {

	/**
	 * Boot admin hooks.
	 *
	 * Settings API option registration and Settings page UI assembly
	 * live in dedicated `Atmosphere\Options` and
	 * `Atmosphere\WP_Admin\Settings_Fields` classes (mirroring the
	 * ActivityPub plugin's layout); both self-register their hooks
	 * from `Atmosphere::init()`. Admin only wires the admin-only
	 * surfaces here: menu page, OAuth callback handler, asset enqueue,
	 * reauth notice, admin-post handlers, and the post meta box.
	 */
	public static function register(): void {
		\add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		\add_action( 'admin_init', array( self::class, 'handle_oauth_callback' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		\add_action( 'admin_notices', array( self::class, 'maybe_render_reauth_notice' ) );

		\add_action( 'admin_post_atmosphere_disconnect', array( self::class, 'handle_disconnect' ) );
		\add_action( 'admin_post_atmosphere_set_domain_handle', array( self::class, 'handle_set_domain_handle' ) );

		// Meta box on syncable post types.
		\add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );

		/*
		 * REST route for client metadata is registered globally from
		 * Atmosphere::init() — rest_api_init does not fire on admin
		 * requests, so wiring it up here is redundant.
		 */
	}

	/**
	 * Register the settings page under Settings.
	 */
	public static function add_menu(): void {
		\add_options_page(
			\__( 'ATmosphere', 'atmosphere' ),
			\__( 'ATmosphere', 'atmosphere' ),
			'manage_options',
			'atmosphere',
			array( self::class, 'render_page' )
		);
	}


	/**
	 * Enqueue admin CSS/JS on our settings page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_atmosphere' !== $hook_suffix ) {
			return;
		}

		\wp_enqueue_style(
			'atmosphere-admin',
			ATMOSPHERE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ATMOSPHERE_VERSION
		);

		\wp_enqueue_script(
			'atmosphere-settings',
			ATMOSPHERE_PLUGIN_URL . 'assets/js/settings.js',
			array(),
			ATMOSPHERE_VERSION,
			true
		);

		\wp_localize_script(
			'atmosphere-settings',
			'atmosphere',
			array(
				'ajax_url'       => \admin_url( 'admin-ajax.php' ),
				'backfill_nonce' => \wp_create_nonce( 'atmosphere_backfill' ),
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		include ATMOSPHERE_PLUGIN_DIR . 'templates/settings-page.php';
	}

	/**
	 * Handle the OAuth callback (code + state in query string).
	 */
	public static function handle_oauth_callback(): void {
		$page = \filter_input( INPUT_GET, 'page' );
		if ( 'atmosphere' !== $page ) {
			return;
		}

		$code  = \filter_input( INPUT_GET, 'code' );
		$state = \filter_input( INPUT_GET, 'state' );

		if ( null === $code || null === $state ) {
			return;
		}

		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = Client::handle_callback(
			\sanitize_text_field( $code ),
			\sanitize_text_field( $state )
		);

		if ( \is_wp_error( $result ) ) {
			\add_settings_error( 'atmosphere', 'callback_failed', $result->get_error_message() );
			return;
		}

		// Auto-create publication on first connect.
		Publisher::sync_publication();

		\add_settings_error(
			'atmosphere',
			'connected',
			\__( 'Successfully connected to AT Protocol.', 'atmosphere' ),
			'success'
		);
		\set_transient( 'settings_errors', \get_settings_errors(), 30 );

		\wp_safe_redirect( \admin_url( 'options-general.php?page=atmosphere&connected=1' ) );
		exit;
	}

	/**
	 * Handle the explicit "use my domain as my Bluesky handle" submission.
	 *
	 * Verifies capability + nonce, defers to {@see Handle::set_handle()} for
	 * the actual call, then redirects back to the Settings page with a notice
	 * already populated by Handle.
	 */
	public static function handle_set_domain_handle(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to do this.', 'atmosphere' ) );
		}

		\check_admin_referer( 'atmosphere_set_domain_handle', 'atmosphere_nonce' );

		Handle::set_handle();

		\wp_safe_redirect( \admin_url( 'options-general.php?page=atmosphere' ) );
		exit;
	}

	/**
	 * Handle the "Disconnect" action.
	 */
	public static function handle_disconnect(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Unauthorized.', 'atmosphere' ) );
		}

		\check_admin_referer( 'atmosphere_disconnect', 'atmosphere_nonce' );

		/*
		 * Best-effort handle revert BEFORE the disconnect drops the OAuth
		 * token: if the site previously set the handle to its domain, restore
		 * the snapshotted previous handle while the access token is still
		 * valid. The call posts a notice on the way out; disconnect proceeds
		 * regardless of result so a token-revoked or network-failed revert
		 * can't trap the user in a connected state.
		 */
		Handle::maybe_revert_on_disconnect();

		/*
		 * Clear the snapshot regardless of revert outcome so it cannot be
		 * revived by a future reconnect to a different account. Once the
		 * OAuth token is gone there is no way to retry a failed revert
		 * anyway, so the snapshot is dead weight after this point.
		 */
		\delete_option( Handle::OPTION_PREVIOUS_HANDLE );

		Client::disconnect();

		\add_settings_error(
			'atmosphere',
			'disconnected',
			\__( 'Disconnected from AT Protocol.', 'atmosphere' ),
			'info'
		);
		\set_transient( 'settings_errors', \get_settings_errors(), 30 );

		\wp_safe_redirect( \admin_url( 'options-general.php?page=atmosphere' ) );
		exit;
	}

	/**
	 * Add the ATmosphere meta box to syncable post types.
	 */
	public static function add_meta_box(): void {
		if ( ! is_connected() ) {
			return;
		}

		foreach ( get_supported_post_types() as $post_type ) {
			\add_meta_box(
				'atmosphere',
				\__( 'ATmosphere', 'atmosphere' ),
				array( self::class, 'render_meta_box' ),
				$post_type,
				'side'
			);
		}
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		\load_template(
			ATMOSPHERE_PLUGIN_DIR . 'templates/meta-box.php',
			false,
			array( 'post' => $post )
		);
	}

	/**
	 * Render a global admin notice when the OAuth session needs reauth.
	 *
	 * Surfaced on every admin screen (gated on `manage_options`) because
	 * the publish, comment, and update paths silently no-op until the
	 * user reconnects — without a visible nudge, an expired refresh
	 * token can sit unnoticed for days. The notice is dismissible per
	 * page-load only so the user is reminded again on their next visit.
	 */
	public static function maybe_render_reauth_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! needs_reauth() ) {
			return;
		}

		$settings_url = \admin_url( 'options-general.php?page=atmosphere' );

		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php \esc_html_e( 'ATmosphere: reconnection required', 'atmosphere' ); ?></strong>
			</p>
			<p>
				<?php
				echo \wp_kses(
					\sprintf(
						/* translators: %s: URL to the ATmosphere settings page. */
						\__( 'Your AT Protocol session has expired. New posts and comments will not publish until you <a href="%s">reconnect on the settings page</a>. Your publishing preferences and verification headers stay in place in the meantime.', 'atmosphere' ),
						\esc_url( $settings_url )
					),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register the client-metadata REST endpoint.
	 */
	public static function register_rest_routes(): void {
		\register_rest_route(
			'atmosphere/v1',
			'/client-metadata',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'serve_client_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Serve the OAuth client metadata JSON.
	 *
	 * This endpoint URL IS the client_id per AT Protocol OAuth spec.
	 *
	 * @return \WP_REST_Response
	 */
	public static function serve_client_metadata(): \WP_REST_Response {
		$metadata = array(
			'client_id'                  => Client::client_id(),
			'client_name'                => \get_bloginfo( 'name' ) . ' (ATmosphere)',
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
			'scope'                      => 'atproto transition:generic identity:handle',
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
		 *    every entry is rooted at this site's admin
		 *    (`admin_url('')` prefix). Off-site / empty / non-string /
		 *    nested-array entries cause the entire filter result to be
		 *    rejected.
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

		if ( self::client_metadata_filter_is_valid( $filtered ) ) {
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
				'0.1.0'
			);
		}

		$response = new \WP_REST_Response( $metadata, 200 );

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
	 *  - Each entry begins with this site's admin URL prefix
	 *    (`admin_url('')`), the same gate
	 *    {@see \Atmosphere\OAuth\Client::redirect_uri()} applies to
	 *    the inbound filter. An off-site / scheme-mismatched / empty
	 *    entry disqualifies the entire filter result.
	 *
	 * Returns true only if every check passes; the caller falls back
	 * to the unfiltered metadata on false.
	 *
	 * @param mixed $filtered Filter return value.
	 * @return bool
	 */
	private static function client_metadata_filter_is_valid( $filtered ): bool {
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

		$admin_prefix = \admin_url( '' );

		foreach ( $filtered['redirect_uris'] as $uri ) {
			if ( ! \is_string( $uri )
				|| '' === $uri
				|| ! \str_starts_with( $uri, $admin_prefix )
			) {
				return false;
			}
		}

		return true;
	}
}
