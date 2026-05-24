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
 *
 * @group atmosphere
 * @group transformer
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
	 * The generate_for_time() helper returns a 13-character valid TID.
	 */
	public function test_generate_for_time_shape() {
		$tid = TID::generate_for_time( 1_500_000_000_000_000 );

		$this->assertSame( 13, \strlen( $tid ) );
		$this->assertTrue( TID::is_valid( $tid ) );
	}

	/**
	 * The generate_for_time() helper is fully deterministic for the
	 * same microsecond input. The clock_id is derived from the input
	 * rather than the per-process random value, so a retry in a
	 * different PHP worker reconstructs the same rkey — which is the
	 * idempotency guarantee the backfill flow depends on.
	 */
	public function test_generate_for_time_is_deterministic() {
		$microseconds = 1_500_000_000_000_000;

		$first  = TID::generate_for_time( $microseconds );
		$second = TID::generate_for_time( $microseconds );

		$this->assertSame( $first, $second );
	}

	/**
	 * The generate_for_time() helper must not consult or update the
	 * monotonic floor. After a historical mint with an old timestamp
	 * the live generate() must still produce a now-based TID (not
	 * floor + 1).
	 */
	public function test_generate_for_time_does_not_poison_floor() {
		// Prime the floor with a current-time call.
		$baseline     = TID::generate();
		$floor_before = (int) \get_option( 'atmosphere_tid_last_ts', 0 );

		// Mint a historical TID well below the floor.
		$historical_micros = 1_000_000_000_000_000; // 2001-09-09.
		$historical        = TID::generate_for_time( $historical_micros );

		$floor_after = (int) \get_option( 'atmosphere_tid_last_ts', 0 );

		// Floor must not change.
		$this->assertSame( $floor_before, $floor_after, 'Historical TID write must not bump the persisted floor.' );

		// And the historical TID must sort *before* the baseline (proves
		// it was not snapped forward to the floor).
		$this->assertLessThan( $baseline, $historical, 'Historical TID for 2001 must sort before a current-time TID.' );

		// A subsequent live generate() should keep moving forward
		// relative to the baseline, not regress to the historical value.
		$next_live = TID::generate();
		$this->assertGreaterThan( $baseline, $next_live, 'Live generate() after a historical mint must still increase.' );
	}

	/**
	 * The microseconds_from_post_date() helper returns deterministic,
	 * post-ID-disambiguated microseconds for two posts at the same second.
	 */
	public function test_microseconds_from_post_date_disambiguates_same_second() {
		$gmt = '2019-03-14 15:09:26';

		$a = TID::microseconds_from_post_date( $gmt, 42 );
		$b = TID::microseconds_from_post_date( $gmt, 43 );
		$c = TID::microseconds_from_post_date( $gmt, 42 );

		$this->assertNotSame( $a, $b, 'Different post IDs in the same second must produce different microseconds.' );
		$this->assertSame( $a, $c, 'Same post ID + same second must be idempotent.' );

		// Sanity-check: the seconds-portion of all three matches.
		$seconds = (int) \strtotime( $gmt . ' UTC' );
		$this->assertSame( $seconds, \intdiv( $a, 1_000_000 ) );
		$this->assertSame( $seconds, \intdiv( $b, 1_000_000 ) );
	}

	/**
	 * The microseconds_from_post_date() helper returns 0 for unparseable
	 * input so callers can fall back to TID::generate() rather than
	 * minting an epoch-anchored TID.
	 */
	public function test_microseconds_from_post_date_returns_zero_on_parse_failure() {
		$this->assertSame( 0, TID::microseconds_from_post_date( '', 42 ) );
		$this->assertSame( 0, TID::microseconds_from_post_date( '0000-00-00 00:00:00', 42 ) );
		$this->assertSame( 0, TID::microseconds_from_post_date( 'not a date', 42 ) );
	}

	/**
	 * Pre-epoch dates are rejected so the historical path never mints a
	 * negative-encoded TID. Posts dated before 1970-01-01 UTC are
	 * vanishingly rare in WordPress but possible (manual `post_date_gmt`
	 * edits, imports from legacy systems); the explicit guard keeps the
	 * caller-side fallback to {@see TID::generate()} working.
	 */
	public function test_microseconds_from_post_date_returns_zero_for_pre_epoch_date() {
		$this->assertSame( 0, TID::microseconds_from_post_date( '1969-12-31 23:59:00', 42 ) );
		$this->assertSame( 0, TID::microseconds_from_post_date( '1900-01-01 00:00:00', 42 ) );
	}

	/**
	 * A historical-mint with an old timestamp produces a TID that sorts
	 * well before a TID minted from `microtime(true)` — proving the
	 * historical-ordering property end-to-end.
	 */
	public function test_generate_for_time_sorts_before_current() {
		$historical_micros = TID::microseconds_from_post_date( '2010-01-01 00:00:00', 1 );
		$historical        = TID::generate_for_time( $historical_micros );

		$current = TID::generate();

		$this->assertLessThan( $current, $historical, 'A 2010 TID must sort before a now-minted TID.' );
	}

	/**
	 * The salt argument to generate_for_time() makes two records that
	 * map to the same microsecond (e.g. IDs that collide modulo 1M
	 * inside the same GMT second) mint distinct rkeys.
	 */
	public function test_generate_for_time_salt_disambiguates_modulo_collision() {
		$gmt = '2019-03-14 15:09:26';

		// IDs that collide on `% 1_000_000` produce identical microseconds.
		$micros_a = TID::microseconds_from_post_date( $gmt, 42 );
		$micros_b = TID::microseconds_from_post_date( $gmt, 1_000_042 );
		$this->assertSame( $micros_a, $micros_b, 'Setup: ids differing by 1M must share microseconds.' );

		// Without a salt, the rkey collides — that's the documented
		// failure mode we are guarding against.
		$rkey_no_salt_a = TID::generate_for_time( $micros_a );
		$rkey_no_salt_b = TID::generate_for_time( $micros_b );
		$this->assertSame( $rkey_no_salt_a, $rkey_no_salt_b, 'Sanity: no-salt path collides when microseconds match.' );

		// With a per-record salt, the rkeys must differ even though
		// the microsecond portion is identical.
		$rkey_a = TID::generate_for_time( $micros_a, 'post:42' );
		$rkey_b = TID::generate_for_time( $micros_b, 'post:1000042' );
		$this->assertNotSame( $rkey_a, $rkey_b, 'Distinct salts must mint distinct rkeys.' );
	}

	/**
	 * Non-positive microseconds fall back to {@see TID::generate()}
	 * rather than encoding a negative or epoch-anchored value. Mirrors
	 * the parse-failure guard in `microseconds_from_post_date()` and
	 * protects direct callers who hand the helper their own integer.
	 */
	public function test_generate_for_time_rejects_non_positive_microseconds() {
		$zero_tid     = TID::generate_for_time( 0, 'post:1' );
		$negative_tid = TID::generate_for_time( -1, 'post:1' );

		$this->assertTrue( TID::is_valid( $zero_tid ), 'Zero microseconds must fall back to a valid TID.' );
		$this->assertTrue( TID::is_valid( $negative_tid ), 'Negative microseconds must fall back to a valid TID.' );

		// Fall-back path goes through `generate()` which uses the live
		// monotonic floor, so the fallback rkey must sort after a 2010
		// historical TID — the same ordering invariant that proves the
		// fallback didn't accidentally mint a near-epoch garbage value.
		$historical_2010 = TID::generate_for_time(
			TID::microseconds_from_post_date( '2010-01-01 00:00:00', 1 ),
			'post:1'
		);
		$this->assertGreaterThan( $historical_2010, $zero_tid );
		$this->assertGreaterThan( $historical_2010, $negative_tid );
	}

	/**
	 * Microsecond values large enough to overflow into negative when
	 * shifted left by 10 bits also fall back to `generate()`. Guards
	 * against caller bugs (e.g. an accidental double `* 1_000_000`).
	 */
	public function test_generate_for_time_rejects_oversized_microseconds() {
		$overflow_tid = TID::generate_for_time( \PHP_INT_MAX, 'post:1' );

		$this->assertTrue( TID::is_valid( $overflow_tid ), 'Oversized microseconds must fall back to a valid TID.' );
	}

	/**
	 * The namespace argument prevents collisions between WP_Post and
	 * WP_Comment records that share an AT Protocol collection but have
	 * matching IDs and timestamps.
	 */
	public function test_microseconds_from_post_date_namespace_disambiguates() {
		$gmt = '2019-03-14 15:09:26';
		$id  = 42;

		$post    = TID::microseconds_from_post_date( $gmt, $id );
		$comment = TID::microseconds_from_post_date( $gmt, $id, 'comment' );

		$this->assertNotSame( $post, $comment, 'Namespaced and bare results must differ for the same id+second.' );

		// Namespace must be deterministic across calls.
		$comment_again = TID::microseconds_from_post_date( $gmt, $id, 'comment' );
		$this->assertSame( $comment, $comment_again, 'Same namespace + id + second must be idempotent.' );

		// Both still land inside the same GMT second.
		$seconds = (int) \strtotime( $gmt . ' UTC' );
		$this->assertSame( $seconds, \intdiv( $post, 1_000_000 ) );
		$this->assertSame( $seconds, \intdiv( $comment, 1_000_000 ) );
	}
}
