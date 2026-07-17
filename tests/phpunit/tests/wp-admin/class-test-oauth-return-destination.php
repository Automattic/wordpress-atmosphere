<?php
/**
 * Tests for the OAuth callback return-destination resolver.
 *
 * Pins the routing added for the Connectors card: after the OAuth callback the
 * browser must return to wherever the connect flow started — the core
 * Settings → Connectors screen for a Connectors-card connect, otherwise the
 * plugin's own settings page. The origin travels inside the flow's own resolved
 * record ({@see \Atmosphere\OAuth\Client::pending_origin()}), so it can't be
 * clobbered by a second flow the way a separate site-wide flag could.
 *
 * `handle_oauth_callback()` itself reads the request through
 * `filter_input( INPUT_GET, ... )`, which is not populated in the PHPUnit CLI
 * request, so the resolver is exercised directly.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group connectors
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\Connectors;
use Atmosphere\OAuth\Client;
use Atmosphere\WP_Admin\Admin;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * OAuth return-destination resolver tests.
 *
 * @coversDefaultClass \Atmosphere\WP_Admin\Admin
 */
class Test_OAuth_Return_Destination extends WP_UnitTestCase {

	/**
	 * Admin-menu `$submenu` global, snapshotted before it is overwritten.
	 *
	 * @var mixed
	 */
	private $submenu_snapshot;

	/**
	 * Simulate the Gutenberg plugin's Connectors submenu so the "returns to the
	 * Connectors screen" cases resolve to a real screen URL.
	 *
	 * On WP < 7.0 (this test environment) there is no top-level
	 * `options-connectors.php`, so {@see Connectors::screen_url()} only routes to
	 * a Connectors screen when that submenu is registered — which is exactly the
	 * host-plugin embedding scenario these tests model.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->submenu_snapshot = $GLOBALS['submenu'] ?? null;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture; restored in tear_down().
		$GLOBALS['submenu'] = array(
			'options-general.php' => array(
				array( 'Settings', 'manage_options', 'options-general.php' ),
				array( 'Connectors', 'manage_options', 'options-connectors-wp-admin' ),
			),
		);
	}

	/**
	 * Restore the menu global and clean up the origin flag between tests.
	 */
	public function tear_down(): void {
		if ( null === $this->submenu_snapshot ) {
			unset( $GLOBALS['submenu'] );
		} else {
			$GLOBALS['submenu'] = $this->submenu_snapshot; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the snapshot from set_up().
		}

		\delete_transient( 'atmosphere_oauth_resolved' );
		\delete_transient( 'atmosphere_oauth_notice_' . \get_current_user_id() );
		\wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The Connectors screen URL these tests expect while the Gutenberg submenu
	 * fixture is installed.
	 *
	 * @return string
	 */
	private function connectors_screen_url(): string {
		return \admin_url( 'options-general.php?page=options-connectors-wp-admin' );
	}

	/**
	 * Invoke the private resolver for a given flow origin.
	 *
	 * @param string $origin Flow origin ('connectors' or 'settings').
	 * @return string The resolved destination URL.
	 */
	private function resolve( string $origin ): string {
		$method = new ReflectionMethod( Admin::class, 'oauth_return_destination' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $origin );
	}

	/**
	 * A Connectors-origin flow returns the browser to the Connectors screen.
	 *
	 * @covers ::oauth_return_destination
	 */
	public function test_connectors_origin_returns_to_connectors_screen(): void {
		$this->assertSame( $this->connectors_screen_url(), $this->resolve( 'connectors' ) );
	}

	/**
	 * A settings-origin flow returns the browser to the plugin's settings page.
	 *
	 * @covers ::oauth_return_destination
	 */
	public function test_settings_origin_returns_to_settings_page(): void {
		$this->assertSame( \admin_url( 'options-general.php?page=atmosphere' ), $this->resolve( 'settings' ) );
	}

	/**
	 * The origin lives in the OAuth flow's own resolved record, so it can't be
	 * clobbered by a second flow the way a separate site-wide flag could.
	 * `pending_origin()` defaults to settings when no flow is in progress.
	 *
	 * @covers \Atmosphere\OAuth\Client::pending_origin
	 */
	public function test_pending_origin_reads_the_flow_record(): void {
		$this->assertSame( 'settings', Client::pending_origin() );

		\set_transient(
			'atmosphere_oauth_resolved',
			array(
				'did'    => 'did:plc:test',
				'handle' => 'alice.example.com',
				'origin' => 'connectors',
			),
			HOUR_IN_SECONDS
		);
		$this->assertSame( 'connectors', Client::pending_origin() );

		\delete_transient( 'atmosphere_oauth_resolved' );
	}

	/**
	 * A stored OAuth-callback notice renders once, on `admin_notices`, and is
	 * consumed — the fix for the failure branch that was previously invisible
	 * because the `settings_errors` transient never surfaced on the redirect
	 * destination.
	 *
	 * @covers ::maybe_render_oauth_notice
	 */
	public function test_oauth_notice_renders_once_and_is_consumed(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Keyed per user so a second admin can't consume this one's message.
		$key = 'atmosphere_oauth_notice_' . \get_current_user_id();
		\set_transient(
			$key,
			array(
				'type'    => 'error',
				'message' => 'Handle resolution failed.',
			),
			MINUTE_IN_SECONDS
		);

		\ob_start();
		Admin::maybe_render_oauth_notice();
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Handle resolution failed.', $html );
		$this->assertFalse(
			\get_transient( $key ),
			'The notice must be consumed so it renders exactly once.'
		);

		\ob_start();
		Admin::maybe_render_oauth_notice();
		$this->assertSame( '', (string) \ob_get_clean(), 'A consumed notice must not render again.' );
	}

	/**
	 * The notice is gated on `manage_options`: an unprivileged user never sees it
	 * and, crucially, does not consume it.
	 *
	 * @covers ::maybe_render_oauth_notice
	 */
	public function test_oauth_notice_requires_manage_options(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$key = 'atmosphere_oauth_notice_' . \get_current_user_id();
		\set_transient(
			$key,
			array(
				'type'    => 'success',
				'message' => 'Connected.',
			),
			MINUTE_IN_SECONDS
		);

		\ob_start();
		Admin::maybe_render_oauth_notice();
		$this->assertSame( '', (string) \ob_get_clean() );
		$this->assertNotFalse(
			\get_transient( $key ),
			'An unprivileged view must not consume the notice.'
		);
	}
}
