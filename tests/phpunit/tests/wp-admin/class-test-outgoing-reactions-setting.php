<?php
/**
 * Tests for the outgoing reactions setting and deployment constant.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\Options;
use Atmosphere\WP_Admin\Settings_Fields;
use function Atmosphere\outgoing_reactions_enabled;

/**
 * Outgoing reactions setting tests.
 */
class Test_Outgoing_Reactions_Setting extends \WP_UnitTestCase {

	/**
	 * Remove the saved preference after each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_publish_reactions' );
		parent::tear_down();
	}

	/**
	 * The setting is a REST-visible boolean with the backwards-compatible
	 * enabled default.
	 */
	public function test_setting_is_registered() {
		Options::register_settings();
		$settings = \get_registered_settings();

		$this->assertArrayHasKey( 'atmosphere_publish_reactions', $settings );
		$this->assertSame( 'boolean', $settings['atmosphere_publish_reactions']['type'] );
		$this->assertSame( '1', $settings['atmosphere_publish_reactions']['default'] );
		$this->assertTrue( $settings['atmosphere_publish_reactions']['show_in_rest'] );
	}

	/**
	 * The regular control reflects the option and stays editable when no
	 * deployment constant is enforcing a value.
	 */
	public function test_field_renders_saved_option() {
		\update_option( 'atmosphere_publish_reactions', '1' );

		\ob_start();
		Settings_Fields::render_publish_reactions_field();
		$enabled = (string) \ob_get_clean();

		$this->assertStringContainsString( 'checked=', $enabled );
		$this->assertStringNotContainsString( 'disabled=', $enabled );
		$this->assertStringNotContainsString( 'type="hidden"', $enabled );

		\update_option( 'atmosphere_publish_reactions', '' );
		\ob_start();
		Settings_Fields::render_publish_reactions_field();
		$disabled = (string) \ob_get_clean();

		$this->assertStringNotContainsString( 'checked=', $disabled );
		$this->assertStringNotContainsString( 'disabled=', $disabled );
	}

	/**
	 * The wp-config constant wins over an enabled option, disables the
	 * visible control, and preserves the saved preference through a form save.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_overrides_option_and_disables_field() {
		\define( 'ATMOSPHERE_DISABLE_OUTGOING_REACTIONS', true );
		\update_option( 'atmosphere_publish_reactions', '1' );

		$this->assertFalse( outgoing_reactions_enabled() );

		\ob_start();
		Settings_Fields::render_publish_reactions_field();
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( 'disabled=', $html );
		$this->assertStringNotContainsString( 'checked=', $html );
		$this->assertStringContainsString( 'type="hidden"', $html );
		$this->assertStringContainsString( 'value="1"', $html );
		$this->assertStringContainsString( 'ATMOSPHERE_DISABLE_OUTGOING_REACTIONS', $html );
	}
}
