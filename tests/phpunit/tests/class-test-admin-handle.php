<?php
/**
 * Integration tests for the domain-handle admin-post handler.
 *
 * Covers the two security gates on `Admin::handle_set_domain_handle`
 * (capability + nonce) and the happy-path redirect. The Handle service
 * itself has unit coverage in {@see Test_Handle}; this file pins the
 * front-door contract between an admin POST and the XRPC call.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group handle
 */

namespace Atmosphere\Tests;

use Atmosphere\Handle;
use Atmosphere\WP_Admin\Admin;
use WP_UnitTestCase;
use WPDieException;

/**
 * Admin domain-handle handler tests.
 */
class Test_Admin_Handle extends WP_UnitTestCase {

	/**
	 * Filters registered during a test that must be removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $tracked_filters = array();

	/**
	 * Reset request superglobals and current user.
	 */
	public function tear_down(): void {
		foreach ( $this->tracked_filters as $entry ) {
			\remove_filter( $entry[0], $entry[1], $entry[2] );
		}
		$this->tracked_filters = array();

		$_REQUEST = array();
		$_POST    = array();
		$_GET     = array();

		\wp_set_current_user( 0 );
		\delete_option( 'atmosphere_connection' );
		\delete_option( Handle::OPTION_PREVIOUS_HANDLE );

		parent::tear_down();
	}

	/**
	 * Register a filter and remember it for tearDown removal.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function add_filter_tracked( string $hook, callable $callback, int $priority = 10 ): void {
		\add_filter( $hook, $callback, $priority );
		$this->tracked_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Become an administrator — grants `manage_options`.
	 */
	private function become_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );
	}

	/**
	 * Test that the handler dies when the current user lacks `manage_options`.
	 */
	public function test_dies_without_manage_options_cap(): void {
		\wp_set_current_user( 0 );

		$this->expectException( WPDieException::class );

		Admin::handle_set_domain_handle();
	}

	/**
	 * Test that the handler dies when no valid nonce is present, and that
	 * the underlying `Handle::set_handle()` flow never runs.
	 */
	public function test_dies_on_missing_nonce_and_skips_handle_call(): void {
		$this->become_admin();

		$called = 0;
		$spy    = static function ( $value ) use ( &$called ) {
			++$called;
			return $value;
		};
		$this->add_filter_tracked( Handle::FILTER_PRE_UPDATE, $spy );

		try {
			Admin::handle_set_domain_handle();
			$this->fail( 'Expected wp_die() from check_admin_referer.' );
		} catch ( WPDieException $e ) {
			// Expected.
			unset( $e );
		}

		$this->assertSame( 0, $called, 'Handle::set_handle() must not run when the nonce check fails.' );
	}

	/**
	 * Test that the handler redirects to the Atmosphere settings page when
	 * both the capability and nonce gates pass.
	 */
	public function test_redirects_to_settings_page_on_success(): void {
		$this->become_admin();

		$_REQUEST['atmosphere_nonce'] = \wp_create_nonce( 'atmosphere_set_domain_handle' );

		/*
		 * Short-circuit `wp_redirect` so the handler's `exit` is preempted
		 * by an exception we can catch — without this the test would halt
		 * the entire PHPUnit run.
		 */
		$captured = null;
		$catcher  = static function ( $location ) use ( &$captured ) {
			$captured = $location;
			throw new WPDieException( 'redirect_intercepted' );
		};
		$this->add_filter_tracked( 'wp_redirect', $catcher );

		try {
			Admin::handle_set_domain_handle();
			$this->fail( 'Expected redirect to be intercepted.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'redirect_intercepted', $e->getMessage() );
		}

		$this->assertIsString( $captured );
		$this->assertStringContainsString( 'page=atmosphere', $captured );
	}
}
