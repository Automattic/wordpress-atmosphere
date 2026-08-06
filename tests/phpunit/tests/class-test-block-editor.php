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
use function Atmosphere\settings_url;

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

		$this->assertFalse( $data['needsReauth'] );
		$this->assertSame( '', $data['reauthLead'] );
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

		$this->assertTrue( $data['needsReauth'] );
		$this->assertSame( 'Your Bluesky session has expired.', $data['reauthLead'] );
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

		$this->assertStringContainsString( 'security keys', $this->script_data()['reauthLead'] );
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

		$this->assertTrue( $data['needsReauth'] );
		$this->assertFalse( $data['canManage'] );
		$this->assertSame( 'Your site’s Bluesky connection needs attention.', $data['reauthLead'] );
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

		$this->assertTrue( $data['needsReauth'] );
		$this->assertSame( 'ATmosphere is disconnected from Bluesky.', $data['reauthLead'] );
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

		$this->assertTrue( $data['needsReauth'] );
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

		$this->assertTrue( $data['needsReauth'] );
		$this->assertSame( '', $data['reauthLead'] );
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

		$this->assertTrue( $this->script_data()['autoPublish'] );

		\update_option( 'atmosphere_auto_publish', '0' );

		$this->assertFalse( $this->script_data()['autoPublish'] );
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

		$this->assertFalse( $data['autoPublish'] );
		$this->assertSame(
			'Automatic publishing to Bluesky is turned off in settings.',
			$data['autoPublishNotice']
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

		$this->assertFalse( $data['autoPublish'] );
		$this->assertSame( '', $data['autoPublishNotice'] );
	}

	/**
	 * Nothing to explain while sharing is on.
	 */
	public function test_enabled_sharing_needs_no_notice() {
		$data = $this->script_data();

		$this->assertTrue( $data['autoPublish'] );
		$this->assertSame( '', $data['autoPublishNotice'] );
	}
}
