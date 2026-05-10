<?php
/**
 * Tests for the domain-handle service.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group handle
 */

namespace Atmosphere\Tests;

use Atmosphere\Handle;
use WP_UnitTestCase;

/**
 * Domain-handle tests.
 */
class Test_Handle extends WP_UnitTestCase {

	/**
	 * Closures registered during a test that must be removed in tearDown.
	 *
	 * Stored as `[ [ $hook, $callable, $priority ], ... ]` so tests can
	 * register filters via {@see self::add_filter_tracked()} without having
	 * to remember to detach each one. Targeted removal avoids the
	 * shotgun blast of `remove_all_filters()`, which strips core's own
	 * registrations (multisite, `wp_force_ssl_admin`, etc.) too.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $tracked_filters = array();

	/**
	 * Set up an admin user so {@see Handle::set_handle()} clears its cap gate.
	 */
	public function set_up(): void {
		parent::set_up();

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );
	}

	/**
	 * Detach any filters registered via {@see self::add_filter_tracked()}.
	 */
	public function tear_down(): void {
		foreach ( $this->tracked_filters as $entry ) {
			\remove_filter( $entry[0], $entry[1], $entry[2] );
		}
		$this->tracked_filters = array();

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
	 * Force home_url() and site_url() to resolve to the same URL.
	 *
	 * Most tests want a clean root install where both URLs share host and
	 * path, so this helper installs both filters in one call.
	 *
	 * @param string $url URL to return for both.
	 */
	private function force_urls( string $url ): void {
		$callback = static fn() => $url;
		$this->add_filter_tracked( 'home_url', $callback );
		$this->add_filter_tracked( 'site_url', $callback );
	}

	/**
	 * Test that public constants hold the expected string values.
	 */
	public function test_constants(): void {
		$this->assertSame( 'atmosphere_domain_handle_enabled', Handle::FILTER_ENABLED );
		$this->assertSame( 'atmosphere_pre_update_handle', Handle::FILTER_PRE_UPDATE );
		$this->assertSame( 'atmosphere_previous_handle', Handle::OPTION_PREVIOUS_HANDLE );
		$this->assertSame( 'atmosphere', Handle::NOTICE_SETTING );
	}

	/**
	 * Test that is_enabled returns true by default.
	 */
	public function test_is_enabled_default_true(): void {
		$this->assertTrue( Handle::is_enabled() );
	}

	/**
	 * Test that is_enabled can be disabled via filter.
	 */
	public function test_is_enabled_filter_can_disable(): void {
		$this->add_filter_tracked( Handle::FILTER_ENABLED, '__return_false' );
		$this->assertFalse( Handle::is_enabled() );
	}

	/**
	 * Test that is_root_install returns true for a root domain.
	 */
	public function test_is_root_install_for_root(): void {
		$this->force_urls( 'https://example.com' );
		$this->assertTrue( Handle::is_root_install() );
	}

	/**
	 * Test that is_root_install returns true for a root domain with trailing slash.
	 */
	public function test_is_root_install_for_root_with_trailing_slash(): void {
		$this->force_urls( 'https://example.com/' );
		$this->assertTrue( Handle::is_root_install() );
	}

	/**
	 * Test that is_root_install returns false for a subdirectory install.
	 */
	public function test_is_root_install_false_for_subdirectory(): void {
		$this->force_urls( 'https://example.com/blog' );
		$this->assertFalse( Handle::is_root_install() );
	}

	/**
	 * Test that is_root_install returns false when home_url is at the host
	 * root but site_url is in a subdirectory — the rewrite parser is
	 * rooted at site_url and would not resolve `/.well-known/atproto-did`.
	 */
	public function test_is_root_install_false_for_split_install(): void {
		$this->add_filter_tracked( 'home_url', static fn() => 'https://example.com' );
		$this->add_filter_tracked( 'site_url', static fn() => 'https://example.com/wp' );
		$this->assertFalse( Handle::is_root_install() );
	}

	/**
	 * Test that is_root_install returns false when home and site disagree on host.
	 */
	public function test_is_root_install_false_for_host_mismatch(): void {
		$this->add_filter_tracked( 'home_url', static fn() => 'https://example.com' );
		$this->add_filter_tracked( 'site_url', static fn() => 'https://other.example' );
		$this->assertFalse( Handle::is_root_install() );
	}

	/**
	 * Test that get_target_handle lowercases the host.
	 */
	public function test_get_target_handle_lowercases_host(): void {
		$this->add_filter_tracked( 'home_url', static fn() => 'https://Example.COM' );
		$this->assertSame( 'example.com', Handle::get_target_handle() );
	}

	/**
	 * Test that get_target_handle returns empty string when host is missing.
	 */
	public function test_get_target_handle_returns_empty_when_host_missing(): void {
		$this->add_filter_tracked( 'home_url', static fn() => '/relative/path' );
		$this->assertSame( '', Handle::get_target_handle() );
	}

	/**
	 * Test that should_offer returns false when the feature is disabled.
	 */
	public function test_should_offer_false_when_disabled(): void {
		$this->add_filter_tracked( Handle::FILTER_ENABLED, '__return_false' );
		$this->assertFalse(
			Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * Test that should_offer returns false for a subdirectory install.
	 */
	public function test_should_offer_false_for_subdir_install(): void {
		$this->force_urls( 'https://example.com/blog' );
		$this->assertFalse(
			Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * Test that should_offer returns false when not connected.
	 */
	public function test_should_offer_false_when_not_connected(): void {
		$this->force_urls( 'https://example.com' );
		$this->assertFalse( Handle::should_offer( array( 'connected' => false ) ) );
	}

	/**
	 * Test that should_offer returns false when the handle already matches the domain.
	 */
	public function test_should_offer_false_when_handle_already_matches(): void {
		$this->force_urls( 'https://example.com' );
		$this->assertFalse(
			Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'example.com',
				)
			)
		);
		$this->assertFalse(
			Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'EXAMPLE.com',
				)
			)
		);
	}

	/**
	 * Test that should_offer returns true when all conditions are met.
	 */
	public function test_should_offer_true_when_eligible(): void {
		$this->force_urls( 'https://example.com' );
		$this->assertTrue(
			Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
	}

	/**
	 * Test that set_handle returns null when the current user lacks manage_options.
	 */
	public function test_set_handle_returns_null_when_user_lacks_cap(): void {
		\wp_set_current_user( 0 );
		$this->force_urls( 'https://example.com' );
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.bsky.social',
				'did'          => 'did:plc:test',
				'access_token' => 'tok',
			)
		);
		$this->add_filter_tracked( Handle::FILTER_PRE_UPDATE, static fn() => true );

		$this->assertNull( Handle::set_handle() );
		$this->assertSame(
			'alice.bsky.social',
			\get_option( 'atmosphere_connection' )['handle']
		);
	}

	/**
	 * Test that set_handle returns null when the feature is disabled.
	 */
	public function test_set_handle_returns_null_when_disabled(): void {
		$this->add_filter_tracked( Handle::FILTER_ENABLED, '__return_false' );
		$this->assertNull( Handle::set_handle() );
	}

	/**
	 * Test that set_handle returns null for a subdirectory install.
	 */
	public function test_set_handle_returns_null_for_subdir_install(): void {
		$this->force_urls( 'https://example.com/blog' );
		$this->assertNull( Handle::set_handle() );
	}

	/**
	 * Test that set_handle returns WP_Error when not connected.
	 */
	public function test_set_handle_errors_when_not_connected(): void {
		$this->force_urls( 'https://example.com' );
		\delete_option( 'atmosphere_connection' );
		$result = Handle::set_handle();
		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_not_connected', $result->get_error_code() );
	}

	/**
	 * Test that set_handle returns null when the handle already matches.
	 */
	public function test_set_handle_returns_null_when_already_matching(): void {
		$this->force_urls( 'https://example.com' );
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'example.com',
				'did'          => 'did:plc:test',
				'access_token' => 'tok',
			)
		);
		$this->assertNull( Handle::set_handle() );
	}

	/**
	 * Test that set_handle succeeds via the short-circuit filter.
	 */
	public function test_set_handle_succeeds_via_short_circuit_filter(): void {
		$this->force_urls( 'https://example.com' );
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.bsky.social',
				'did'          => 'did:plc:test',
				'access_token' => 'tok',
			)
		);
		$this->add_filter_tracked( Handle::FILTER_PRE_UPDATE, static fn() => true );

		$result = Handle::set_handle();

		$this->assertTrue( $result );
		$this->assertSame( 'alice.bsky.social', \get_option( Handle::OPTION_PREVIOUS_HANDLE ) );
		$this->assertSame( 'example.com', \get_option( 'atmosphere_connection' )['handle'] );
	}

	/**
	 * Test that set_handle propagates a WP_Error from the short-circuit filter.
	 */
	public function test_set_handle_propagates_short_circuit_wp_error(): void {
		$this->force_urls( 'https://example.com' );
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.bsky.social',
				'did'          => 'did:plc:test',
				'access_token' => 'tok',
			)
		);
		$err = new \WP_Error( 'rate_limited', 'slow down' );
		$this->add_filter_tracked( Handle::FILTER_PRE_UPDATE, static fn() => $err );

		$result = Handle::set_handle();

		$this->assertWPError( $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 'alice.bsky.social', \get_option( 'atmosphere_connection' )['handle'] );
	}

	/**
	 * Test that maybe_revert_on_disconnect returns null when the feature is disabled.
	 */
	public function test_revert_returns_null_when_disabled(): void {
		$this->add_filter_tracked( Handle::FILTER_ENABLED, '__return_false' );
		$this->assertNull( Handle::maybe_revert_on_disconnect() );
	}

	/**
	 * Test that maybe_revert_on_disconnect returns null when no previous handle is stored.
	 */
	public function test_revert_returns_null_when_no_previous_handle(): void {
		$this->assertNull( Handle::maybe_revert_on_disconnect() );
	}

	/**
	 * Test that maybe_revert_on_disconnect clears the option on success
	 * and mirrors the restored handle into the connection option.
	 */
	public function test_revert_clears_option_on_success(): void {
		\update_option( Handle::OPTION_PREVIOUS_HANDLE, 'alice.bsky.social' );
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'example.com',
				'did'          => 'did:plc:test',
				'access_token' => 'tok',
			)
		);
		$this->add_filter_tracked( Handle::FILTER_PRE_UPDATE, static fn() => true );

		$result = Handle::maybe_revert_on_disconnect();

		$this->assertTrue( $result );
		$this->assertFalse( \get_option( Handle::OPTION_PREVIOUS_HANDLE ) );
		$this->assertSame( 'alice.bsky.social', \get_option( 'atmosphere_connection' )['handle'] );
	}

	/**
	 * Test that maybe_revert_on_disconnect keeps the option on failure.
	 */
	public function test_revert_keeps_option_on_failure(): void {
		\update_option( Handle::OPTION_PREVIOUS_HANDLE, 'alice.bsky.social' );
		$err = new \WP_Error( 'fail', 'nope' );
		$this->add_filter_tracked( Handle::FILTER_PRE_UPDATE, static fn() => $err );

		$result = Handle::maybe_revert_on_disconnect();

		$this->assertWPError( $result );
		$this->assertSame( 'alice.bsky.social', \get_option( Handle::OPTION_PREVIOUS_HANDLE ) );
	}
}
