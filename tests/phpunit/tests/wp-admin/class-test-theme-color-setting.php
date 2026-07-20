<?php
/**
 * Tests for publication theme color setting sanitization.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group settings
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\Options;
use Atmosphere\Sanitize;
use Atmosphere\Transformer\Publication;

/**
 * Theme color setting sanitization tests.
 */
class Test_Theme_Color_Setting extends \WP_UnitTestCase {

	/**
	 * Clear stored theme colors between tests.
	 */
	public function tear_down(): void {
		\delete_option( Publication::OPTION_THEME_BACKGROUND );
		\delete_option( Publication::OPTION_THEME_FOREGROUND );
		\delete_option( Publication::OPTION_THEME_ACCENT );

		parent::tear_down();
	}

	/**
	 * Valid hex values are normalized to six-character lowercase form.
	 */
	public function test_hex_color_accepts_valid_values() {
		$this->assertSame( '#ff8800', Sanitize::hex_color( '#ff8800' ) );
		$this->assertSame( '#ff8800', Sanitize::hex_color( '#F80' ) );
		$this->assertSame( '#112233', Sanitize::hex_color( ' #112233 ' ) );
	}

	/**
	 * Invalid values sanitize to empty string.
	 */
	public function test_hex_color_rejects_invalid_values() {
		$this->assertSame( '', Sanitize::hex_color( 'nope' ) );
		$this->assertSame( '', Sanitize::hex_color( '#zzzzzz' ) );
		$this->assertSame( '', Sanitize::hex_color( 'rgb(1,2,3)' ) );
		$this->assertSame( '', Sanitize::hex_color( null ) );
		$this->assertSame( '', Sanitize::hex_color( array( '#fff' ) ) );
	}

	/**
	 * Empty input remains empty so derived theme values can be used.
	 */
	public function test_hex_color_allows_empty_value() {
		$this->assertSame( '', Sanitize::hex_color( '' ) );
	}

	/**
	 * The sanitizer is actually wired to the registered setting, so a
	 * malformed value never reaches the database.
	 */
	public function test_registered_setting_sanitizes_on_save() {
		Options::register_settings();

		\update_option( Publication::OPTION_THEME_ACCENT, '#AABBCC' );
		$this->assertSame( '#aabbcc', \get_option( Publication::OPTION_THEME_ACCENT ) );

		\update_option( Publication::OPTION_THEME_ACCENT, 'javascript:alert(1)' );
		$this->assertSame( '', \get_option( Publication::OPTION_THEME_ACCENT ) );
	}

	/**
	 * Saving a theme color queues a publication sync so the record
	 * reflects the new colors.
	 */
	public function test_saving_theme_color_schedules_publication_sync() {
		\update_option( Publication::OPTION_THEME_BACKGROUND, '#123456' );

		$this->assertNotFalse(
			\has_action( 'update_option_' . Publication::OPTION_THEME_BACKGROUND ),
			'The background color option must trigger a publication sync.'
		);
	}
}
