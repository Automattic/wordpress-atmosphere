<?php
/**
 * Tests for publication theme color setting sanitization.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group settings
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\Sanitize;

/**
 * Theme color setting sanitization tests.
 */
class Test_Theme_Color_Setting extends \WP_UnitTestCase {

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
}
