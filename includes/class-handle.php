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
}
