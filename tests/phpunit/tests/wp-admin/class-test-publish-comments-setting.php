<?php
/**
 * Tests for the publish-comments setting and its behavior filter.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\Options;
use Atmosphere\WP_Admin\Settings_Fields;
use function Atmosphere\is_comment_publishing_enabled;

/**
 * Publish-comments setting tests.
 */
class Test_Publish_Comments_Setting extends \WP_UnitTestCase {

	/**
	 * Remove the saved preference and filter overrides after each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_publish_comments' );
		\remove_all_filters( 'atmosphere_should_publish_comments' );
		parent::tear_down();
	}

	/**
	 * The setting is a REST-visible boolean with the backwards-compatible
	 * enabled default.
	 */
	public function test_setting_is_registered() {
		Options::register_settings();
		$settings = \get_registered_settings();

		$this->assertArrayHasKey( 'atmosphere_publish_comments', $settings );
		$this->assertSame( 'boolean', $settings['atmosphere_publish_comments']['type'] );
		$this->assertSame( '1', $settings['atmosphere_publish_comments']['default'] );
		$this->assertTrue( $settings['atmosphere_publish_comments']['show_in_rest'] );
	}

	/**
	 * The control edits the stored preference and reflects it directly.
	 */
	public function test_field_renders_saved_option() {
		\update_option( 'atmosphere_publish_comments', '1' );

		\ob_start();
		Settings_Fields::render_publish_comments_field();
		$enabled = (string) \ob_get_clean();

		$this->assertStringContainsString( 'checked=', $enabled );

		\update_option( 'atmosphere_publish_comments', '' );
		\ob_start();
		Settings_Fields::render_publish_comments_field();
		$disabled = (string) \ob_get_clean();

		$this->assertStringNotContainsString( 'checked=', $disabled );
	}

	/**
	 * The behavior filter has the final say in both directions, and the
	 * override never touches the stored preference — the form keeps
	 * editing the saved value while a host plugin steers the effective
	 * behavior.
	 */
	public function test_filter_overrides_effective_behavior_without_touching_the_option() {
		\update_option( 'atmosphere_publish_comments', '1' );
		\add_filter( 'atmosphere_should_publish_comments', '__return_false' );

		$this->assertFalse( is_comment_publishing_enabled() );
		$this->assertSame( '1', \get_option( 'atmosphere_publish_comments' ), 'The override must not modify the stored preference.' );

		/* The form still reflects (and edits) the saved value. */
		\ob_start();
		Settings_Fields::render_publish_comments_field();
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( 'checked=', $html );

		/* The filter can also re-enable a lane the option has off. */
		\remove_all_filters( 'atmosphere_should_publish_comments' );
		\update_option( 'atmosphere_publish_comments', '' );
		\add_filter( 'atmosphere_should_publish_comments', '__return_true' );

		$this->assertTrue( is_comment_publishing_enabled() );
		$this->assertSame( '', \get_option( 'atmosphere_publish_comments' ) );
	}
}
