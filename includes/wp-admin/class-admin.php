<?php
/**
 * Admin settings page, meta box, and hook wiring.
 *
 * @package Atmosphere
 */

namespace Atmosphere\WP_Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Backfill;
use Atmosphere\OAuth\Client;
use Atmosphere\Publisher;
use function Atmosphere\get_connection;
use function Atmosphere\is_connected;

/**
 * Admin class.
 */
class Admin {

	/**
	 * Boot admin hooks.
	 */
	public static function register(): void {
		\add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		\add_action( 'admin_init', array( self::class, 'handle_oauth_callback' ) );
		\add_action( 'admin_init', array( self::class, 'register_settings' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );

		\add_action( 'admin_post_atmosphere_connect', array( self::class, 'handle_connect' ) );
		\add_action( 'admin_post_atmosphere_disconnect', array( self::class, 'handle_disconnect' ) );
		\add_action( 'admin_post_atmosphere_sync_publication', array( self::class, 'handle_sync_publication' ) );

		// Meta box on syncable post types.
		\add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );

		// REST route for client metadata.
		\add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
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
	 * Register plugin settings.
	 */
	public static function register_settings(): void {
		\register_setting( 'atmosphere', 'atmosphere_auto_publish', array( 'default' => '1' ) );
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
	 * Handle the "Connect" form submission.
	 */
	public static function handle_connect(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Unauthorized.', 'atmosphere' ) );
		}

		\check_admin_referer( 'atmosphere_connect', 'atmosphere_nonce' );

		$handle = isset( $_POST['atmosphere_handle'] )
			? \sanitize_text_field( \wp_unslash( $_POST['atmosphere_handle'] ) )
			: '';

		if ( empty( $handle ) ) {
			\add_settings_error( 'atmosphere', 'no_handle', \__( 'Please enter a handle.', 'atmosphere' ) );
			\set_transient( 'settings_errors', \get_settings_errors(), 30 );
			\wp_safe_redirect( \admin_url( 'options-general.php?page=atmosphere' ) );
			exit;
		}

		$auth_url = Client::authorize( $handle );

		if ( \is_wp_error( $auth_url ) ) {
			\add_settings_error( 'atmosphere', 'auth_failed', $auth_url->get_error_message() );
			\set_transient( 'settings_errors', \get_settings_errors(), 30 );
			\wp_safe_redirect( \admin_url( 'options-general.php?page=atmosphere' ) );
			exit;
		}

		\wp_redirect( $auth_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
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

		\wp_safe_redirect( \admin_url( 'options-general.php?page=atmosphere&connected=1' ) );
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
	 * Handle "Sync Publication" action.
	 */
	public static function handle_sync_publication(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'Unauthorized.', 'atmosphere' ) );
		}

		\check_admin_referer( 'atmosphere_sync_publication', 'atmosphere_nonce' );

		$result = Publisher::sync_publication();

		if ( \is_wp_error( $result ) ) {
			\add_settings_error( 'atmosphere', 'sync_failed', $result->get_error_message() );
		} else {
			\add_settings_error(
				'atmosphere',
				'synced',
				\__( 'Publication record synced.', 'atmosphere' ),
				'success'
			);
		}

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

		foreach ( Backfill::syncable_post_types() as $post_type ) {
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
		include ATMOSPHERE_PLUGIN_DIR . 'templates/meta-box.php';
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
			'scope'                      => 'atproto transition:generic',
			'dpop_bound_access_tokens'   => true,
			'application_type'           => 'web',
		);

		/**
		 * Filters the OAuth client metadata served at the REST endpoint.
		 *
		 * @param array $metadata Client metadata.
		 */
		$metadata = \apply_filters( 'atmosphere_client_metadata', $metadata );

		return new \WP_REST_Response( $metadata, 200 );
	}
}
