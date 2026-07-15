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
	 * Clean up the origin flag between tests.
	 */
	public function tear_down(): void {
		\delete_transient( 'atmosphere_oauth_from_connectors' );
		parent::tear_down();
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

		$this->assertSame( \admin_url( Connectors::SCREEN ), $destination );
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
		$this->assertSame( \admin_url( Connectors::SCREEN ), $this->resolve() );

		// A second resolution now falls back to the settings page: the flag is
		// gone, mirroring what a failed-then-retried callback would see.
		$this->assertSame( \admin_url( 'options-general.php?page=atmosphere' ), $this->resolve() );
	}
}
