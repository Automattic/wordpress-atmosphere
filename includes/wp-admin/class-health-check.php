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
use function Atmosphere\get_identity;
use function Atmosphere\get_reauth_reason;
use function Atmosphere\get_supported_post_types;
use function Atmosphere\has_identity;
use function Atmosphere\is_connected;
use function Atmosphere\is_operator_disconnected;
use function Atmosphere\settings_url;

/**
 * Health check class.
 *
 * @since unreleased
 */
class Health_Check {

	/**
	 * Register the Site Health filters.
	 */
	public static function init(): void {
		\add_filter( 'site_status_tests', array( self::class, 'add_tests' ) );
		\add_filter( 'debug_information', array( self::class, 'debug_information' ) );
	}

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
		$reason = get_reauth_reason();

		if ( Client::REAUTH_REASON_KEY_CHANGED === $reason ) {
			$description = \sprintf(
				'<p>%s</p>',
				\__( 'Your site’s security keys have changed — this can happen after a migration, or when a security plugin rotates them on a schedule — so ATmosphere can no longer read its saved Bluesky login. Reconnect your Bluesky account on the settings page.', 'atmosphere' )
			);

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

		if ( Client::REAUTH_REASON_DECRYPT_FAILED === $reason ) {
			return \sprintf(
				'<p>%s</p>',
				\__( 'ATmosphere can no longer read its saved Bluesky login. Reconnect your Bluesky account on the settings page to resume sharing.', 'atmosphere' )
			);
		}

		return \sprintf(
			'<p>%s</p>',
			\__( 'Your Bluesky session has expired. Reconnect your Bluesky account on the settings page to resume sharing.', 'atmosphere' )
		);
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
					'value'   => (string) ( $identity['did'] ?? '' ),
					'private' => false,
				),
				'pds_endpoint'      => array(
					'label'   => \__( 'PDS Endpoint', 'atmosphere' ),
					'value'   => (string) ( $identity['pds_endpoint'] ?? '' ),
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
					'value'   => '1' === \get_option( 'atmosphere_auto_publish', '1' )
						? \__( 'Enabled', 'atmosphere' )
						: \__( 'Disabled', 'atmosphere' ),
					'private' => false,
				),
				'post_types'        => array(
					'label'   => \__( 'Supported Post Types', 'atmosphere' ),
					'value'   => \implode( ', ', get_supported_post_types() ),
					'private' => false,
				),
			),
		);

		return $info;
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
