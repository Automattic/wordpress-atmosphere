<?php
/**
 * Tests for the OAuth callback return-destination resolver.
 *
 * Pins the routing added for the Connectors card: after the OAuth callback the
 * browser must return to wherever the connect flow started — the core
 * Settings → Connectors screen when a Connectors-card connect set the
 * `atmosphere_oauth_from_connectors` flag, otherwise the plugin's own settings
 * page. Either way the flag must be consumed so it can't survive its TTL and
 * steer a later, unrelated connect. The outcome-independent consumption is what
 * keeps a *failed* callback from stranding the admin on a hidden settings page.
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

		\delete_transient( 'atmosphere_oauth_from_connectors' );
		\delete_transient( 'atmosphere_oauth_notice' );
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
	 * Invoke the private resolver.
	 *
	 * @return string The resolved destination URL.
	 */
	private function resolve(): string {
		$method = new ReflectionMethod( Admin::class, 'oauth_return_destination' );
		$method->setAccessible( true );

		return (string) $method->invoke( null );
	}

	/**
	 * With the Connectors-origin flag set, the browser returns to the
	 * Connectors screen and the flag is consumed.
	 *
	 * @covers ::oauth_return_destination
	 */
	public function test_returns_to_connectors_screen_and_consumes_flag(): void {
		\set_transient( 'atmosphere_oauth_from_connectors', 1, HOUR_IN_SECONDS );

		$destination = $this->resolve();

		$this->assertSame( $this->connectors_screen_url(), $destination );
		$this->assertFalse(
			\get_transient( 'atmosphere_oauth_from_connectors' ),
			'The origin flag must be consumed so it cannot leak into a later connect.'
		);
	}

	/**
	 * Without the flag, the browser returns to the plugin's settings page.
	 *
	 * @covers ::oauth_return_destination
	 */
	public function test_returns_to_settings_page_without_flag(): void {
		$destination = $this->resolve();

		$this->assertSame( \admin_url( 'options-general.php?page=atmosphere' ), $destination );
	}

	/**
	 * The flag is consumed even on the settings-page path, so a stale flag from
	 * an abandoned Connectors-card flow can never survive a subsequent connect.
	 *
	 * @covers ::oauth_return_destination
	 */
	public function test_consumes_flag_regardless_of_destination(): void {
		\set_transient( 'atmosphere_oauth_from_connectors', 1, HOUR_IN_SECONDS );

		// First resolution consumes the flag and routes to Connectors.
		$this->assertSame( $this->connectors_screen_url(), $this->resolve() );

		// A second resolution now falls back to the settings page: the flag is
		// gone, mirroring what a failed-then-retried callback would see.
		$this->assertSame( \admin_url( 'options-general.php?page=atmosphere' ), $this->resolve() );
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
		\set_transient(
			'atmosphere_oauth_notice',
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
			\get_transient( 'atmosphere_oauth_notice' ),
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
		\set_transient(
			'atmosphere_oauth_notice',
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
			\get_transient( 'atmosphere_oauth_notice' ),
			'An unprivileged view must not consume the notice.'
		);
	}
}
