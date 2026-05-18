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
	 * Option backing the cross-request monotonic counter.
	 *
	 * Persisted because the per-process static below only protects
	 * collisions within one PHP worker. With multiple PHP-FPM workers
	 * (or load-balanced WordPress instances) two concurrent publishes
	 * can otherwise mint identical microsecond timestamps. The option
	 * write costs one extra round-trip per TID; the per-process static
	 * keeps the hot path tight when a single worker mints many TIDs in
	 * succession.
	 *
	 * @var string
	 */
	private const OPTION_LAST_TS = 'atmosphere_tid_last_ts';

	/**
	 * Monotonic counter to avoid collisions within a single process.
	 *
	 * @var int
	 */
	private static int $last_ts = 0;

	/**
	 * Per-process random 10-bit clock identifier.
	 *
	 * Seeded via `random_int` (CSPRNG) so two PHP workers booted from
	 * the same parent process don't end up with the same `wp_rand`
	 * sequence; with 1024 possible clock IDs, a CSPRNG draw is the
	 * cheapest defence against cross-worker TID collisions.
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

		// Cross-request floor: another worker may have minted a TID
		// later than this process's static counter.
		$persisted = (int) \get_option( self::OPTION_LAST_TS, 0 );
		$floor     = \max( self::$last_ts, $persisted );

		if ( $ts <= $floor ) {
			$ts = $floor + 1;
		}

		self::$last_ts = $ts;

		if ( $ts > $persisted ) {
			\update_option( self::OPTION_LAST_TS, $ts, false );
		}

		if ( null === self::$clock_id ) {
			/*
			 * `random_int` throws on systems without a usable CSPRNG
			 * (essentially never on a working PHP install, but a worth
			 * a fallback so a missing entropy source can't bring down
			 * publishing). `wp_rand` is non-cryptographic but the
			 * collision space is still 1024.
			 */
			try {
				self::$clock_id = \random_int( 0, 1023 );
			} catch ( \Throwable $e ) {
				self::$clock_id = \wp_rand( 0, 1023 );
			}
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
