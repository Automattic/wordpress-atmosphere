<?php
/**
 * Tests for the long-form composition setting.
 *
 * Covers the sanitize callback and the option-driven seed filter.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group settings
 */

namespace Atmosphere\Tests;

use WP_UnitTestCase;
use Atmosphere\Atmosphere;
use Atmosphere\WP_Admin\Admin;

/**
 * Long-form composition setting tests.
 */
class Test_Long_Form_Composition_Setting extends WP_UnitTestCase {

	/**
	 * Re-register the seed filter and clear option/transient state.
	 *
	 * Other test classes call `remove_all_filters( 'atmosphere_long_form_composition' )`
	 * in their `tear_down`, which would strip the production seed registered once on
	 * `plugins_loaded`. Re-add it here so each test in this class starts with the
	 * expected filter wiring regardless of execution order.
	 */
	public function set_up(): void {
		parent::set_up();

		\remove_all_filters( 'atmosphere_long_form_composition' );
		\add_filter( 'atmosphere_long_form_composition', array( Atmosphere::class, 'seed_long_form_composition' ), 1 );
		\delete_transient( 'atmosphere_invalid_long_form_composition_logged' );
	}

	/**
	 * Reset state between tests.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_long_form_composition' );
		\delete_transient( 'atmosphere_invalid_long_form_composition_logged' );
		\remove_all_filters( 'atmosphere_long_form_composition' );

		parent::tear_down();
	}

	/**
	 * Sanitize callback accepts each known strategy.
	 */
	public function test_sanitize_accepts_known_values() {
		$this->assertSame( 'link-card', Admin::sanitize_long_form_composition( 'link-card' ) );
		$this->assertSame( 'truncate-link', Admin::sanitize_long_form_composition( 'truncate-link' ) );
		$this->assertSame( 'teaser-thread', Admin::sanitize_long_form_composition( 'teaser-thread' ) );
	}

	/**
	 * Sanitize callback falls back to the default for unknown / non-string input.
	 */
	public function test_sanitize_rejects_unknown_values() {
		$this->assertSame( 'link-card', Admin::sanitize_long_form_composition( 'something-else' ) );
		$this->assertSame( 'link-card', Admin::sanitize_long_form_composition( '' ) );
		$this->assertSame( 'link-card', Admin::sanitize_long_form_composition( null ) );
		$this->assertSame( 'link-card', Admin::sanitize_long_form_composition( array( 'teaser-thread' ) ) );
	}

	/**
	 * The option seeds the `atmosphere_long_form_composition` filter.
	 */
	public function test_option_seeds_filter() {
		\update_option( 'atmosphere_long_form_composition', 'teaser-thread' );

		$result = \apply_filters( 'atmosphere_long_form_composition', 'link-card', null );

		$this->assertSame( 'teaser-thread', $result );
	}

	/**
	 * Downstream filters at the default priority override the option.
	 */
	public function test_downstream_filter_overrides_option() {
		\update_option( 'atmosphere_long_form_composition', 'teaser-thread' );

		\add_filter(
			'atmosphere_long_form_composition',
			static function (): string {
				return 'truncate-link';
			}
		);

		$result = \apply_filters( 'atmosphere_long_form_composition', 'link-card', null );

		$this->assertSame( 'truncate-link', $result );
	}

	/**
	 * Unknown stored values are ignored; the default flows through.
	 */
	public function test_corrupt_option_falls_through_to_default() {
		\update_option( 'atmosphere_long_form_composition', 'bogus-strategy' );

		$result = \apply_filters( 'atmosphere_long_form_composition', 'link-card', null );

		$this->assertSame( 'link-card', $result );
	}
}
