<?php
/**
 * Tests for the Publication transformer.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests\Transformer;

use WP_UnitTestCase;
use Atmosphere\Transformer\Publication;

/**
 * Publication transformer tests.
 */
class Test_Publication extends WP_UnitTestCase {

	/**
	 * Test hex_to_rgb with a full hex color.
	 */
	public function test_hex_to_rgb_full() {
		$rgb = Publication::hex_to_rgb( '#ff8800' );

		$this->assertSame( 255, $rgb['r'] );
		$this->assertSame( 136, $rgb['g'] );
		$this->assertSame( 0, $rgb['b'] );
	}

	/**
	 * Test hex_to_rgb with shorthand hex.
	 */
	public function test_hex_to_rgb_shorthand() {
		$rgb = Publication::hex_to_rgb( '#f80' );

		$this->assertSame( 255, $rgb['r'] );
		$this->assertSame( 136, $rgb['g'] );
		$this->assertSame( 0, $rgb['b'] );
	}

	/**
	 * Test hex_to_rgb rejects invalid input.
	 */
	public function test_hex_to_rgb_invalid() {
		$this->assertNull( Publication::hex_to_rgb( 'not-a-color' ) );
		$this->assertNull( Publication::hex_to_rgb( 'var(--wp-color)' ) );
	}

	/**
	 * Test that the publication TID is stable across calls.
	 */
	public function test_publication_tid_stable() {
		\delete_option( Publication::OPTION_TID );

		$pub  = new Publication( null );
		$rkey = $pub->get_rkey();

		$this->assertNotEmpty( $rkey );
		$this->assertSame( $rkey, $pub->get_rkey() );
	}

	/**
	 * Test the collection NSID.
	 */
	public function test_collection() {
		$pub = new Publication( null );

		$this->assertSame( 'site.standard.publication', $pub->get_collection() );
	}
}
