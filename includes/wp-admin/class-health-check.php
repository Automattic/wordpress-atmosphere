<?php
/**
 * Site Health integration.
 *
 * Surfaces the Bluesky connection state where WordPress users (and the
 * people supporting them) actually look when something is wrong: the
 * Tools → Site Health status screen and its debug-information panel.
 * The admin reconnect notice is dismissible and easy to miss; this
 * check is persistent until the connection is healthy again, and the
 * debug panel makes support threads self-diagnosing.
 *
 * Mirrors the ActivityPub plugin's `Health_Check` layout.
 *
 * @package Atmosphere
 */

namespace Atmosphere\WP_Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\Encryption;
use function Atmosphere\get_did;
use function Atmosphere\get_identity;
use function Atmosphere\get_pds_endpoint;
use function Atmosphere\get_reauth_reason;
use function Atmosphere\get_supported_post_types;
use function Atmosphere\has_identity;
use function Atmosphere\is_auto_publish_enabled;
use function Atmosphere\is_connected;
use function Atmosphere\is_operator_disconnected;
use function Atmosphere\reauth_reason_lead;
use function Atmosphere\settings_url;
use function Atmosphere\threadgate_needs_reconnect;

/**
 * Health check class.
 *
 * @since 2.1.0
 */
class Health_Check {

	/**
	 * Register the direct (non-async) status tests.
	 *
	 * The connection test only reads options — no HTTP — so it cannot
	 * slow the Site Health screen down or flake on network conditions.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public static function add_tests( $tests ) {
		$tests['direct']['atmosphere_test_connection'] = array(
			'label' => \__( 'ATmosphere Bluesky Connection Test', 'atmosphere' ),
			'test'  => array( self::class, 'test_connection' ),
		);

		$tests['direct']['atmosphere_test_threadgate_scope'] = array(
			'label' => \__( 'ATmosphere Bluesky Reply Restrictions Test', 'atmosphere' ),
			'test'  => array( self::class, 'test_threadgate_scope' ),
		);

		return $tests;
	}

	/**
	 * Bluesky connection test.
	 *
	 * Four states, in order of precedence:
	 *
	 *  - connected                  → good.
	 *  - operator disconnected      → recommended (a state the user chose).
	 *  - connection needs reauth    → critical, with cause-specific copy
	 *    driven by the `reauth_reason` marker. The `key_changed` case
	 *    additionally recommends pinning `ATMOSPHERE_ENCRYPTION_KEY` so
	 *    the next salt rotation doesn't break the connection again.
	 *  - never connected            → recommended.
	 *
	 * @return array The test result.
	 */
	public static function test_connection(): array {
		$result = array(
			'label'       => \__( 'ATmosphere is connected to Bluesky', 'atmosphere' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => \__( 'ATmosphere', 'atmosphere' ),
				'color' => 'green',
			),
			'description' => \sprintf(
				'<p>%s</p>',
				\__( 'Your site is connected to Bluesky and can share posts and comments.', 'atmosphere' )
			),
			'actions'     => '',
			'test'        => 'atmosphere_test_connection',
		);

		$state = self::connection_state();

		if ( 'connected' === $state ) {
			return $result;
		}

		$result['actions'] = \sprintf(
			'<p><a href="%s">%s</a></p>',
			\esc_url( settings_url() ),
			\esc_html__( 'Open the ATmosphere settings page', 'atmosphere' )
		);

		if ( 'never_connected' === $state ) {
			$result['status']      = 'recommended';
			$result['label']       = \__( 'ATmosphere is not connected to Bluesky yet', 'atmosphere' );
			$result['description'] = \sprintf(
				'<p>%s</p>',
				\__( 'Connect a Bluesky account on the settings page to start sharing posts and comments.', 'atmosphere' )
			);

			return $result;
		}

		if ( 'disconnected' === $state ) {
			$result['status']      = 'recommended';
			$result['label']       = \__( 'ATmosphere is disconnected from Bluesky', 'atmosphere' );
			$result['description'] = \sprintf(
				'<p>%s</p>',
				\__( 'You disconnected this site from Bluesky. New posts and comments will not be shared until you reconnect.', 'atmosphere' )
			);

			return $result;
		}

		$result['status']         = 'critical';
		$result['badge']['color'] = 'red';
		$result['label']          = \__( 'ATmosphere needs to be reconnected to Bluesky', 'atmosphere' );
		$result['description']    = self::reauth_description();

		return $result;
	}

	/**
	 * Reply-restrictions scope test.
	 *
	 * Reply restrictions were added after the first connections were made,
	 * and they need a scope those connections never asked for. This is the
	 * one place a site admin who never opens the editor would find out.
	 * Recommended, not critical: posts still publish, only the restriction
	 * is skipped.
	 *
	 * @since unreleased
	 *
	 * @return array Site Health test result.
	 */
	public static function test_threadgate_scope(): array {
		$result = array(
			'label'       => \__( 'ATmosphere can restrict who replies on Bluesky', 'atmosphere' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => \__( 'ATmosphere', 'atmosphere' ),
				'color' => 'green',
			),
			'description' => \sprintf(
				'<p>%s</p>',
				\__( 'Your Bluesky connection allows ATmosphere to set who can reply to a shared post.', 'atmosphere' )
			),
			'actions'     => '',
			'test'        => 'atmosphere_test_threadgate_scope',
		);

		if ( ! threadgate_needs_reconnect() ) {
			return $result;
		}

		$result['status']      = 'recommended';
		$result['label']       = \__( 'ATmosphere needs a reconnect to restrict replies on Bluesky', 'atmosphere' );
		$result['description'] = \sprintf(
			'<p>%s</p>',
			\__( 'This site connected to Bluesky before reply restrictions were available. Posts still share as usual, but any reply restriction you set is skipped until you reconnect your account.', 'atmosphere' )
		);
		$result['actions']     = \sprintf(
			'<p><a href="%s">%s</a></p>',
			\esc_url( settings_url() ),
			\esc_html__( 'Reconnect on the ATmosphere settings page', 'atmosphere' )
		);

		return $result;
	}

	/**
	 * Resolve the connection state once for both Site Health surfaces.
	 *
	 * Precedence matters: a live connection wins, a site with no
	 * identity has never connected, the operator-disconnect gate (see
	 * `is_operator_disconnected()` for the stale-marker rationale) beats
	 * the failure states, and everything else needs a reconnect — the
	 * `reauth_reason` marker carries the specific cause.
	 *
	 * @return string One of `connected`, `never_connected`,
	 *                `disconnected`, `needs_reauth`.
	 */
	private static function connection_state(): string {
		if ( is_connected() ) {
			return 'connected';
		}

		if ( ! has_identity() ) {
			return 'never_connected';
		}

		if ( is_operator_disconnected() ) {
			return 'disconnected';
		}

		return 'needs_reauth';
	}

	/**
	 * Build the cause-specific description for the needs-reauth state.
	 *
	 * @return string HTML paragraphs.
	 */
	private static function reauth_description(): string {
		/*
		 * The cause sentence is shared with the admin reconnect notice
		 * via `reauth_reason_lead()`; only the action tail is owned here.
		 */
		$description = \sprintf(
			'<p>%s %s</p>',
			reauth_reason_lead(),
			\__( 'Reconnect your Bluesky account on the settings page to resume sharing.', 'atmosphere' )
		);

		if ( Client::REAUTH_REASON_KEY_CHANGED !== get_reauth_reason() ) {
			return $description;
		}

		if ( Encryption::has_dedicated_key() ) {
			$description .= \sprintf(
				'<p>%s</p>',
				\__( 'A dedicated ATmosphere encryption key is defined, but its value appears to have changed. Restore the previous value, or reconnect to save a new login under the current one.', 'atmosphere' )
			);
		} else {
			/*
			 * Generate a real, ready-to-paste key (same recipe as
			 * WordPress's own secret-key service) instead of a
			 * placeholder the user has to know how to replace. A
			 * fresh value is generated per render and never stored
			 * — only the copy the user pastes into wp-config.php
			 * matters. `wp_generate_password()`'s character set
			 * contains no quotes or backslashes, so the value is
			 * safe inside a single-quoted PHP string literal.
			 */
			$description .= \sprintf(
				'<p>%s</p><p><code>%s</code></p>',
				\__( 'If your security keys are rotated regularly, add a dedicated key for ATmosphere to your wp-config.php before reconnecting, so the connection survives future rotations. Copy this freshly generated line as-is, and never change it afterwards:', 'atmosphere' ),
				\esc_html( "define( 'ATMOSPHERE_ENCRYPTION_KEY', '" . \wp_generate_password( 64, true, true ) . "' );" )
			);
		}

		return $description;
	}

	/**
	 * Add the ATmosphere section to the Site Health debug information.
	 *
	 * Everything here is deliberately non-sensitive: DID, handle, and
	 * PDS endpoint are public AT Protocol data, and the encryption key
	 * *source* is reported without any key material. Users routinely
	 * paste this panel into support threads.
	 *
	 * @param array $info Debug information sections.
	 * @return array
	 */
	public static function debug_information( $info ) {
		$identity = get_identity();

		$info['atmosphere'] = array(
			'label'  => \__( 'ATmosphere', 'atmosphere' ),
			'fields' => array(
				'version'           => array(
					'label'   => \__( 'Plugin Version', 'atmosphere' ),
					'value'   => ATMOSPHERE_VERSION,
					'private' => false,
				),
				'connection_status' => array(
					'label'   => \__( 'Connection Status', 'atmosphere' ),
					'value'   => self::connection_status(),
					'private' => false,
				),
				'handle'            => array(
					'label'   => \__( 'Handle', 'atmosphere' ),
					'value'   => (string) ( $identity['handle'] ?? '' ),
					'private' => false,
				),
				'did'               => array(
					'label'   => \__( 'DID', 'atmosphere' ),
					'value'   => get_did(),
					'private' => false,
				),
				'pds_endpoint'      => array(
					'label'   => \__( 'PDS Endpoint', 'atmosphere' ),
					'value'   => get_pds_endpoint(),
					'private' => false,
				),
				'encryption_key'    => array(
					'label'   => \__( 'Encryption Key Source', 'atmosphere' ),
					'value'   => Encryption::has_dedicated_key()
						? \__( 'Dedicated key (ATMOSPHERE_ENCRYPTION_KEY)', 'atmosphere' )
						: \__( 'WordPress security keys (AUTH_KEY/AUTH_SALT)', 'atmosphere' ),
					'private' => false,
				),
				'auto_publish'      => array(
					'label'   => \__( 'Auto-Publish', 'atmosphere' ),
					'value'   => self::auto_publish_debug_value(),
					'private' => false,
				),
				'post_types'        => array(
					'label'   => \__( 'Supported Post Types', 'atmosphere' ),
					'value'   => \implode( ', ', get_supported_post_types() ),
					'private' => false,
				),
				'last_refresh'      => array(
					'label'   => \__( 'Last Login Renewal', 'atmosphere' ),
					'value'   => self::last_refresh_debug_value(),
					'private' => false,
				),
			),
		);

		return $info;
	}

	/**
	 * Login-renewal history for the debug panel.
	 *
	 * ATmosphere renews its saved Bluesky login in the background. When a
	 * site keeps losing its connection, the useful question is whether that
	 * renewal is running at all, and if it ran, what the answer was. Both
	 * halves are reported, since "never renewed on a site connected for
	 * weeks" and "renewed fine until it was rejected" call for opposite
	 * advice.
	 *
	 * @since unreleased
	 *
	 * @return string
	 */
	private static function last_refresh_debug_value(): string {
		$status = \get_option( Client::REFRESH_STATUS_OPTION, array() );

		if ( ! \is_array( $status ) ) {
			$status = array();
		}

		if ( empty( $status['last_success'] ) ) {
			$success = \__( 'never', 'atmosphere' );
		} else {
			$success = \sprintf(
				/* translators: %s: human-readable time difference, e.g. "2 hours". */
				\__( '%s ago', 'atmosphere' ),
				\human_time_diff( (int) $status['last_success'] )
			);
		}

		if ( empty( $status['last_failure'] ) ) {
			return $success;
		}

		return \sprintf(
			/* translators: 1: when the last renewal succeeded, 2: how long ago the last one failed, 3: the error reported. */
			\__( '%1$s (last failure %2$s ago: %3$s)', 'atmosphere' ),
			$success,
			\human_time_diff( (int) $status['last_failure'] ),
			(string) ( $status['last_error'] ?? \__( 'unknown', 'atmosphere' ) )
		);
	}

	/**
	 * Auto-publish state for the debug panel, stored setting *and* effective.
	 *
	 * Report both so a support thread can see the user's actual saved preference
	 * even when connection-only mode or the `atmosphere_should_auto_publish`
	 * filter overrides it — the stored-vs-effective discrepancy is exactly what
	 * such a thread needs to diagnose.
	 *
	 * @return string
	 */
	private static function auto_publish_debug_value(): string {
		$stored_on = '1' === (string) \get_option( 'atmosphere_auto_publish', '1' );
		$effective = is_auto_publish_enabled();

		if ( $stored_on === $effective ) {
			return $effective
				? \__( 'Enabled', 'atmosphere' )
				: \__( 'Disabled', 'atmosphere' );
		}

		return $effective
			? \__( 'Disabled in settings, overridden on by another plugin', 'atmosphere' )
			: \__( 'Enabled in settings, overridden off by another plugin', 'atmosphere' );
	}

	/**
	 * Human-readable connection status for the debug panel.
	 *
	 * @return string
	 */
	private static function connection_status(): string {
		switch ( self::connection_state() ) {
			case 'connected':
				return \__( 'Connected', 'atmosphere' );
			case 'never_connected':
				return \__( 'Not connected', 'atmosphere' );
			case 'disconnected':
				return \__( 'Disconnected by the user', 'atmosphere' );
		}

		switch ( get_reauth_reason() ) {
			case Client::REAUTH_REASON_KEY_CHANGED:
				return \__( 'Needs reconnect (security keys changed)', 'atmosphere' );
			case Client::REAUTH_REASON_DECRYPT_FAILED:
				return \__( 'Needs reconnect (saved login unreadable)', 'atmosphere' );
			default:
				return \__( 'Needs reconnect (session expired)', 'atmosphere' );
		}
	}
}
