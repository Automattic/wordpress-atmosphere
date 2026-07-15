<?php
/**
 * Tests for Settings API option registration.
 *
 * @package Atmosphere
 * @group atmosphere
 */

namespace Atmosphere\Tests;

use Atmosphere\Options;

/**
 * Settings API option tests.
 */
class Test_Options extends \WP_UnitTestCase {

	/**
	 * Remove saved options after each test.
	 */
	public function tear_down(): void {
		foreach ( $this->data_boolean_options() as $option ) {
			\delete_option( $option[0] );
		}

		parent::tear_down();
	}

	/**
	 * Boolean settings retain the string format expected by their consumers.
	 *
	 * @dataProvider data_boolean_options
	 *
	 * @param string $option Option name.
	 */
	public function test_boolean_options_preserve_string_values( $option ) {
		Options::register_settings();
		\delete_option( $option );

		\update_option( $option, '0' );
		$this->assertSame( '', \get_option( $option ) );

		\update_option( $option, '1' );
		$this->assertSame( '1', \get_option( $option ) );
	}

	/**
	 * Boolean option names.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function data_boolean_options(): array {
		return array(
			'auto publish'     => array( 'atmosphere_auto_publish' ),
			'publish comments' => array( 'atmosphere_publish_comments' ),
			'sync reactions'   => array( 'atmosphere_sync_reactions' ),
			'sync replies'     => array( 'atmosphere_sync_replies' ),
		);
	}
}
