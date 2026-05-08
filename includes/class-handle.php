<?php
/**
 * Domain-handle service: replace the connected Bluesky handle with the site host.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * Coordinates the "use my domain as my Bluesky handle" feature.
 *
 * Calls `com.atproto.identity.updateHandle` on the connected PDS so the
 * site's host (e.g. `example.com`) becomes the public AT Protocol handle.
 * Bluesky verifies the change against `/.well-known/atproto-did`, which
 * `Atmosphere::serve_wellknown_atproto_did()` already serves.
 *
 * Changing a handle is destructive — the previous handle stops resolving
 * — so the call ALWAYS requires explicit user confirmation. There is no
 * automatic mode.
 */
class Handle {

	/**
	 * Filter name for the feature kill-switch.
	 *
	 * @var string
	 */
	public const FILTER_ENABLED = 'atmosphere_domain_handle_enabled';

	/**
	 * Filter name for short-circuiting the actual `updateHandle` call.
	 *
	 * @var string
	 */
	public const FILTER_PRE_UPDATE = 'atmosphere_pre_update_handle';

	/**
	 * Settings-error slug used when surfacing notices.
	 *
	 * @var string
	 */
	public const NOTICE_SETTING = 'atmosphere';

	/**
	 * Option storing the previous handle so disconnect can revert.
	 *
	 * @var string
	 */
	public const OPTION_PREVIOUS_HANDLE = 'atmosphere_previous_handle';

	/**
	 * Whether the entire feature is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		/**
		 * Filter whether the domain-handle feature is enabled.
		 *
		 * Filter to false to fully disable: the Settings panel suppresses
		 * the confirm button, and disconnect does not attempt to revert.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) \apply_filters( self::FILTER_ENABLED, true );
	}

	/**
	 * Whether the site is at the root of its domain.
	 *
	 * Subdirectory installs (e.g. `https://example.com/blog/`) cannot serve
	 * the AT Protocol verification endpoint at the domain root, so the
	 * feature must skip them — Bluesky's PDS would reject the handle change
	 * because it cannot fetch `/.well-known/atproto-did` at the host root.
	 *
	 * @return bool
	 */
	public static function is_root_install(): bool {
		$path = \wp_parse_url( \home_url(), PHP_URL_PATH );

		return null === $path || '' === $path || '/' === $path;
	}
}
