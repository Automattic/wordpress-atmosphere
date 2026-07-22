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
			/*
			 * Try the atomic CAS first regardless of whether we think
			 * the row exists. On steady state this is the only branch
			 * that runs and it is a single statement. On the very
			 * first generate() across the whole install the UPDATE
			 * affects zero rows because the option does not exist
			 * yet, and we fall through to the INSERT IGNORE below to
			 * create it.
			 *
			 * The previous shape branched on `0 === $persisted` and
			 * used `update_option` for the first write — which is
			 * unconditional, so two concurrent first publishers
			 * could both observe `persisted = 0` and the smaller-
			 * microtime write would silently regress the floor.
			 * Going through CAS for every write closes that bootstrap
			 * race; the INSERT IGNORE below is also atomic on the
			 * UNIQUE index, so the first concurrent INSERT wins and
			 * the loser falls back to the CAS path on its next call.
			 *
			 * Cast to string for the writes to match how WordPress
			 * stores `option_value`; the WHERE clause casts back to
			 * UNSIGNED for a numeric comparison because the column
			 * is `longtext`. Invalidate the options cache by hand
			 * because `$wpdb->query` does not (unlike
			 * `update_option`).
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
					(string) $ts,
					self::OPTION_LAST_TS,
					$ts
				)
			);

			if ( 0 === $updated ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
						self::OPTION_LAST_TS,
						(string) $ts,
						'no'
					)
				);
			}

			\wp_cache_delete( self::OPTION_LAST_TS, 'options' );
		}

		return self::encode( ( $ts << 10 ) | self::clock_id() );
	}

	/**
	 * Lazily seed and return the per-process 10-bit clock identifier.
	 *
	 * `random_int` throws only on a system without a usable CSPRNG
	 * (essentially never on a working PHP install); `wp_rand` is the
	 * non-cryptographic fallback. Shared by {@see self::generate()} and
	 * {@see self::generate_for_time()} so both mint from the same clock.
	 *
	 * @return int
	 */
	private static function clock_id(): int {
		if ( null === self::$clock_id ) {
			try {
				self::$clock_id = \random_int( 0, 1023 );
			} catch ( \Throwable $e ) {
				self::$clock_id = \wp_rand( 0, 1023 );
			}
		}

		return self::$clock_id;
	}

	/**
	 * Generate a TID for a specific historical time.
	 *
	 * Mints a sortable rkey from a past timestamp WITHOUT reading or
	 * advancing the persisted monotonic floor ({@see self::OPTION_LAST_TS}).
	 * A historical TID is by definition below the live floor, and the
	 * monotonic guarantee only needs to hold among concurrent *live*
	 * mints — so a backfill can sort records by their original publish
	 * date without regressing the floor for live publishing.
	 *
	 * `$disambiguator` occupies the sub-second microsecond slot. WordPress
	 * post dates are second-precision, so that slot is otherwise always
	 * zero; a caller-composed disambiguator keeps records that share a
	 * second from colliding on the same rkey and gives them a stable sort
	 * order (see {@see Base::historical_rkey()}). It is taken modulo
	 * 1,000,000 so it can never spill into the seconds component.
	 *
	 * @param int $unix_seconds  Unix timestamp in seconds (GMT).
	 * @param int $disambiguator Sub-second disambiguator (0–999,999).
	 * @return string 13-character identifier.
	 */
	public static function generate_for_time( int $unix_seconds, int $disambiguator = 0 ): string {
		if ( $unix_seconds <= 0 ) {
			// Unparseable / zero date (e.g. `0000-00-00`): mint a live TID
			// rather than a garbage epoch-1970 rkey.
			return self::generate();
		}

		$micros = $unix_seconds * 1_000_000 + ( $disambiguator % 1_000_000 );

		return self::encode( ( $micros << 10 ) | self::clock_id() );
	}

	/**
	 * Decode a TID to its timestamp component, in microseconds.
	 *
	 * Inverse of the 10-bit clock-id shift {@see self::encode()} applies:
	 * returns the microsecond timestamp the TID sorts on (the low
	 * clock-id bits are dropped). Lets callers verify a record's rkey
	 * maps to an expected publish time.
	 *
	 * @param string $tid 13-character TID.
	 * @return int Microseconds since the Unix epoch.
	 */
	public static function decode( string $tid ): int {
		$value = 0;
		$len   = \strlen( $tid );

		for ( $i = 0; $i < $len; $i++ ) {
			$value = ( $value << 5 ) | (int) \strpos( self::CHARSET, $tid[ $i ] );
		}

		return $value >> 10;
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
