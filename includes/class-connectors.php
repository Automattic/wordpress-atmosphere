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
	 * WordPress core serves the Connectors screen as this top-level admin page. On a
	 * site running the Gutenberg plugin the screen instead lives at a Settings
	 * submenu, `options-general.php?page=options-connectors-wp-admin`, so it has two
	 * possible URLs depending on which is present. Both are matched through the shared
	 * {@see self::SCREEN_SLUG} marker rather than this exact filename — for the card enqueue (see
	 * {@see self::is_connectors_screen()}) and for the OAuth return destination
	 * (see {@see self::screen_url()} and
	 * {@see \Atmosphere\WP_Admin\Admin::handle_oauth_callback()}). The card never
	 * tells the server where to come back to — it only flags that the flow started
	 * on the Connectors screen; the server resolves the concrete URL from the
	 * registered admin menu, never from request input.
	 *
	 * @var string
	 */
	public const SCREEN = 'options-connectors.php';

	/**
	 * The stable marker shared by every variant of the Connectors screen slug.
	 *
	 * Core's page is `options-connectors.php`; on a site running the Gutenberg plugin
	 * it is a Settings submenu with a slug like `options-connectors-wp-admin`. Both
	 * contain this substring, so matching on it recognizes the screen regardless of
	 * which one a given site has, while staying scoped enough not to match anything
	 * else.
	 *
	 * @var string
	 */
	public const SCREEN_SLUG = 'options-connectors';

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
	 * form and lets our script module render the card. The `plugin.file` entry
	 * lets core derive the connector's installed/active state for the card UI,
	 * defaulting `is_active` to its own `is_plugin_active()` check.
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
				'plugin'         => array(
					'file' => \plugin_basename( ATMOSPHERE_PLUGIN_FILE ),
				),
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
		if ( ! self::is_connectors_screen( $hook_suffix ) ) {
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

		\wp_enqueue_style(
			'atmosphere-handle-typeahead',
			ATMOSPHERE_PLUGIN_URL . 'assets/css/handle-typeahead.css',
			array( 'wp-components' ),
			ATMOSPHERE_VERSION
		);
	}

	/**
	 * Whether an admin hook suffix identifies the Connectors screen.
	 *
	 * Core passes `options-connectors.php`; on a site running the Gutenberg plugin
	 * the hook suffix is `settings_page_options-connectors-wp-admin` (and any similar
	 * `{parent}_page_options-connectors-*` variant). Match both by looking for the
	 * shared {@see self::SCREEN_SLUG} marker, which stays scoped to this one screen so
	 * the card never enqueues elsewhere.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return bool True on the Connectors screen in any of its registered forms.
	 */
	public static function is_connectors_screen( string $hook_suffix ): bool {
		return \str_contains( $hook_suffix, self::SCREEN_SLUG );
	}

	/**
	 * The admin URL of the Connectors screen for this install.
	 *
	 * Used as the OAuth return destination for a Connectors-initiated connect.
	 * Core serves the screen at `options-connectors.php`; on a site running the
	 * Gutenberg plugin it is a Settings submenu at
	 * `options-general.php?page=options-connectors-wp-admin`. Prefer the submenu URL
	 * when it is present in the admin menu, so the flow returns to the screen the user
	 * actually came from; otherwise fall back to core's top-level page.
	 *
	 * The REST authorize route registers on WP 6.5+, so a host plugin can drive a
	 * Connectors-origin connect on a site that has no Connectors screen at all
	 * (WP < 7.0, where `options-connectors.php` does not exist). Falling back to
	 * that file there would 404 after the callback, so land on the settings page
	 * instead when the Connectors API is absent.
	 *
	 * The URL is resolved from the server-side admin menu, never from request
	 * input, so it stays a trusted, hardcoded-shape destination for
	 * `wp_safe_redirect()`.
	 *
	 * @return string Admin URL of the Connectors screen, or the settings page on WP < 7.0.
	 */
	public static function screen_url(): string {
		$submenu_url = self::submenu_screen_url();
		if ( null !== $submenu_url ) {
			return $submenu_url;
		}

		// WP < 7.0 has no top-level Connectors page; `options-connectors.php`
		// would 404. Send the flow to the settings page instead.
		if ( ! \class_exists( 'WP_Connector_Registry' ) ) {
			return settings_url();
		}

		return \admin_url( self::SCREEN );
	}

	/**
	 * Locate the Gutenberg plugin's Connectors submenu entry, if present.
	 *
	 * When the Gutenberg plugin provides the screen, it lives under a parent
	 * (Settings) with a menu slug that carries the {@see self::SCREEN_SLUG} marker
	 * (e.g. `options-connectors-wp-admin`). Walk the registered submenus for such an
	 * entry and build its admin URL from the parent it lives under. Returns null on a
	 * core-only site, where the screen is its own top-level page rather than a submenu.
	 *
	 * The admin menu (`admin_menu`) is fully built before `admin_init`, where the
	 * OAuth callback resolves this, so `$submenu` is populated by then.
	 *
	 * @return string|null Admin URL of the submenu screen, or null if none found.
	 */
	private static function submenu_screen_url(): ?string {
		$submenu = $GLOBALS['submenu'] ?? null;
		if ( ! \is_array( $submenu ) ) {
			return null;
		}

		foreach ( $submenu as $parent => $items ) {
			if ( ! \is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				/*
				 * WP submenu items are [ title, capability, slug, ... ]; index 2
				 * is the menu slug, regardless of the item's position in the menu.
				 */
				$slug = $item[2] ?? '';
				if (
					\is_string( $slug )
					&& self::SCREEN !== $slug
					&& \str_contains( $slug, self::SCREEN_SLUG )
				) {
					return \add_query_arg( 'page', $slug, \admin_url( (string) $parent ) );
				}
			}
		}

		return null;
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
				'typeaheadUrl'   => handle_typeahead_url(),
			)
		);
	}
}
