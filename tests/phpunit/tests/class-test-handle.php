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
}
