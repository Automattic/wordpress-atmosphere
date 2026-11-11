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

	/**
	 * A historical TID decodes back to the micros it was minted from.
	 */
	public function test_generate_for_time_round_trips_through_decode() {
		$unix     = \strtotime( '2020-01-01 00:00:00' );
		$expected = $unix * 1_000_000 + 42;

		$tid = TID::generate_for_time( $unix, 42 );

		$this->assertSame( 13, \strlen( $tid ) );
		$this->assertTrue( TID::is_valid( $tid ) );
		$this->assertSame( $expected, TID::decode( $tid ) );
	}

	/**
	 * A historical TID sorts below a freshly minted live TID.
	 */
	public function test_generate_for_time_sorts_below_live() {
		$hist = TID::generate_for_time( \strtotime( '2020-01-01 00:00:00' ), 1 );
		$live = TID::generate();

		$this->assertLessThan( $live, $hist );
	}

	/**
	 * Historical minting never advances the persisted monotonic floor.
	 */
	public function test_generate_for_time_does_not_advance_floor() {
		\update_option( 'atmosphere_tid_last_ts', '9999999999999999' );

		TID::generate_for_time( \strtotime( '2020-01-01 00:00:00' ), 1 );

		$this->assertSame( '9999999999999999', \get_option( 'atmosphere_tid_last_ts' ) );
	}

	/**
	 * The disambiguator separates two posts that share a second and
	 * orders them by the disambiguator value.
	 */
	public function test_generate_for_time_disambiguator_separates_same_second() {
		$unix = \strtotime( '2020-01-01 00:00:00' );

		$a = TID::generate_for_time( $unix, 100 );
		$b = TID::generate_for_time( $unix, 200 );

		$this->assertNotSame( $a, $b );
		$this->assertLessThan( $b, $a );
	}

	/**
	 * Different dates sort in date order.
	 */
	public function test_generate_for_time_sorts_by_date() {
		$older = TID::generate_for_time( \strtotime( '2019-06-01 00:00:00' ), 5 );
		$newer = TID::generate_for_time( \strtotime( '2021-06-01 00:00:00' ), 5 );

		$this->assertLessThan( $newer, $older );
	}

	/**
	 * A non-positive timestamp falls back to a live TID rather than
	 * minting a 1970-epoch rkey.
	 */
	public function test_generate_for_time_falls_back_on_bad_date() {
		$tid = TID::generate_for_time( 0, 1 );

		$this->assertTrue( TID::is_valid( $tid ) );
		// A live TID is far above any 2020s historical value; a 0-epoch
		// TID would sort near the bottom of the charset.
		$this->assertGreaterThan( TID::generate_for_time( \strtotime( '2020-01-01 00:00:00' ), 1 ), $tid );
	}

	/**
	 * A negative disambiguator is wrapped back into the sub-second slot
	 * rather than pushing the key before the target second (PHP's `%`
	 * keeps the sign of the dividend).
	 */
	public function test_generate_for_time_negative_disambiguator_stays_in_second() {
		$unix    = \strtotime( '2020-01-01 00:00:00' );
		$decoded = TID::decode( TID::generate_for_time( $unix, -5 ) );

		$this->assertGreaterThanOrEqual( $unix * 1_000_000, $decoded );
		$this->assertLessThan( ( $unix + 1 ) * 1_000_000, $decoded );
	}

	/**
	 * The clock bits widen disambiguation beyond the sub-second slot: two
	 * records with the same sub-second disambiguator but different clock
	 * bits mint distinct rkeys at the same microsecond — where the old
	 * sub-second-only scheme (shared per-process clock) collided.
	 */
	public function test_generate_for_time_clock_widens_disambiguation() {
		$unix = \strtotime( '2020-01-01 00:00:00' );

		$a = TID::generate_for_time( $unix, 500, 0 );
		$b = TID::generate_for_time( $unix, 500, 1 );

		$this->assertNotSame( $a, $b );
		// Same microsecond timestamp — the difference is purely the clock.
		$this->assertSame( TID::decode( $a ), TID::decode( $b ) );
		$this->assertLessThan( $b, $a );
	}

	/**
	 * A future date is clamped to now so a historical mint can't sort ahead
	 * of records published later in real time.
	 */
	public function test_generate_for_time_clamps_future_dates() {
		$second = \intdiv(
			TID::decode( TID::generate_for_time( \strtotime( '2999-01-01 00:00:00' ), 0 ) ),
			1_000_000
		);

		$this->assertLessThanOrEqual( \time(), $second );
		$this->assertGreaterThan( \strtotime( '2020-01-01 00:00:00' ), $second );
	}

	/**
	 * Decoding malformed input returns 0 explicitly instead of decoding
	 * stray characters into a plausible-but-wrong timestamp.
	 */
	public function test_decode_returns_zero_for_invalid_tid() {
		$this->assertSame( 0, TID::decode( '' ) );
		$this->assertSame( 0, TID::decode( 'tooshort' ) );
		// 13 characters, but '0' is not in the base-32 charset.
		$this->assertSame( 0, TID::decode( '0000000000000' ) );
	}
}
