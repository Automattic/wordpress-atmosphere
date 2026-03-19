<?php
/**
 * Tests for TID generation.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests\Transformer;

use WP_UnitTestCase;
use Atmosphere\Transformer\TID;

/**
 * TID tests.
 */
class Test_TID extends WP_UnitTestCase {

	/**
	 * Test that generate produces a 13-character string.
	 */
	public function test_generate_length() {
		$tid = TID::generate();

		$this->assertSame( 13, \strlen( $tid ) );
	}

	/**
	 * Test that generated TIDs are valid.
	 */
	public function test_generate_is_valid() {
		$tid = TID::generate();

		$this->assertTrue( TID::is_valid( $tid ) );
	}

	/**
	 * Test that consecutive TIDs are monotonically increasing.
	 */
	public function test_generate_monotonic() {
		$first  = TID::generate();
		$second = TID::generate();

		$this->assertGreaterThan( $first, $second );
	}

	/**
	 * Test is_valid rejects bad inputs.
	 */
	public function test_is_valid_rejects_bad_input() {
		$this->assertFalse( TID::is_valid( '' ) );
		$this->assertFalse( TID::is_valid( 'tooshort' ) );
		$this->assertFalse( TID::is_valid( '0000000000000' ) ); // '0' and '1' not in charset.
		$this->assertFalse( TID::is_valid( 'AAAAAAAAAAAAA' ) ); // Uppercase not in charset.
	}
}
