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
	 * Also rejects split installs where `home_url` is at the host root but
	 * `site_url` lives in a subdirectory (e.g. `WP_HOME=https://example.com`,
	 * `WP_SITEURL=https://example.com/wp`). The `.well-known/atproto-did`
	 * rewrite resolves through WordPress's request parser rooted at
	 * `site_url`, so the verification fetch would 404 even though `home_url`
	 * looks correct — and the PDS would have already accepted the new
	 * handle by the time we found out.
	 *
	 * @return bool
	 */
	public static function is_root_install(): bool {
		$home = \wp_parse_url( \home_url() );
		$site = \wp_parse_url( \site_url() );

		if ( ! \is_array( $home ) || ! \is_array( $site ) ) {
			return false;
		}

		$home_path = isset( $home['path'] ) ? (string) $home['path'] : '';
		$site_path = isset( $site['path'] ) ? (string) $site['path'] : '';

		if ( '' !== $home_path && '/' !== $home_path ) {
			return false;
		}

		if ( '' !== $site_path && '/' !== $site_path ) {
			return false;
		}

		$home_host = isset( $home['host'] ) ? \strtolower( (string) $home['host'] ) : '';
		$site_host = isset( $site['host'] ) ? \strtolower( (string) $site['host'] ) : '';

		return '' !== $home_host && $home_host === $site_host;
	}

	/**
	 * Compute the handle that the site would advertise.
	 *
	 * Reads the host portion of `home_url()`. Returns empty when the host
	 * cannot be resolved so callers refuse to send an empty payload.
	 *
	 * @return string
	 */
	public static function get_target_handle(): string {
		$host = \wp_parse_url( \home_url(), PHP_URL_HOST );

		return \is_string( $host ) ? \strtolower( $host ) : '';
	}

	/**
	 * Replace the connected user's Bluesky handle with the site host.
	 *
	 * Fail-safe default: short-circuits when the current user lacks
	 * `manage_options`. Callers in admin contexts (e.g.
	 * {@see \Atmosphere\WP_Admin\Admin::handle_set_domain_handle()}) still
	 * verify nonce themselves; the cap gate here is belt-and-braces so a
	 * future caller (cron, REST, WP-CLI) cannot accidentally trigger an
	 * `updateHandle` against the user's connected account without an
	 * explicit cap-bearing context.
	 *
	 * On success: snapshots the current handle (so disconnect can revert),
	 * invokes `com.atproto.identity.updateHandle` via Atmosphere's DPoP
	 * client, and posts a settings notice describing the outcome.
	 *
	 * @return true|\WP_Error|null Null when the feature is disabled, the
	 *                              install is ineligible, the current user
	 *                              lacks the required capability, the
	 *                              connection handle already matches the
	 *                              site host, or no host can be derived.
	 *                              True on success. WP_Error on failure.
	 */
	public static function set_handle(): true|\WP_Error|null {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return null;
		}

		if ( ! self::is_enabled() || ! self::is_root_install() ) {
			return null;
		}

		$target = self::get_target_handle();
		if ( '' === $target ) {
			return null;
		}

		if ( ! is_connected() ) {
			self::add_settings_notice(
				\__( 'Connect to Bluesky before setting your domain handle.', 'atmosphere' ),
				'error'
			);
			return new \WP_Error(
				'atmosphere_not_connected',
				\__( 'Not connected to Bluesky.', 'atmosphere' )
			);
		}

		$connection = get_connection();
		$current    = isset( $connection['handle'] ) ? \strtolower( (string) $connection['handle'] ) : '';

		if ( $current === $target ) {
			return null;
		}

		if ( '' !== $current ) {
			/*
			 * Snapshot the current handle BEFORE the XRPC call. If the call
			 * succeeds, the snapshot is what we revert to on disconnect. If it
			 * fails, the PDS handle is unchanged, so the snapshot still equals
			 * the user's actual handle and a later revert call is a safe no-op.
			 */
			\update_option( self::OPTION_PREVIOUS_HANDLE, $current, false );
		}

		$result = self::call_update_handle( $target );

		if ( \is_wp_error( $result ) ) {
			self::add_settings_notice(
				\sprintf(
					/* translators: 1: target handle (the site domain); 2: error message from the PDS. */
					\__( 'Could not set %1$s as your Bluesky handle: %2$s', 'atmosphere' ),
					$target,
					$result->get_error_message()
				),
				'error'
			);
			return $result;
		}

		self::sync_connection_handle( $target );

		self::add_settings_notice(
			\sprintf(
				/* translators: %s: the handle the site set itself to (e.g. example.com). */
				\__( 'Your Bluesky handle is now %s.', 'atmosphere' ),
				$target
			),
			'success'
		);

		return true;
	}

	/**
	 * Attempt to revert to the previously snapshotted handle.
	 *
	 * No-op when the feature is disabled or there is nothing to revert.
	 * Caller (the disconnect flow) MUST invoke this BEFORE
	 * `\Atmosphere\OAuth\Client::disconnect()` so the access token is still
	 * valid for the call.
	 *
	 * @return true|\WP_Error|null Null when no revert was attempted.
	 */
	public static function maybe_revert_on_disconnect(): true|\WP_Error|null {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$previous = (string) \get_option( self::OPTION_PREVIOUS_HANDLE, '' );
		if ( '' === $previous ) {
			return null;
		}

		$result = self::call_update_handle( $previous );

		if ( \is_wp_error( $result ) ) {
			self::add_settings_notice(
				\sprintf(
					/* translators: 1: previous handle to restore; 2: error message from the PDS. */
					\__( 'Could not restore your previous Bluesky handle (%1$s): %2$s', 'atmosphere' ),
					$previous,
					$result->get_error_message()
				),
				'warning'
			);
			return $result;
		}

		\delete_option( self::OPTION_PREVIOUS_HANDLE );

		/*
		 * Mirror the restored handle into atmosphere_connection. On the
		 * standard disconnect path Client::disconnect() drops the option
		 * moments later, so this write is wasted there — but keeping it
		 * decouples Handle from disconnect ordering, so any future caller
		 * (e.g. a manual revert action that does not also disconnect) gets
		 * a consistent local snapshot.
		 */
		self::sync_connection_handle( $previous );

		self::add_settings_notice(
			\sprintf(
				/* translators: %s: the handle that was restored (e.g. alice.bsky.social). */
				\__( 'Restored your previous Bluesky handle: %s.', 'atmosphere' ),
				$previous
			),
			'info'
		);

		return true;
	}

	/**
	 * Issue the `com.atproto.identity.updateHandle` call.
	 *
	 * Runs the `atmosphere_pre_update_handle` short-circuit filter first so
	 * tests and integrations can observe / mock the call without going
	 * through the DPoP layer (which would otherwise require real encrypted
	 * keys to even build a request).
	 *
	 * @param string $handle Handle to set on the connected account.
	 * @return true|\WP_Error
	 */
	private static function call_update_handle( string $handle ): true|\WP_Error {
		/**
		 * Short-circuits the `com.atproto.identity.updateHandle` call.
		 *
		 * Return `true` to fake success, a `WP_Error` to fake failure, or
		 * `null` (the default) to fall through to the real PDS request.
		 *
		 * @param null|true|\WP_Error $short_circuit Short-circuit value.
		 * @param string              $handle        Handle that would be set.
		 */
		$short_circuit = \apply_filters( self::FILTER_PRE_UPDATE, null, $handle );

		if ( true === $short_circuit ) {
			return true;
		}

		if ( \is_wp_error( $short_circuit ) ) {
			return $short_circuit;
		}

		if ( null !== $short_circuit ) {
			return new \WP_Error(
				'atmosphere_invalid_pre_update_handle_return',
				\__( 'atmosphere_pre_update_handle must return null, true, or a WP_Error.', 'atmosphere' )
			);
		}

		$response = API::post(
			'/xrpc/com.atproto.identity.updateHandle',
			array( 'handle' => $handle )
		);

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Mirror a successful PDS handle change into the local connection option.
	 *
	 * The PDS is now serving the new handle, but `atmosphere_connection`
	 * still holds the value captured at OAuth time. Without this update
	 * the Settings page shows the stale handle and {@see self::should_offer()}
	 * keeps offering the panel because `current !== target`.
	 *
	 * @param string $handle New handle to record locally.
	 */
	private static function sync_connection_handle( string $handle ): void {
		$connection = get_connection();
		if ( ! empty( $connection ) ) {
			$connection['handle'] = $handle;
			\update_option( 'atmosphere_connection', $connection, false );
		}

		/*
		 * Mirror to the durable identity option as well — that is now
		 * the canonical store consulted by `get_identity()` /
		 * `has_identity()`, and the publishing UI / verification
		 * headers read from there. Without this write, a handle change
		 * would silently drift on the public surface even though the
		 * PDS has accepted it. Use the namespace helper rather than
		 * `get_option` directly so a legacy connection still on the
		 * pre-split shape gets lazy-migrated as a side effect. Identity
		 * stays autoloaded (true) because it is read on every public
		 * verification request and contains no secret material; the
		 * autoload=false above applies only to `atmosphere_connection`,
		 * which holds the encrypted tokens.
		 */
		$identity = get_identity();
		if ( ! empty( $identity['did'] ) ) {
			$identity['handle'] = $handle;
			\update_option( 'atmosphere_identity', $identity, true );
		}
	}

	/**
	 * Persist a settings notice under the Atmosphere group.
	 *
	 * Stores via the `settings_errors` transient so the message survives
	 * the `wp_safe_redirect` admin-post handlers issue. Does not redirect.
	 *
	 * @param string $message Translated message to surface.
	 * @param string $type    Notice type (`success`, `error`, `warning`, `info`).
	 */
	private static function add_settings_notice( string $message, string $type ): void {
		\add_settings_error( self::NOTICE_SETTING, 'atmosphere_domain_handle', $message, $type );
		\set_transient( 'settings_errors', \get_settings_errors(), 30 );
	}

	/**
	 * Whether the confirm-handle UI should render for the given status.
	 *
	 * @param array<string, mixed> $status Connection status snapshot with at
	 *                                     least `connected` and `handle`.
	 * @return bool
	 */
	public static function should_offer( array $status ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		if ( ! self::is_root_install() ) {
			return false;
		}

		$target = self::get_target_handle();
		if ( '' === $target ) {
			return false;
		}

		if ( empty( $status['connected'] ) ) {
			return false;
		}

		$current = isset( $status['handle'] ) ? (string) $status['handle'] : '';
		if ( '' !== $current && \strtolower( $current ) === $target ) {
			return false;
		}

		return true;
	}
}
