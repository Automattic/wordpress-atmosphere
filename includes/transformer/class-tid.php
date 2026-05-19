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
		global $wpdb;

		$ts = (int) ( \microtime( true ) * 1_000_000 );

		/*
		 * Why direct $wpdb instead of `update_option()` here?
		 * The whole point of this persisted floor is a cross-worker
		 * monotonic guarantee: two PHP-FPM workers minting TIDs at
		 * the same microsecond must end up with distinct rkeys, and
		 * the persisted value must never regress. `update_option`
		 * does a read-modify-write at the PHP layer with no
		 * conditional on the existing row, so the obvious shape —
		 * read $persisted, compute max, write it back — is racy:
		 *
		 *   T0  worker A: get_option = 100
		 *   T1  worker B: get_option = 100
		 *   T2  worker A: ts = 105, update_option(105)  -> floor=105
		 *   T3  worker B: ts = 103, update_option(103)  -> floor=103  ← regress
		 *
		 * The slower worker silently regresses the floor and the
		 * monotonic invariant the docblock promises evaporates.
		 * Subsequent TIDs fall back to the 10-bit `clock_id` for
		 * collision avoidance, which is fine 1023/1024 of the time
		 * but is not "monotonic" in any meaningful sense.
		 *
		 * `UPDATE ... WHERE CAST(option_value AS UNSIGNED) < $ts`
		 * is a single atomic statement: the row is rewritten only
		 * when the new candidate strictly exceeds the stored one.
		 * Worker B at T3 above no-ops because 103 < 105 fails the
		 * WHERE. Combined with the per-process `self::$last_ts`
		 * for the hot path, this gives us a true monotonic floor
		 * without an option-write per call (worker B never even
		 * issues the UPDATE if its candidate is already below the
		 * static).
		 */
		$persisted = (int) \get_option( self::OPTION_LAST_TS, 0 );
		$floor     = \max( self::$last_ts, $persisted );

		if ( $ts <= $floor ) {
			$ts = $floor + 1;
		}

		self::$last_ts = $ts;

		if ( $ts > $persisted ) {
			if ( 0 === $persisted ) {
				/*
				 * First-write: there is no row to UPDATE against yet,
				 * so the standard options API creates it. Subsequent
				 * advances go through the atomic UPDATE branch below.
				 * `autoload=false` keeps the row out of the
				 * always-loaded options cache; the per-process static
				 * is what makes the hot path tight.
				 */
				\update_option( self::OPTION_LAST_TS, (string) $ts, false );
			} else {
				/*
				 * Atomic advance via compare-and-swap on the option
				 * value. Cast to string for the write to match how
				 * WordPress stores `option_value`; the WHERE clause
				 * casts back to UNSIGNED for a numeric comparison
				 * because the column is `longtext`. Invalidate the
				 * options cache by hand because `$wpdb->query` does
				 * not (unlike `update_option`).
				 */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
						(string) $ts,
						self::OPTION_LAST_TS,
						$ts
					)
				);
				\wp_cache_delete( self::OPTION_LAST_TS, 'options' );
			}
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
