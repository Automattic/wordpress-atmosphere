<?php
/**
 * Sanitize callbacks for plugin-owned settings.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Content_Parser\Registry;
use Atmosphere\OAuth\Client;

/**
 * Stateless sanitize helpers wired to `register_setting()` callbacks.
 *
 * Method names read as verb phrases at the call site (`Sanitize::handle`,
 * `Sanitize::long_form_composition`) so the class name and the method
 * together describe the action.
 */
class Sanitize {

	/**
	 * Sanitize a boolean option while preserving its string storage format.
	 *
	 * WordPress caches the value passed to `update_option()` without first
	 * converting scalar values to their database string representation. Keep
	 * these existing checkbox options as `'1'` or `''` so strict comparisons
	 * behave consistently whether the value comes from the database or cache.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function boolean_option( $value ): string {
		return \rest_sanitize_boolean( $value ) ? '1' : '';
	}

	/**
	 * Sanitize the handle field and trigger OAuth if a value is submitted.
	 *
	 * Used as the `sanitize_callback` for the `atmosphere_handle`
	 * setting. The value is never stored: when a handle comes in, this
	 * method resolves it via {@see Client::authorize()} and redirects
	 * the admin to the auth server. The empty string return keeps the
	 * Settings API from persisting anything to `wp_options`.
	 *
	 * @param string $value The submitted handle.
	 * @return string Empty string (never stored).
	 */
	public static function handle( $value ): string {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return '';
		}

		$handle = normalize_handle( $value );

		if ( empty( $handle ) ) {
			return '';
		}

		// A settings-page connect lands back on the settings page: the origin
		// travels inside this flow's own resolved record (see Client::authorize),
		// so it can't be crossed with a Connectors-card flow.
		$auth_url = Client::authorize( $handle, 'settings' );

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
		 * intercepts the redirect before `exit` fires. The `finally`
		 * guarantees detachment even when a `wp_redirect` filter throws
		 * instead of returning.
		 */
		if ( ! \is_string( $auth_url ) || ! self::is_safe_authorize_url( $auth_url ) ) {
			\add_settings_error(
				'atmosphere',
				'auth_failed',
				\__( 'Authorization URL is not a safe HTTPS target.', 'atmosphere' )
			);
			return '';
		}

		$auth_host = \wp_parse_url( $auth_url, PHP_URL_HOST );

		$allow_auth_host = static function ( $hosts ) use ( $auth_host ) {
			$hosts[] = $auth_host;
			return $hosts;
		};

		\add_filter( 'allowed_redirect_hosts', $allow_auth_host );
		try {
			\wp_safe_redirect( $auth_url );
		} finally {
			\remove_filter( 'allowed_redirect_hosts', $allow_auth_host );
		}
		exit;
	}

	/**
	 * Whether an OAuth authorization URL is a safe HTTPS target.
	 *
	 * Defence-in-depth for the URL {@see Client::authorize()} builds from
	 * auth-server metadata. The resolver validates each URL it persists, but
	 * both connect entry points re-check the scheme + host before acting on the
	 * URL — {@see self::handle()} before an admin redirect, and
	 * {@see \Atmosphere\Rest\Admin\Connection_Controller::authorize()} before
	 * handing it to the Connectors card, which navigates client-side — so a
	 * misconfigured `atmosphere_*` filter or future code path can't slip a
	 * `javascript:` / `data:` URI through.
	 *
	 * @param string $url Authorization URL returned by {@see Client::authorize()}.
	 * @return bool True when the URL is an absolute `https://` URL with a host.
	 */
	public static function is_safe_authorize_url( string $url ): bool {
		return 'https' === \wp_parse_url( $url, PHP_URL_SCHEME )
			&& ! empty( \wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Sanitize the long-form composition setting.
	 *
	 * Used as the `sanitize_callback` for the
	 * `atmosphere_long_form_composition` setting. Falls back to
	 * `'link-card'` when the submitted value is missing, the wrong
	 * type, or not one of the known strategy slugs.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function long_form_composition( $value ): string {
		$value = \is_string( $value ) ? \sanitize_text_field( $value ) : '';

		return \in_array( $value, Atmosphere::LONG_FORM_STRATEGIES, true ) ? $value : 'link-card';
	}

	/**
	 * Sanitize the content-format setting.
	 *
	 * Used as the `sanitize_callback` for the `atmosphere_content_format`
	 * option. Accepts an empty string (automatic) or a registered parser
	 * NSID; anything else falls back to automatic.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function content_format( $value ): string {
		$value = \is_string( $value ) ? \sanitize_text_field( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		return Registry::has( $value ) ? $value : '';
	}
}
