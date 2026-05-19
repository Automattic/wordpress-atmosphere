<?php
/**
 * Settings API option registration.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Client;

/**
 * Registers every stored plugin option with the Settings API.
 *
 * Mirrors the convention the ActivityPub plugin uses: hook
 * `register_settings()` on both `admin_init` (so `options.php` form
 * submissions run the registered sanitize callbacks) and
 * `rest_api_init` (so `/wp-json/wp/v2/settings` exposes the registered
 * shape and resolves defaults for REST callers). Without the REST
 * registration, third-party code reading these options through the
 * REST endpoint would see empty values instead of the documented
 * defaults on a fresh install.
 */
class Options {

	/**
	 * Wire the registration hooks.
	 */
	public static function init(): void {
		\add_action( 'admin_init', array( self::class, 'register_settings' ) );
		\add_action( 'rest_api_init', array( self::class, 'register_settings' ) );
	}

	/**
	 * Register every option with the Settings API.
	 */
	public static function register_settings(): void {
		\register_setting(
			'atmosphere',
			'atmosphere_auto_publish',
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		\register_setting(
			'atmosphere',
			'atmosphere_long_form_composition',
			array(
				'type'              => 'string',
				'description'       => 'Composition strategy for long-form Bluesky posts.',
				'default'           => 'link-card',
				'sanitize_callback' => array( self::class, 'sanitize_long_form_composition' ),
				'show_in_rest'      => array(
					'schema' => array(
						'enum' => Atmosphere::LONG_FORM_STRATEGIES,
					),
				),
			)
		);

		\register_setting(
			'atmosphere',
			'atmosphere_support_post_types',
			array(
				'type'              => 'array',
				'description'       => 'Post types to publish to AT Protocol.',
				'default'           => array( 'post' ),
				'sanitize_callback' => array( Post_Types::class, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);

		\register_setting(
			'atmosphere',
			'atmosphere_handle',
			array(
				'type'              => 'string',
				'show_in_rest'      => false,
				'sanitize_callback' => array( self::class, 'sanitize_handle' ),
			)
		);
	}

	/**
	 * Sanitize the long-form composition setting.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_long_form_composition( $value ): string {
		$value = \is_string( $value ) ? \sanitize_text_field( $value ) : '';

		return \in_array( $value, Atmosphere::LONG_FORM_STRATEGIES, true ) ? $value : 'link-card';
	}

	/**
	 * Sanitize the handle field and trigger OAuth if a value is submitted.
	 *
	 * @param string $value The submitted handle.
	 * @return string Empty string (never stored).
	 */
	public static function sanitize_handle( $value ): string {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return '';
		}

		$handle = \sanitize_text_field( $value );

		if ( empty( $handle ) ) {
			return '';
		}

		$auth_url = Client::authorize( $handle );

		if ( \is_wp_error( $auth_url ) ) {
			\add_settings_error( 'atmosphere', 'auth_failed', $auth_url->get_error_message() );
			return '';
		}

		/*
		 * `$auth_url` is built from the auth-server metadata returned
		 * by the resolution chain. The resolver validates each URL it
		 * persists, but defence-in-depth: re-check the scheme + host
		 * before redirecting an admin so a misconfigured filter or
		 * future code path can't slip a `javascript:` / `data:` URI
		 * through.
		 *
		 * `wp_safe_redirect` would normally reject this destination —
		 * it's intentionally off-site (the AT Protocol auth server).
		 * Add the auth-server host to `allowed_redirect_hosts` for the
		 * `wp_safe_redirect` call, then immediately detach the filter
		 * so it can't affect any subsequent redirect — the `exit`
		 * makes that production-redundant, but pinning the invariant
		 * here keeps it intact if a test or a `wp_die()` handler ever
		 * intercepts the redirect before `exit` fires.
		 */
		$auth_host   = \is_string( $auth_url ) ? \wp_parse_url( $auth_url, PHP_URL_HOST ) : '';
		$auth_scheme = \is_string( $auth_url ) ? \wp_parse_url( $auth_url, PHP_URL_SCHEME ) : '';

		if ( empty( $auth_host ) || 'https' !== $auth_scheme ) {
			\add_settings_error(
				'atmosphere',
				'auth_failed',
				\__( 'Authorization URL is not a safe HTTPS target.', 'atmosphere' )
			);
			return '';
		}

		$allow_auth_host = static function ( $hosts ) use ( $auth_host ) {
			$hosts[] = $auth_host;
			return $hosts;
		};

		\add_filter( 'allowed_redirect_hosts', $allow_auth_host );
		\wp_safe_redirect( $auth_url );
		\remove_filter( 'allowed_redirect_hosts', $allow_auth_host );
		exit;
	}
}
