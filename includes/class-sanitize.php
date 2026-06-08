<?php
/**
 * Sanitize callbacks for plugin-owned settings.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

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

		$handle = \sanitize_text_field( $value );

		/*
		 * Strip a leading "@". Bluesky surfaces handles as "@alice.bsky.social",
		 * so people naturally type the "@" in — but the resolver expects a bare
		 * DNS-style identifier and would reject the "@"-prefixed form as invalid.
		 */
		$handle = \ltrim( $handle, '@' );

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
}
