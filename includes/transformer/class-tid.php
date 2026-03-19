<?php
/**
 * AT Protocol Timestamp Identifier (TID) generation.
 *
 * A TID encodes a microsecond-precision timestamp and a 10-bit
 * clock ID into a 13-character base-32 sortable string.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

\defined( 'ABSPATH' ) || exit;

/**
 * TID generator.
 */
class TID {

	/**
	 * Crockford-style base-32 sortable alphabet.
	 *
	 * @var string
	 */
	private const CHARSET = '234567abcdefghijklmnopqrstuvwxyz';

	/**
	 * Fixed output length.
	 *
	 * @var int
	 */
	private const LEN = 13;

	/**
	 * Monotonic counter to avoid collisions within a single process.
	 *
	 * @var int
	 */
	private static int $last_ts = 0;

	/**
	 * Per-process random 10-bit clock identifier.
	 *
	 * @var int|null
	 */
	private static ?int $clock_id = null;

	/**
	 * Generate a fresh TID.
	 *
	 * @return string 13-character identifier.
	 */
	public static function generate(): string {
		$ts = (int) ( \microtime( true ) * 1_000_000 );

		if ( $ts <= self::$last_ts ) {
			$ts = self::$last_ts + 1;
		}

		self::$last_ts = $ts;

		if ( null === self::$clock_id ) {
			self::$clock_id = \wp_rand( 0, 1023 );
		}

		return self::encode( ( $ts << 10 ) | self::$clock_id );
	}

	/**
	 * Check whether a string looks like a valid TID.
	 *
	 * @param string $tid Candidate string.
	 * @return bool
	 */
	public static function is_valid( string $tid ): bool {
		if ( \strlen( $tid ) !== self::LEN ) {
			return false;
		}

		for ( $i = 0; $i < self::LEN; $i++ ) {
			if ( false === \strpos( self::CHARSET, $tid[ $i ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Encode a 64-bit integer into a 13-character base-32 string.
	 *
	 * @param int $value 64-bit value.
	 * @return string
	 */
	private static function encode( int $value ): string {
		$out = '';

		for ( $i = 0; $i < self::LEN; $i++ ) {
			$shift = ( self::LEN - 1 - $i ) * 5;
			$out  .= self::CHARSET[ ( $value >> $shift ) & 0x1F ];
		}

		return $out;
	}
}
