<?php
/**
 * Tests for the block-editor script data.
 *
 * @package Atmosphere
 * @group atmosphere
 */

namespace Atmosphere\Tests;

use Atmosphere\Block_Editor;
use Atmosphere\Connectors;
use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\DPoP;
use Atmosphere\OAuth\Encryption;
use function Atmosphere\settings_url;
use const Atmosphere\SESSION_VERIFIED_TRANSIENT;

/**
 * Tests for the config the editor scripts receive as `window.atmosphereEditor`.
 *
 * @coversDefaultClass \Atmosphere\Block_Editor
 */
class Test_Block_Editor extends \WP_UnitTestCase {

	/**
	 * Reset connection state between tests.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_auto_publish' );
		\delete_option( Client::DISCONNECTED_OPTION );
		\delete_option( 'atmosphere_auto_publish' );
		\remove_all_filters( 'atmosphere_should_auto_publish' );
		\remove_all_filters( 'atmosphere_connection_only_mode' );
		\remove_all_filters( 'pre_http_request' );
		\delete_transient( SESSION_VERIFIED_TRANSIENT );
		parent::tear_down();
	}

	/**
	 * Invoke the private config builder.
	 *
	 * @return array The localized editor config.
	 */
	private function script_data(): array {
		$method = new \ReflectionMethod( Block_Editor::class, 'script_data' );

		return $method->invoke( null );
	}

	/**
	 * Store an identity plus a connection row flagged for reauth.
	 *
	 * @param string $reason The recorded reauth reason, if any.
	 */
	private function flag_connection_for_reauth( string $reason = '' ): void {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\update_option(
			'atmosphere_connection',
			array(
				'did'           => 'did:plc:test123',
				'access_token'  => 'test-token',
				'needs_reauth'  => true,
				'reauth_reason' => $reason,
			)
		);
	}

	/**
	 * Log in as an administrator.
	 */
	private function login_as_admin(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * A never-connected site gets no reconnect prompt at all.
	 *
	 * @covers ::script_data
	 */
	public function test_never_connected_site_needs_no_reauth() {
		$this->login_as_admin();

		$data = $this->script_data();

		/*
		 * Never connected is a setup step, not a problem: nothing to warn
		 * about, so no message. But the site cannot share either, and the
		 * toggle's help text has to hedge rather than promise a share the
		 * pre-publish panel would then deny.
		 */
		$this->assertSame( '', $data['shareStatus']['message'] );
		$this->assertFalse( $data['shareStatus']['can_share'] );
		$this->assertTrue( $data['shareStatus']['sharing_enabled'] );
	}

	/**
	 * A flagged connection reports the plain expiry sentence to an admin.
	 *
	 * @covers ::script_data
	 */
	public function test_flagged_connection_reports_expiry_lead_to_admin() {
		$this->login_as_admin();
		$this->flag_connection_for_reauth();

		$data = $this->script_data();

		$this->assertFalse( $data['shareStatus']['can_share'] );
		$this->assertSame( 'Your Bluesky session has expired.', $data['shareStatus']['message'] );
	}

	/**
	 * The recorded cause reaches an admin, so the key-rotation case is
	 * explained rather than mislabelled as a plain expiry.
	 *
	 * @covers ::script_data
	 */
	public function test_key_changed_reason_reaches_admin() {
		$this->login_as_admin();
		$this->flag_connection_for_reauth( Client::REAUTH_REASON_KEY_CHANGED );

		$this->assertStringContainsString( 'security keys', $this->script_data()['shareStatus']['message'] );
	}

	/**
	 * A user without `manage_options` gets a generic lead: the key-rotation
	 * explanation is meaningless to someone who cannot act on it.
	 *
	 * @covers ::script_data
	 */
	public function test_author_gets_generic_lead() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->flag_connection_for_reauth( Client::REAUTH_REASON_KEY_CHANGED );

		$data = $this->script_data();

		$this->assertFalse( $data['shareStatus']['can_share'] );
		$this->assertFalse( $data['canManage'] );
		$this->assertSame( 'Your site’s Bluesky connection needs attention.', $data['shareStatus']['message'] );
	}

	/**
	 * An operator-initiated disconnect must not claim a session expired.
	 *
	 * @covers ::script_data
	 */
	public function test_operator_disconnect_uses_disconnected_lead() {
		$this->login_as_admin();
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\delete_option( 'atmosphere_connection' );
		\update_option( Client::DISCONNECTED_OPTION, true );

		$data = $this->script_data();

		$this->assertFalse( $data['shareStatus']['can_share'] );
		$this->assertSame( 'ATmosphere is disconnected from Bluesky.', $data['shareStatus']['message'] );
	}

	/**
	 * A dead connection is a site-level problem: it still needs a reconnect
	 * warning even when auto-publish is off, since other behaviors (reaction
	 * sync, comment publishing, a host plugin's own use of the connection)
	 * still depend on it.
	 *
	 * @covers ::script_data
	 */
	public function test_needs_reauth_true_when_auto_publish_off() {
		$this->login_as_admin();
		$this->flag_connection_for_reauth();
		\update_option( 'atmosphere_auto_publish', '0' );

		$data = $this->script_data();

		$this->assertFalse( $data['shareStatus']['can_share'] );
	}

	/**
	 * `needsReauth` reports the raw connection state, with no suppression:
	 * it stays true for a non-admin on an operator-initiated disconnect so
	 * `shareHelpText()` can still hedge. Only `reauthLead` (which drives the
	 * banner) is suppressed, since an operator-initiated disconnect is a
	 * state the administrator chose, not a cause every author needs to see.
	 *
	 * @covers ::script_data
	 */
	public function test_author_gets_no_reauth_lead_for_operator_disconnect() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\delete_option( 'atmosphere_connection' );
		\update_option( Client::DISCONNECTED_OPTION, true );

		$data = $this->script_data();

		$this->assertFalse( $data['shareStatus']['can_share'] );
		$this->assertSame( '', $data['shareStatus']['message'] );
	}

	/**
	 * The reconnect link points at the settings page while it's visible.
	 *
	 * @covers ::script_data
	 */
	public function test_reconnect_url_points_to_settings_page_when_visible() {
		$this->login_as_admin();

		$data = $this->script_data();

		$this->assertSame( settings_url(), $data['reconnectUrl'] );
	}

	/**
	 * When the settings page is hidden (connection-only mode), the reconnect
	 * link falls back to the Connectors screen when one exists, or is empty
	 * when there's nowhere to send the user — matching
	 * {@see \Atmosphere\WP_Admin\Admin::maybe_render_reauth_notice()}.
	 *
	 * @covers ::script_data
	 */
	public function test_reconnect_url_falls_back_when_settings_page_hidden() {
		$this->login_as_admin();
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );

		$data = $this->script_data();

		if ( \class_exists( 'WP_Connector_Registry' ) ) {
			$this->assertSame( Connectors::screen_url(), $data['reconnectUrl'] );
		} else {
			$this->assertSame( '', $data['reconnectUrl'] );
		}
	}

	/**
	 * `autoPublish` mirrors `is_auto_publish_enabled()` so the editor-plugin
	 * script can hide the per-post toggle without gating its own enqueue on
	 * the same setting.
	 *
	 * @covers ::script_data
	 */
	public function test_auto_publish_reflects_the_stored_setting() {
		$this->login_as_admin();

		/*
		 * `sharing_enabled` is the site's policy, which is what decides
		 * whether the per-post controls render. It is deliberately not
		 * `can_share`, which also goes false on a dead connection while the
		 * toggle must stay put.
		 */
		$this->assertTrue( $this->script_data()['shareStatus']['sharing_enabled'] );

		\update_option( 'atmosphere_auto_publish', '0' );

		$this->assertFalse( $this->script_data()['shareStatus']['sharing_enabled'] );
	}

	/**
	 * The document panel describes state that predates auto-publish being
	 * switched off (a shared URL, a still-on-Bluesky removal warning, a
	 * publish error, the per-post toggle a CLI backfill still reads), so
	 * `enqueue()` must keep loading it even with auto-publish off. Only the
	 * toggle and custom-text field hide themselves client-side; the script
	 * itself stays enqueued exactly like the pre-publish panel.
	 *
	 * @covers ::enqueue
	 */
	public function test_enqueue_loads_both_panels_when_auto_publish_disabled() {
		\wp_dequeue_script( 'atmosphere-editor-plugin' );
		\wp_dequeue_script( 'atmosphere-pre-publish-panel' );
		\update_option( 'atmosphere_auto_publish', '0' );

		Block_Editor::enqueue();

		$this->assertTrue( \wp_script_is( 'atmosphere-editor-plugin', 'enqueued' ) );
		$this->assertTrue( \wp_script_is( 'atmosphere-pre-publish-panel', 'enqueued' ) );
	}

	/**
	 * With auto-publish on (the default), `enqueue()` loads both panels.
	 *
	 * @covers ::enqueue
	 */
	public function test_enqueue_loads_both_panels_when_auto_publish_enabled() {
		\wp_dequeue_script( 'atmosphere-editor-plugin' );
		\wp_dequeue_script( 'atmosphere-pre-publish-panel' );

		Block_Editor::enqueue();

		$this->assertTrue( \wp_script_is( 'atmosphere-pre-publish-panel', 'enqueued' ) );

		$this->assertTrue( \wp_script_is( 'atmosphere-editor-plugin', 'enqueued' ) );
	}

	/**
	 * The site owner's own choice is explained, so the missing controls are
	 * not a mystery.
	 */
	public function test_owner_disabled_sharing_is_explained() {
		\update_option( 'atmosphere_auto_publish', '0' );

		$data = $this->script_data();

		$this->assertFalse( $data['shareStatus']['can_share'] );
		$this->assertSame(
			'Automatic publishing to Bluesky is turned off in settings.',
			$data['shareStatus']['message']
		);
	}

	/**
	 * Sharing forced off from outside says nothing: the host plugin owns the
	 * sharing experience there, and narrating the arrangement to an author
	 * who cannot act on it is noise.
	 */
	public function test_externally_disabled_sharing_says_nothing() {
		\update_option( 'atmosphere_auto_publish', '1' );
		\add_filter( 'atmosphere_should_auto_publish', '__return_false' );

		$data = $this->script_data();

		$this->assertFalse( $data['shareStatus']['can_share'] );
		$this->assertSame( '', $data['shareStatus']['message'] );
	}

	/**
	 * Nothing to explain while sharing is on.
	 */
	public function test_enabled_sharing_needs_no_notice() {
		$data = $this->script_data();

		$this->assertTrue( $data['shareStatus']['sharing_enabled'] );
		$this->assertSame( '', $data['shareStatus']['message'] );
	}

	/**
	 * Opening the editor is the earliest useful moment to notice a dead
	 * connection: the author has written nothing yet.
	 *
	 * A session the user revoked from their Bluesky account leaves stored
	 * credentials byte-for-byte identical to a working one, so the banner
	 * would otherwise vouch for it right up until the publish failed. The
	 * probe runs before the share status is resolved, so the prompt is
	 * there on the first paint.
	 */
	public function test_editor_load_catches_a_revoked_session() {
		$dpop_jwk = DPoP::generate_key();

		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\update_option(
			'atmosphere_connection',
			array(
				'did'            => 'did:plc:test123',
				'access_token'   => Encryption::encrypt( 'test-access-token' ),
				'refresh_token'  => Encryption::encrypt( 'test-refresh-token' ),
				'dpop_jwk'       => Encryption::encrypt( \wp_json_encode( $dpop_jwk ) ),
				'pds_endpoint'   => 'https://pds.example.com',
				'token_endpoint' => 'https://auth.example.com/oauth/token',
				'expires_at'     => \time() + 3600,
				'needs_reauth'   => false,
			)
		);
		\update_option( 'atmosphere_auto_publish', '1' );

		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				if ( false !== \strpos( $url, 'oauth/token' ) ) {
					return array(
						'response' => array( 'code' => 400 ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => \wp_json_encode( array( 'error' => 'invalid_grant' ) ),
					);
				}

				if ( false !== \strpos( $url, 'getSession' ) ) {
					return array(
						'response' => array( 'code' => 401 ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => \wp_json_encode( array( 'error' => 'InvalidToken' ) ),
					);
				}

				return $response;
			},
			1,
			3
		);

		$data = $this->script_data();

		$this->assertSame(
			'needs_reconnect',
			$data['shareStatus']['state'],
			'A revoked session must surface as a reconnect prompt on editor load.'
		);
		$this->assertFalse(
			$data['shareStatus']['can_share'],
			'A revoked session must not be advertised as ready to share.'
		);
		$this->assertNotEmpty(
			$data['shareStatus']['reason'],
			'The banner needs a reason to render the reconnect prompt from.'
		);
	}

	/**
	 * The probe must not hold up the editor when the PDS is unreachable.
	 *
	 * Failing open here matters more than anywhere else: this runs during
	 * `enqueue_block_editor_assets`, so treating a timeout as a
	 * disconnection would greet an author with a reconnect prompt every
	 * time their PDS had a bad minute.
	 */
	public function test_editor_load_ignores_an_unreachable_pds() {
		$dpop_jwk = DPoP::generate_key();

		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\update_option(
			'atmosphere_connection',
			array(
				'did'            => 'did:plc:test123',
				'access_token'   => Encryption::encrypt( 'test-access-token' ),
				'refresh_token'  => Encryption::encrypt( 'test-refresh-token' ),
				'dpop_jwk'       => Encryption::encrypt( \wp_json_encode( $dpop_jwk ) ),
				'pds_endpoint'   => 'https://pds.example.com',
				'token_endpoint' => 'https://auth.example.com/oauth/token',
				'expires_at'     => \time() + 3600,
				'needs_reauth'   => false,
			)
		);
		\update_option( 'atmosphere_auto_publish', '1' );

		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				if ( false !== \strpos( $url, 'getSession' ) ) {
					return new \WP_Error( 'http_request_failed', 'Connection timed out.' );
				}

				return $response;
			},
			1,
			3
		);

		$data = $this->script_data();

		$this->assertSame(
			'ok',
			$data['shareStatus']['state'],
			'An unreachable PDS must not read as a disconnection.'
		);
		$this->assertTrue( $data['shareStatus']['can_share'] );
	}

	/**
	 * The probe waits far less than the shared request timeout.
	 *
	 * This one runs while the editor renders, so the ceiling is the
	 * difference between a slow load and a frozen one.
	 */
	public function test_editor_load_probe_uses_a_short_timeout() {
		$dpop_jwk = DPoP::generate_key();

		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\update_option(
			'atmosphere_connection',
			array(
				'did'            => 'did:plc:test123',
				'access_token'   => Encryption::encrypt( 'test-access-token' ),
				'refresh_token'  => Encryption::encrypt( 'test-refresh-token' ),
				'dpop_jwk'       => Encryption::encrypt( \wp_json_encode( $dpop_jwk ) ),
				'pds_endpoint'   => 'https://pds.example.com',
				'token_endpoint' => 'https://auth.example.com/oauth/token',
				'expires_at'     => \time() + 3600,
				'needs_reauth'   => false,
			)
		);

		$captured = null;

		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured ) {
				if ( false !== \strpos( $url, 'getSession' ) ) {
					$captured = $args;

					return array(
						'response' => array( 'code' => 200 ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => \wp_json_encode( array( 'did' => 'did:plc:test123' ) ),
					);
				}

				return $response;
			},
			1,
			3
		);

		$this->script_data();

		$this->assertNotNull( $captured, 'The probe should have reached the transport.' );
		$this->assertLessThanOrEqual( 5, $captured['timeout'], 'The editor must not wait 30 seconds on a probe.' );
	}
}
