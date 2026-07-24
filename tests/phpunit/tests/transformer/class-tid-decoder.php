<?php
/**
 * Test-only helpers for round-tripping TIDs back to their microsecond
 * value.
 *
 * Kept separate from the production `TID` class so the decode logic
 * doesn't accidentally grow into a public API surface — historical TID
 * decoding is a verification aid for the test suite, not a runtime
 * concern.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests\Transformer;

/**
 * TID decoding helpers for tests.
 */
final class TID_Decoder {

	/**
	 * Crockford-style base-32 sortable alphabet used by `TID::encode()`.
	 *
	 * Mirrored here rather than reflected off the private constant so
	 * the test asserts against the encoded contract, not the
	 * implementation.
	 *
	 * @var string
	 */
	private const CHARSET = '234567abcdefghijklmnopqrstuvwxyz';

	/**
	 * Fixed TID length.
	 *
	 * @var int
	 */
	private const LEN = 13;

	/**
	 * Decode a TID back to its microsecond value.
	 *
	 * Inverts `TID::encode()` + the `( $microseconds << 10 ) | $clock_id`
	 * layout used by both `TID::generate()` and
	 * `TID::generate_for_time()` — strips the 10-bit clock identifier
	 * to recover the original microsecond count.
	 *
	 * @param string $tid 13-character TID.
	 * @return int Microseconds since the Unix epoch.
	 */
	public static function tid_to_microseconds( string $tid ): int {
		$value = 0;

		for ( $i = 0; $i < self::LEN; $i++ ) {
			$index  = \strpos( self::CHARSET, $tid[ $i ] );
			$shift  = ( self::LEN - 1 - $i ) * 5;
			$value |= ( $index << $shift );
		}

		return $value >> 10;
	}
}
