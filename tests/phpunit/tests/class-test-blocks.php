<?php
/**
 * Tests for the front-end block registration.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests;

use Atmosphere\Blocks;
use WP_UnitTestCase;

/**
 * Tests for front-end block registration and its ActivityPub gating.
 *
 * @group atmosphere
 * @coversDefaultClass \Atmosphere\Blocks
 */
class Test_Blocks extends WP_UnitTestCase {

	/**
	 * Start each test from a clean slate — the plugin registers the block on
	 * `init` during bootstrap, so unregister it first to exercise the gate.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'atmosphere/reactions' ) ) {
			\unregister_block_type( 'atmosphere/reactions' );
		}
	}

	/**
	 * Restore the default registration so later tests find the block present.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_register_reactions_block' );

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'atmosphere/reactions' ) ) {
			Blocks::register_blocks();
		}

		parent::tear_down();
	}

	/**
	 * With the ActivityPub plugin absent (the test environment), the
	 * reactions block registers by default.
	 *
	 * @covers ::register_blocks
	 */
	public function test_registers_reactions_block_by_default() {
		Blocks::register_blocks();

		$this->assertTrue(
			\WP_Block_Type_Registry::get_instance()->is_registered( 'atmosphere/reactions' )
		);
	}

	/**
	 * The filter can suppress registration.
	 *
	 * @covers ::register_blocks
	 */
	public function test_filter_can_suppress_registration() {
		\add_filter( 'atmosphere_register_reactions_block', '__return_false' );

		Blocks::register_blocks();

		$this->assertFalse(
			\WP_Block_Type_Registry::get_instance()->is_registered( 'atmosphere/reactions' )
		);
	}

	/**
	 * The filter default is true when ActivityPub is not active, so the
	 * gate keys off the plugin's presence (not a hard skip).
	 *
	 * @covers ::register_blocks
	 */
	public function test_filter_default_is_true_without_activitypub() {
		$received = null;
		\add_filter(
			'atmosphere_register_reactions_block',
			function ( $register ) use ( &$received ) {
				$received = $register;
				return $register;
			}
		);

		Blocks::register_blocks();

		$this->assertTrue( $received, 'Default should be true when ACTIVITYPUB_PLUGIN_VERSION is undefined.' );
	}
}
