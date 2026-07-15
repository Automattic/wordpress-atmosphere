<?php
/**
 * WordPress Connectors API integration.
 *
 * Surfaces ATmosphere's AT Protocol account connection on the core
 * Settings → Connectors screen (WordPress 7.0+), so the same account can be
 * connected from there in addition to the plugin's own settings page.
 *
 * The connector registers with `authentication.method => 'none'`, which tells
 * core not to render its built-in API-key form and to defer the card UI to a
 * script module we supply — the same pattern Jetpack uses for its (non-API-key)
 * WordPress.com connection. The card drives the OAuth flow through ATmosphere's
 * own authenticated REST routes (see {@see \Atmosphere\Rest\Admin\Connection_Controller}).
 *
 * @package Atmosphere
 */

namespace Atmosphere;

use Atmosphere\Rest\Admin\Connection_Controller;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers ATmosphere as a WordPress 7.0 connector.
 */
class Connectors {

	/**
	 * Connector id.
	 *
	 * Named after the plugin that provides the connection, mirroring how
	 * Jetpack registers its own "Jetpack Connection" card: ATmosphere owns this
	 * AT Protocol connection and is its flagship consumer. The card connects an
	 * AT Protocol account (used for Bluesky *and* standard.site), so it is not
	 * branded to any single app.
	 *
	 * @var string
	 */
	public const CONNECTOR_ID = 'atmosphere';

	/**
	 * Script module id for the card UI.
	 *
	 * Also the id the browser reads the hydration payload from (core prints it
	 * as `wp-script-module-data-{id}`), so it must match the id used JS-side.
	 *
	 * @var string
	 */
	public const MODULE_ID = '@atmosphere/connectors-card';

	/**
	 * The core Connectors admin page.
	 *
	 * WordPress ships the Connectors screen as this top-level admin page. It is
	 * the single source for both jobs the screen serves here: matching the card
	 * enqueue (`admin_enqueue_scripts` passes it verbatim as `$hook_suffix`, see
	 * {@see self::maybe_enqueue()}) and, in the OAuth callback, the hardcoded
	 * destination a Connectors-initiated connect returns to (see
	 * {@see \Atmosphere\WP_Admin\Admin::handle_oauth_callback()}). One screen
	 * means one return target, so the card never tells the server where to come
	 * back to — it only flags that the flow started here.
	 *
	 * @var string
	 */
	public const SCREEN = 'options-connectors.php';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		\add_action( 'wp_connectors_init', array( self::class, 'register' ), 20 );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue' ) );
	}

	/**
	 * Register the connector with the core registry.
	 *
	 * Runs on `wp_connectors_init` (WP 7.0+ only). The registry is passed by
	 * core; we register once with `method => 'none'` so core skips its API-key
	 * form and lets our script module render the card.
	 *
	 * @param \WP_Connector_Registry $registry The core connector registry.
	 * @return void
	 */
	public static function register( $registry ): void {
		$registry->register(
			self::CONNECTOR_ID,
			array(
				'name'           => \__( 'ATmosphere', 'atmosphere' ),
				'description'    => \__( 'Connect your account to publish to the AT Protocol network with ATmosphere.', 'atmosphere' ),
				'type'           => 'cloud_service',
				'logo_url'       => ATMOSPHERE_PLUGIN_URL . 'assets/images/atmosphere.svg',
				'authentication' => array(
					'method' => 'none',
				),
			)
		);
	}

	/**
	 * Enqueue the card script module + styles on the Connectors screen.
	 *
	 * Gated three ways so this never loads elsewhere: the current admin page must
	 * be the Connectors screen, the registry class must exist, and the built
	 * module asset must be present (so a source checkout that hasn't run
	 * `npm run build` degrades cleanly instead of enqueuing a 404).
	 *
	 * @param string $hook_suffix Current admin page, from `admin_enqueue_scripts`.
	 * @return void
	 */
	public static function maybe_enqueue( string $hook_suffix ): void {
		if ( self::SCREEN !== $hook_suffix ) {
			return;
		}

		if ( ! \class_exists( 'WP_Connector_Registry' ) ) {
			return;
		}

		$asset_file = ATMOSPHERE_PLUGIN_DIR . 'build/connectors-card/index.asset.php';
		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		\wp_register_script_module(
			self::MODULE_ID,
			ATMOSPHERE_PLUGIN_URL . 'build/connectors-card/index.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? ATMOSPHERE_VERSION
		);
		\wp_enqueue_script_module( self::MODULE_ID );

		\add_filter( 'script_module_data_' . self::MODULE_ID, array( self::class, 'get_connector_data' ) );

		\wp_enqueue_style(
			'atmosphere-connectors',
			ATMOSPHERE_PLUGIN_URL . 'assets/css/connectors.css',
			array(),
			ATMOSPHERE_VERSION
		);
	}

	/**
	 * Hydration payload handed to the card script module.
	 *
	 * Reuses the plugin's existing connection-state helpers so the card and the
	 * settings page always agree on whether the account is connected. Merged
	 * into (not replacing) whatever core already put on the module data.
	 *
	 * @param array $data Existing script module data.
	 * @return array Data with ATmosphere connection state added.
	 */
	public static function get_connector_data( $data ): array {
		$identity = get_identity();
		$did      = $identity['did'] ?? '';
		$base     = Connection_Controller::ROUTE_NAMESPACE . '/' . Connection_Controller::ROUTE_BASE;

		return \array_merge(
			(array) $data,
			array(
				'connectorId'    => self::CONNECTOR_ID,
				'isConnected'    => is_connected(),
				'needsReauth'    => needs_reauth(),
				'handle'         => $identity['handle'] ?? '',
				'profileUrl'     => '' !== $did
					? appview_url(
						'profile/' . $did,
						array(
							'type' => 'profile',
							'did'  => $did,
						)
					)
					: '',
				'restRoot'       => \esc_url_raw( \rest_url() ),
				'restNonce'      => \wp_create_nonce( 'wp_rest' ),
				'authorizePath'  => $base . '/authorize',
				'disconnectPath' => $base . '/disconnect',
				'typeaheadUrl'   => self::typeahead_url(),
			)
		);
	}

	/**
	 * The handle typeahead endpoint the card queries as the user types (an
	 * `app.bsky.actor.searchActorsTypeahead` XRPC endpoint).
	 *
	 * Defaults to Bluesky's official unauthenticated public appview
	 * (`public.api.bsky.app`), which is CORS-enabled so the browser can call it
	 * directly. Centralized and filterable the same way {@see appview_url()}
	 * centralizes the appview host: a site can point it elsewhere — e.g. a
	 * network-wide index such as `typeahead.waow.tech` — or return an empty
	 * string to disable typeahead entirely and fall back to manual handle entry.
	 *
	 * @return string The typeahead XRPC endpoint, or '' to disable typeahead.
	 */
	public static function typeahead_url(): string {
		/**
		 * Filters the handle typeahead endpoint used by the Connectors card.
		 *
		 * @param string $url Default typeahead XRPC endpoint. Return '' to
		 *                    disable typeahead and require manual handle entry.
		 */
		$url = (string) \apply_filters(
			'atmosphere_handle_typeahead_url',
			'https://public.api.bsky.app/xrpc/app.bsky.actor.searchActorsTypeahead'
		);

		return '' === $url ? '' : \esc_url_raw( $url );
	}
}
