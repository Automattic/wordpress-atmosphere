<?php
/**
 * Tests for the icon library registration.
 *
 * The icon API only exists on WordPress 7.1+, and the plugin supports
 * 6.5, so there are two behaviors to pin: on 7.1 the collection and all
 * four icons register and render, and on older WordPress `register()`
 * must return without touching anything. The file-level checks run
 * everywhere: the library's sanitizer only keeps `svg`, `path` and
 * `polygon`, and strips `stroke`, so a redrawn asset that violates that
 * would arrive broken without any test noticing.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group icons
 */

namespace Atmosphere\Tests;

use Atmosphere\Icons;

/**
 * Icon registration tests.
 */
class Test_Icons extends \WP_UnitTestCase {

	/**
	 * Unregister everything the tests registered.
	 */
	public function tear_down(): void {
		if ( \function_exists( 'wp_unregister_icon' ) ) {
			foreach ( \array_keys( Icons::icons() ) as $name ) {
				\wp_unregister_icon( Icons::COLLECTION . '/' . $name );
			}
			\wp_unregister_icon_collection( Icons::COLLECTION );
		}

		parent::tear_down();
	}

	/**
	 * Every shipped SVG exists and only uses what the sanitizer keeps.
	 */
	public function test_svg_files_survive_the_library_sanitizer() {
		foreach ( \array_keys( Icons::icons() ) as $name ) {
			$file = ATMOSPHERE_PLUGIN_DIR . 'assets/svg/' . $name . '.svg';

			$this->assertFileExists( $file );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin asset, not a remote URL.
			$svg = (string) \file_get_contents( $file );

			\preg_match_all( '/<([a-zA-Z]+)/', $svg, $elements );
			$this->assertEmpty(
				\array_diff( \array_unique( $elements[1] ), array( 'svg', 'path', 'polygon' ) ),
				"$name.svg uses an element the icon library strips."
			);
			$this->assertStringNotContainsString( 'stroke', $svg, "$name.svg relies on stroke, which the library strips." );
			$this->assertStringContainsString( 'viewBox', $svg );
		}
	}

	/**
	 * On WordPress with the icon API, the collection and all four icons
	 * register, and the library renders them.
	 */
	public function test_registers_collection_and_icons() {
		if ( ! \function_exists( 'wp_register_icon_collection' ) ) {
			$this->markTestSkipped( 'Icon API not available before WordPress 7.1.' );
		}

		Icons::register();

		$this->assertTrue( \WP_Icon_Collections_Registry::get_instance()->is_registered( Icons::COLLECTION ) );

		foreach ( \array_keys( Icons::icons() ) as $name ) {
			$this->assertTrue(
				\WP_Icons_Registry::get_instance()->is_registered( Icons::COLLECTION . '/' . $name ),
				"$name did not register."
			);
		}

		$svg = \wp_get_icon( Icons::COLLECTION . '/bluesky', array( 'size' => 32 ) );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( 'width="32"', $svg );
		// The tag processor lowercases attribute names when it rewrites the markup.
		$this->assertStringContainsStringIgnoringCase( 'viewbox="0 0 600 530"', $svg, 'The rendered markup comes from our file.' );
	}

	/**
	 * Registering twice must not blow up: core refuses the duplicate and
	 * the first registration stays.
	 */
	public function test_register_is_repeat_safe() {
		if ( ! \function_exists( 'wp_register_icon_collection' ) ) {
			$this->markTestSkipped( 'Icon API not available before WordPress 7.1.' );
		}

		Icons::register();
		Icons::register();

		$this->assertTrue( \WP_Icons_Registry::get_instance()->is_registered( Icons::COLLECTION . '/atmosphere' ) );
	}

	/**
	 * Without the icon API, `register()` returns quietly. Pinned so the
	 * 6.5 job in the matrix proves the guard rather than fataling.
	 */
	public function test_register_is_a_noop_without_the_api() {
		if ( \function_exists( 'wp_register_icon_collection' ) ) {
			$this->markTestSkipped( 'Icon API present; the guard path is the 6.5 job\'s to prove.' );
		}

		Icons::register();

		$this->assertFalse( \class_exists( 'WP_Icons_Registry' ) );
	}
}
