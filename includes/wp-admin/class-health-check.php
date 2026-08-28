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
use Atmosphere\Rest\Admin\Health_Check_Controller;
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
use function Atmosphere\truncate_text;

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
	 * The one test that does make a request is registered as async for
	 * the same reason.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public static function add_tests( $tests ) {
		$tests['direct']['atmosphere_test_connection'] = array(
			'label' => \__( 'ATmosphere Bluesky Connection Test', 'atmosphere' ),
			'test'  => array( self::class, 'test_connection' ),
		);

		/*
		 * Asynchronous, like every core test that makes an HTTP request,
		 * so the loopback never holds up the Site Health screen. The
		 * driver route sits in core's namespace; the controller says why.
		 * The cron run keeps only the pass/fail counters, not the
		 * description that makes this test useful, so it is skipped.
		 */
		$tests['async']['atmosphere_test_client_metadata'] = array(
			'label'             => \__( 'ATmosphere Bluesky Reachability Test', 'atmosphere' ),
			'test'              => \rest_url( Health_Check_Controller::ROUTE_NAMESPACE . Health_Check_Controller::CLIENT_METADATA_ROUTE ),
			'has_rest'          => true,
			'async_direct_test' => array( self::class, 'test_client_metadata' ),
			'skip_cron'         => true,
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
	 * Client-metadata reachability test.
	 *
	 * The OAuth `client_id` is a URL on this site, and the auth server
	 * fetches it while the user connects. If that fetch fails, connecting
	 * cannot work, and the only symptom is the auth server's own error
	 * text, which reads like a Bluesky problem. A plugin that restricts
	 * the REST API to an allow list is the usual cause, and core's own
	 * REST test does not catch it because it only fetches `wp/v2`.
	 *
	 * Runs asynchronously, driven by {@see Health_Check_Controller}, so
	 * the loopback never blocks the Site Health screen. The driver route
	 * is in core's namespace so that a REST allow list does not stop the
	 * test itself from running.
	 *
	 * Unlike core's loopback, this one sends no cookies and no HTTP auth
	 * credentials. The auth server has none either, so the request has
	 * to look the way its request does. For the same reason the
	 * certificate is validated first: the auth server validates it too,
	 * so a site whose certificate cannot be validated must not pass. Only
	 * when validation is the sole thing in the way is the fetch retried
	 * with core's loopback setting, and reported as recommended rather
	 * than critical, since split-horizon setups can fail validation from
	 * the server itself while being perfectly valid from outside.
	 *
	 * @since unreleased
	 *
	 * @return array Site Health test result.
	 */
	public static function test_client_metadata(): array {
		$url = Client::client_id();

		$result = array(
			'label'       => \__( 'Bluesky can reach ATmosphere on this site', 'atmosphere' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => \__( 'ATmosphere', 'atmosphere' ),
				'color' => 'green',
			),
			'description' => \sprintf(
				'<p>%s</p>',
				\__( 'When you connect, Bluesky reads a small file from this site to identify it. That file is available.', 'atmosphere' )
			),
			'actions'     => '',
			'test'        => 'atmosphere_test_client_metadata',
		);

		/*
		 * Bluesky only accepts an https client_id, so `client_id()` forces
		 * that scheme. On a site served over plain http the auth server
		 * cannot fetch it, and neither could this loopback. Say that,
		 * rather than reporting a connection error against a URL that
		 * was never going to answer.
		 */
		if ( 'https' !== \wp_parse_url( \home_url(), \PHP_URL_SCHEME ) ) {
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']          = \__( 'Bluesky cannot reach ATmosphere on this site', 'atmosphere' );
			$result['description']    = \sprintf(
				'<p>%s</p>',
				\esc_html__( 'Bluesky requires your site to use HTTPS before it can connect. Once your site is served over HTTPS, connect again.', 'atmosphere' )
			);

			return $result;
		}

		$args = array(
			'timeout'             => 10, // Same as core's own REST loopback; async, so nothing waits on it.
			// The endpoint advertises a five minute public cache; a cached
			// answer would hide a block that was just added, or just lifted.
			'headers'             => array( 'Cache-Control' => 'no-cache' ),
			// The excerpt shows 200 characters; there is no reason to buffer
			// a whole error page to find them.
			'limit_response_size' => 8 * KB_IN_BYTES,
		);

		$response = \wp_remote_get( $url, $args + array( 'sslverify' => true ) );
		$problem  = self::client_metadata_problem( $response, $url );

		if ( '' === $problem ) {
			return $result;
		}

		/*
		 * A transport error with validation on may be nothing but the
		 * certificate. The retry differs in that one setting only, so if
		 * it succeeds, validation was the whole problem.
		 */
		if ( \is_wp_error( $response ) ) {
			$retry = \wp_remote_get(
				$url,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				$args + array( 'sslverify' => \apply_filters( 'https_local_ssl_verify', false, $url ) )
			);

			if ( '' === self::client_metadata_problem( $retry, $url ) ) {
				$result['status']         = 'recommended';
				$result['badge']['color'] = 'orange';
				$result['label']          = \__( 'ATmosphere could not validate this site\'s certificate', 'atmosphere' );
				$result['description']    = \sprintf(
					'<p>%1$s</p><p>%2$s</p><p><code>%3$s</code></p>',
					\sprintf(
						/* translators: %s: the client metadata URL. */
						\esc_html__( 'When you connect, Bluesky fetches %s from this site and checks its certificate. From this server the certificate could not be validated. If it is valid for visitors, Bluesky will accept it and you can ignore this.', 'atmosphere' ),
						'<code>' . \esc_html( $url ) . '</code>'
					),
					\esc_html__( 'The validation error was:', 'atmosphere' ),
					\esc_html( $problem )
				);

				return $result;
			}
		}

		$result['status']         = 'critical';
		$result['badge']['color'] = 'red';
		$result['label']          = \__( 'Bluesky cannot reach ATmosphere on this site', 'atmosphere' );
		$result['description']    = \sprintf(
			'<p>%1$s</p><p>%2$s</p><p><code>%3$s</code></p><p>%4$s</p>',
			\sprintf(
				/* translators: %s: the client metadata URL. */
				\esc_html__( 'When you connect, Bluesky fetches %s from this site to identify it. That request fails, so connecting cannot work.', 'atmosphere' ),
				'<code>' . \esc_html( $url ) . '</code>'
			),
			\esc_html__( 'If a security plugin limits which plugins may use the REST API, allow ATmosphere. The response was:', 'atmosphere' ),
			\esc_html( $problem ),
			\esc_html__( 'Posts you have already shared are not affected.', 'atmosphere' )
		);

		return $result;
	}

	/**
	 * Describe what is wrong with a client-metadata response, or '' when nothing is.
	 *
	 * @since unreleased
	 *
	 * @param array|\WP_Error $response Result of the loopback request.
	 * @param string          $url      The client metadata URL that was fetched.
	 * @return string Human-readable problem, empty when the response is what the auth server needs.
	 */
	private static function client_metadata_problem( array|\WP_Error $response, string $url ): string {
		if ( \is_wp_error( $response ) ) {
			return \sprintf( '(%1$s) %2$s', $response->get_error_code(), $response->get_error_message() );
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );
		$body   = (string) \wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			return \sprintf(
				'(%1$d) %2$s %3$s',
				$status,
				self::body_excerpt( (string) \wp_remote_retrieve_response_message( $response ) ),
				self::body_excerpt( $body )
			);
		}

		$json = \json_decode( $body, true );

		if ( ! \is_array( $json ) || ( $json['client_id'] ?? '' ) !== $url ) {
			return \sprintf( '(200) %s', self::body_excerpt( $body ) );
		}

		return '';
	}

	/**
	 * The start of a response body, enough to recognise an error page.
	 *
	 * Core's REST test shows only the status line. This one shows a bit
	 * of the body on purpose: a REST restricting plugin's block page
	 * names itself there, and Site Health output is what people paste
	 * into support threads, so that is where the cause has to be legible.
	 * The audience is users who can install plugins, who can read the
	 * page directly anyway.
	 *
	 * @since unreleased
	 *
	 * @param string $body Raw body.
	 * @return string
	 */
	private static function body_excerpt( string $body ): string {
		$text = \trim( (string) \preg_replace( '/\s+/', ' ', \wp_strip_all_tags( $body ) ) );

		return truncate_text( $text, 200, '…' );
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
			),
		);

		return $info;
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
