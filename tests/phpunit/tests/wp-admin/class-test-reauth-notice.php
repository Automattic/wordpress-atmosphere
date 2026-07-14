<?php
/**
 * Tests for `Admin::maybe_render_reauth_notice()` copy swap.
 *
 * The notice fires whenever an identity is on file but the OAuth
 * session is gone (operator clicked Disconnect, refresh token revoked,
 * etc.). The copy must differ between those two cases: a refresh
 * failure says "session has expired"; an operator-initiated disconnect
 * says "disconnected". Misattributing one as the other erodes trust in
 * the surface.
 *
 * The notice gate also requires `current_user_can('manage_options')`,
 * so each test elevates to admin before invoking the render. Notice
 * output goes through `wp_kses` + `esc_html` with a translated string;
 * capturing stdout via an output buffer is sufficient to inspect the
 * heading and body.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\OAuth\Client;
use Atmosphere\WP_Admin\Admin;
use WP_UnitTestCase;

/**
 * Reauth-notice copy-swap tests.
 */
class Test_Reauth_Notice extends WP_UnitTestCase {

	/**
	 * Reset request state and clean options after each test.
	 */
	public function tear_down(): void {
		\wp_set_current_user( 0 );
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( Client::DISCONNECTED_OPTION );

		parent::tear_down();
	}

	/**
	 * Become an administrator (grants `manage_options`).
	 */
	private function become_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );
	}

	/**
	 * Seed the identity row so `needs_reauth()` can return true on the
	 * gate inside `maybe_render_reauth_notice()`.
	 */
	private function seed_identity(): void {
		\update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://pds.example.com',
			),
			true
		);
	}

	/**
	 * Render the notice and capture the HTML it printed.
	 */
	private function capture_notice(): string {
		\ob_start();
		Admin::maybe_render_reauth_notice();
		return (string) \ob_get_clean();
	}

	/**
	 * Operator clicked Disconnect. The marker is set and the connection
	 * row is gone. The notice must report the disconnect framing rather
	 * than the "session expired" framing.
	 */
	public function test_renders_disconnected_copy_after_operator_disconnect(): void {
		$this->become_admin();
		$this->seed_identity();
		\update_option( Client::DISCONNECTED_OPTION, \time(), false );

		$html = $this->capture_notice();

		$this->assertStringContainsString(
			'ATmosphere: disconnected',
			$html,
			'Operator-initiated disconnect must render the "disconnected" heading, not the session-expired heading.'
		);
		$this->assertStringContainsString(
			'ATmosphere is disconnected from Bluesky.',
			$html,
			'Operator-initiated disconnect must render the disconnect body copy.'
		);
		$this->assertStringNotContainsString( 'session has expired', $html );
	}

	/**
	 * Refresh token failed (e.g. revoked at the auth server). The
	 * connection row is kept with `needs_reauth = true` and an emptied
	 * access_token; no disconnect marker is set. The notice must use
	 * the "session has expired" copy so the user understands the cause.
	 */
	public function test_renders_session_expired_copy_after_refresh_failure(): void {
		$this->become_admin();
		$this->seed_identity();
		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test',
				'handle'       => 'example.com',
				'access_token' => '',
				'needs_reauth' => true,
			),
			false
		);

		$html = $this->capture_notice();

		$this->assertStringContainsString(
			'ATmosphere: reconnection required',
			$html,
			'Refresh failure must render the "reconnection required" heading.'
		);
		$this->assertStringContainsString(
			'session has expired',
			$html,
			'Refresh failure must render the session-expired body copy.'
		);
		$this->assertStringNotContainsString( 'ATmosphere: disconnected', $html );
	}

	/**
	 * Stale disconnect marker survived an earlier successful reconnect
	 * (e.g. `handle_callback()`'s `delete_option` lost to an object-cache
	 * hiccup). The connection row is alive but the marker lingers. A
	 * later refresh failure flips `needs_reauth = true` while leaving
	 * the connection row populated. The notice gate must treat the
	 * marker as stale (connection present) and fall through to the
	 * session-expired copy — otherwise the user would be told they
	 * "disconnected" when in fact the auth server revoked the token.
	 */
	public function test_stale_marker_with_live_connection_renders_session_expired(): void {
		$this->become_admin();
		$this->seed_identity();
		\update_option( Client::DISCONNECTED_OPTION, \time() - DAY_IN_SECONDS, false );
		\update_option(
			'atmosphere_connection',
			array(
				'did'           => 'did:plc:test',
				'handle'        => 'example.com',
				'access_token'  => '',
				'refresh_token' => 'still-here',
				'needs_reauth'  => true,
			),
			false
		);

		$html = $this->capture_notice();

		$this->assertStringContainsString(
			'session has expired',
			$html,
			'A stale disconnect marker must not mislabel a refresh failure as an operator-initiated disconnect.'
		);
		$this->assertStringNotContainsString( 'ATmosphere: disconnected', $html );
	}

	/**
	 * Cap gate: a logged-out request must produce no output, even when
	 * the underlying state would otherwise render the notice.
	 */
	public function test_no_output_without_manage_options_cap(): void {
		$this->seed_identity();
		\update_option( Client::DISCONNECTED_OPTION, \time(), false );

		$html = $this->capture_notice();

		$this->assertSame( '', $html );
	}

	/**
	 * Legacy state from installs that disconnected before the marker
	 * was added (1.1.0 and earlier): identity preserved, connection row
	 * gone, no marker. The gate must fall through to the session-expired
	 * copy via `$disconnected = false && empty(...)` so existing
	 * disconnected installs still get a notice on upgrade.
	 */
	public function test_renders_session_expired_copy_for_legacy_disconnect_without_marker(): void {
		$this->become_admin();
		$this->seed_identity();
		// No atmosphere_connection row; no DISCONNECTED_OPTION row.

		$html = $this->capture_notice();

		$this->assertStringContainsString(
			'ATmosphere: reconnection required',
			$html,
			'Legacy disconnected installs (no marker) must still render a notice — the gate falls through to the session-expired copy.'
		);
		$this->assertStringNotContainsString( 'ATmosphere: disconnected', $html );
	}

	/**
	 * Defensive: marker present but identity absent. `needs_reauth()`
	 * short-circuits on `has_identity()` and the notice must produce
	 * no output regardless of the marker. A regression that moved the
	 * marker read above the needs_reauth gate (or rewrote needs_reauth
	 * to no longer require identity) would surface a notice on a fresh
	 * install where the marker somehow leaked in (partial uninstall,
	 * manual option insertion, downgrade-then-upgrade).
	 */
	public function test_no_output_when_marker_present_but_identity_absent(): void {
		$this->become_admin();
		// No atmosphere_identity row.
		\update_option( Client::DISCONNECTED_OPTION, \time(), false );

		$html = $this->capture_notice();

		$this->assertSame( '', $html );
	}

	/**
	 * Healthy session: no notice should render at all.
	 */
	public function test_no_output_when_connection_is_healthy(): void {
		$this->become_admin();
		$this->seed_identity();
		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test',
				'handle'       => 'example.com',
				'access_token' => 'live-token',
				'needs_reauth' => false,
			),
			false
		);

		$html = $this->capture_notice();

		$this->assertSame( '', $html );
	}
}
