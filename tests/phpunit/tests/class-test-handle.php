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
		\add_filter( Handle::FILTER_ENABLED, '__return_false' );
		$this->assertFalse( Handle::is_enabled() );
		\remove_filter( Handle::FILTER_ENABLED, '__return_false' );
	}

	/**
	 * Test that is_root_install returns true for a root domain.
	 */
	public function test_is_root_install_for_root(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com' );
		$this->assertTrue( Handle::is_root_install() );
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that is_root_install returns true for a root domain with trailing slash.
	 */
	public function test_is_root_install_for_root_with_trailing_slash(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com/' );
		$this->assertTrue( Handle::is_root_install() );
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that is_root_install returns false for a subdirectory install.
	 */
	public function test_is_root_install_false_for_subdirectory(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com/blog' );
		$this->assertFalse( Handle::is_root_install() );
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that get_target_handle lowercases the host.
	 */
	public function test_get_target_handle_lowercases_host(): void {
		\add_filter( 'home_url', static fn() => 'https://Example.COM' );
		$this->assertSame( 'example.com', Handle::get_target_handle() );
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that get_target_handle returns empty string when host is missing.
	 */
	public function test_get_target_handle_returns_empty_when_host_missing(): void {
		\add_filter( 'home_url', static fn() => '/relative/path' );
		$this->assertSame( '', Handle::get_target_handle() );
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that should_offer returns false when the feature is disabled.
	 */
	public function test_should_offer_false_when_disabled(): void {
		\add_filter( Handle::FILTER_ENABLED, '__return_false' );
		$this->assertFalse(
			Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
		\remove_filter( Handle::FILTER_ENABLED, '__return_false' );
	}

	/**
	 * Test that should_offer returns false for a subdirectory install.
	 */
	public function test_should_offer_false_for_subdir_install(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com/blog' );
		$this->assertFalse(
			Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that should_offer returns false when not connected.
	 */
	public function test_should_offer_false_when_not_connected(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com' );
		$this->assertFalse( Handle::should_offer( array( 'connected' => false ) ) );
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that should_offer returns false when the handle already matches the domain.
	 */
	public function test_should_offer_false_when_handle_already_matches(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com' );
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
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that should_offer returns true when all conditions are met.
	 */
	public function test_should_offer_true_when_eligible(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com' );
		$this->assertTrue(
			Handle::should_offer(
				array(
					'connected' => true,
					'handle'    => 'alice.bsky.social',
				)
			)
		);
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that set_handle returns null when the feature is disabled.
	 */
	public function test_set_handle_returns_null_when_disabled(): void {
		\add_filter( Handle::FILTER_ENABLED, '__return_false' );
		$this->assertNull( Handle::set_handle() );
		\remove_filter( Handle::FILTER_ENABLED, '__return_false' );
	}

	/**
	 * Test that set_handle returns null for a subdirectory install.
	 */
	public function test_set_handle_returns_null_for_subdir_install(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com/blog' );
		$this->assertNull( Handle::set_handle() );
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that set_handle returns WP_Error when not connected.
	 */
	public function test_set_handle_errors_when_not_connected(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com' );
		\delete_option( 'atmosphere_connection' );
		$result = Handle::set_handle();
		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_not_connected', $result->get_error_code() );
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that set_handle returns null when the handle already matches.
	 */
	public function test_set_handle_returns_null_when_already_matching(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com' );
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'example.com',
				'did'          => 'did:plc:test',
				'access_token' => 'tok',
			)
		);
		$this->assertNull( Handle::set_handle() );
		\delete_option( 'atmosphere_connection' );
		\remove_all_filters( 'home_url' );
	}

	/**
	 * Test that set_handle succeeds via the short-circuit filter.
	 */
	public function test_set_handle_succeeds_via_short_circuit_filter(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com' );
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.bsky.social',
				'did'          => 'did:plc:test',
				'access_token' => 'tok',
			)
		);
		\add_filter( Handle::FILTER_PRE_UPDATE, static fn() => true );

		$result = Handle::set_handle();

		$this->assertTrue( $result );
		$this->assertSame( 'alice.bsky.social', \get_option( Handle::OPTION_PREVIOUS_HANDLE ) );
		$this->assertSame( 'example.com', \get_option( 'atmosphere_connection' )['handle'] );

		\remove_all_filters( Handle::FILTER_PRE_UPDATE );
		\remove_all_filters( 'home_url' );
		\delete_option( 'atmosphere_connection' );
		\delete_option( Handle::OPTION_PREVIOUS_HANDLE );
	}

	/**
	 * Test that set_handle propagates a WP_Error from the short-circuit filter.
	 */
	public function test_set_handle_propagates_short_circuit_wp_error(): void {
		\add_filter( 'home_url', static fn() => 'https://example.com' );
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.bsky.social',
				'did'          => 'did:plc:test',
				'access_token' => 'tok',
			)
		);
		$err = new \WP_Error( 'rate_limited', 'slow down' );
		\add_filter( Handle::FILTER_PRE_UPDATE, static fn() => $err );

		$result = Handle::set_handle();

		$this->assertWPError( $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 'alice.bsky.social', \get_option( 'atmosphere_connection' )['handle'] );

		\remove_all_filters( Handle::FILTER_PRE_UPDATE );
		\remove_all_filters( 'home_url' );
		\delete_option( 'atmosphere_connection' );
		\delete_option( Handle::OPTION_PREVIOUS_HANDLE );
	}

	/**
	 * Test that maybe_revert_on_disconnect returns null when the feature is disabled.
	 */
	public function test_revert_returns_null_when_disabled(): void {
		\add_filter( Handle::FILTER_ENABLED, '__return_false' );
		$this->assertNull( Handle::maybe_revert_on_disconnect() );
		\remove_filter( Handle::FILTER_ENABLED, '__return_false' );
	}

	/**
	 * Test that maybe_revert_on_disconnect returns null when no previous handle is stored.
	 */
	public function test_revert_returns_null_when_no_previous_handle(): void {
		\delete_option( Handle::OPTION_PREVIOUS_HANDLE );
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
		\add_filter( Handle::FILTER_PRE_UPDATE, static fn() => true );

		$result = Handle::maybe_revert_on_disconnect();

		$this->assertTrue( $result );
		$this->assertFalse( \get_option( Handle::OPTION_PREVIOUS_HANDLE ) );
		$this->assertSame( 'alice.bsky.social', \get_option( 'atmosphere_connection' )['handle'] );

		\remove_all_filters( Handle::FILTER_PRE_UPDATE );
		\delete_option( 'atmosphere_connection' );
	}

	/**
	 * Test that maybe_revert_on_disconnect keeps the option on failure.
	 */
	public function test_revert_keeps_option_on_failure(): void {
		\update_option( Handle::OPTION_PREVIOUS_HANDLE, 'alice.bsky.social' );
		$err = new \WP_Error( 'fail', 'nope' );
		\add_filter( Handle::FILTER_PRE_UPDATE, static fn() => $err );

		$result = Handle::maybe_revert_on_disconnect();

		$this->assertWPError( $result );
		$this->assertSame( 'alice.bsky.social', \get_option( Handle::OPTION_PREVIOUS_HANDLE ) );

		\remove_all_filters( Handle::FILTER_PRE_UPDATE );
		\delete_option( Handle::OPTION_PREVIOUS_HANDLE );
	}
}
