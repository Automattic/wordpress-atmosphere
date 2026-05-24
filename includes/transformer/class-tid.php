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

		return self::encode( ( $ts << 10 ) | self::ensure_clock_id() );
	}

	/**
	 * Generate a TID that encodes a specific historical microsecond.
	 *
	 * Unlike {@see self::generate()}, this path is intentionally
	 * floor-free: backfilled records carry timestamps far older than
	 * any current-time floor would allow, so consulting or updating
	 * `OPTION_LAST_TS` (or `self::$last_ts`) would either snap the
	 * value forward to "now" — defeating the entire point — or
	 * regress the floor used by live publishing. Callers are
	 * responsible for supplying a microsecond value that is
	 * collision-resistant within their batch (see
	 * {@see self::microseconds_from_post_date()} for the standard
	 * deterministic helper).
	 *
	 * @since unreleased
	 *
	 * @param int $microseconds Microseconds since the Unix epoch.
	 * @return string 13-character identifier.
	 */
	public static function generate_for_time( int $microseconds ): string {
		return self::encode( ( $microseconds << 10 ) | self::ensure_clock_id() );
	}

	/**
	 * Convert a GMT datetime + object ID into a deterministic microsecond value.
	 *
	 * `post_date_gmt` and `comment_date_gmt` are MySQL second
	 * resolution, so two records published in the same second would
	 * otherwise hash to identical microseconds and collide on the
	 * 10-bit clock identifier within a single backfill run. Mixing
	 * the object ID (modulo one second of microseconds) into the
	 * microsecond portion disambiguates those collisions
	 * deterministically — re-running the backfill mints the same TID
	 * for the same record, which keeps the operation idempotent
	 * against `applyWrites`.
	 *
	 * The `$kind` argument disambiguates records that share an AT
	 * Protocol collection but are sourced from different WordPress
	 * object kinds. Without it, a `WP_Post` with id N and a
	 * `WP_Comment` with `comment_ID` N published in the same GMT
	 * second within a single PHP process would mint the same rkey
	 * inside `app.bsky.feed.post` and `applyWrites` would reject the
	 * second create. The kind label is hashed into a sub-second offset
	 * that's stable across runs (CRC32 is deterministic), preserving
	 * idempotency per kind.
	 *
	 * Returns `0` if the datetime can't be parsed; callers should
	 * decide whether to fall back to {@see self::generate()} in that
	 * case rather than minting an epoch-anchored TID.
	 *
	 * @since unreleased
	 *
	 * @param string $gmt_datetime GMT datetime string (e.g. `post_date_gmt`).
	 * @param int    $object_id    Post or comment identifier for disambiguation.
	 * @param string $kind         Optional kind label to separate records
	 *                             sharing a collection (e.g. `post`, `comment`).
	 * @return int Microseconds since the Unix epoch, or 0 on parse failure.
	 */
	public static function microseconds_from_post_date( string $gmt_datetime, int $object_id, string $kind = '' ): int {
		$trimmed = \trim( $gmt_datetime );

		// MySQL zero-date sentinel and empty strings: bail before
		// `strtotime` (which interprets `0000-00-00 00:00:00` as year
		// zero on some PHP builds, yielding a far-past-or-future
		// timestamp that would mint a meaningless TID).
		if ( '' === $trimmed || '0000-00-00 00:00:00' === $trimmed ) {
			return 0;
		}

		$seconds = \strtotime( $trimmed . ' UTC' );

		if ( false === $seconds || $seconds <= 0 ) {
			return 0;
		}

		$offset = $object_id % 1_000_000;

		if ( '' !== $kind ) {
			/*
			 * Fold the kind label into the same 0..999_999 microsecond
			 * window so the result still lands inside the post's
			 * original GMT second. CRC32 is deterministic across runs,
			 * which preserves the idempotency property — same input
			 * mints the same TID — while moving the two kinds far
			 * enough apart in microsecond-space that an ID-level
			 * collision across kinds is astronomically unlikely.
			 */
			$offset = ( $offset + \crc32( $kind ) ) % 1_000_000;
		}

		return ( $seconds * 1_000_000 ) + $offset;
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
	 * Lazily seed and return the per-process clock identifier.
	 *
	 * Shared by {@see self::generate()} and {@see self::generate_for_time()}.
	 * `random_int` throws on systems without a usable CSPRNG (essentially
	 * never on a working PHP install, but worth a fallback so a missing
	 * entropy source can't bring down publishing). `wp_rand` is
	 * non-cryptographic but the collision space is still 1024.
	 *
	 * @return int 10-bit clock identifier.
	 */
	private static function ensure_clock_id(): int {
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
