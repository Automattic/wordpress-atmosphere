<?php
/**
 * Cross-process lease lock backed by the options table.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * Options-table lease lock with an owner token and a renewable TTL.
 *
 * The options table's unique option_name index makes INSERT IGNORE atomic,
 * so acquisition works on every WordPress install without advisory-lock
 * support. An expired row is reclaimed with a compare-and-swap update, and
 * the stored value carries an owner token so a stale worker cannot release
 * or renew a newer worker's lease.
 */
class Lock {

	/**
	 * Option row holding the lease.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * Maximum lease age before another worker may reclaim it.
	 *
	 * @var int
	 */
	private int $ttl;

	/**
	 * Serialized lease owned by this request, or null when not held.
	 *
	 * @var string|null
	 */
	private ?string $value = null;

	/**
	 * Stable owner token of the held lease.
	 *
	 * @var string
	 */
	private string $token = '';

	/**
	 * Expiry timestamp of the held lease.
	 *
	 * @var int
	 */
	private int $expires_at = 0;

	/**
	 * Constructor.
	 *
	 * @param string $option Option name backing the lock.
	 * @param int    $ttl    Lease duration in seconds.
	 */
	public function __construct( string $option, int $ttl ) {
		$this->option = $option;
		$this->ttl    = $ttl;
	}

	/**
	 * Acquire the lock.
	 *
	 * Re-acquiring a lease this request already holds returns false; pair
	 * every successful `acquire()` with a `release()` in a `try`/`finally`.
	 *
	 * @return bool Whether this request owns the lock.
	 */
	public function acquire(): bool {
		global $wpdb;

		if ( null !== $this->value ) {
			return false;
		}

		$token      = \wp_generate_uuid4();
		$expires_at = \time() + $this->ttl;
		$value      = self::encode( $token, $expires_at );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$this->option,
				$value,
				'no'
			)
		);

		if ( 1 === (int) $inserted ) {
			$this->own( $value, $token, $expires_at );
			$this->flush_cache( true );
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$this->option
			)
		);

		if ( \is_string( $existing ) && \time() < self::expiry( $existing ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stolen = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$this->option,
				(string) $existing
			)
		);

		if ( 1 !== (int) $stolen ) {
			return false;
		}

		$this->own( $value, $token, $expires_at );
		$this->flush_cache( false );
		return true;
	}

	/**
	 * Renew the lease owned by this request.
	 *
	 * Skips the database entirely while more than half the TTL remains — a
	 * live lease cannot be stolen, so per-item renewal calls stay cheap. A
	 * failed compare-and-swap means another worker reclaimed the lock and
	 * the current worker must stop writing. Calls without an acquired lease
	 * are a no-op: there is no lease to lose.
	 *
	 * @return bool Whether this request still owns the lock.
	 */
	public function renew(): bool {
		global $wpdb;

		if ( null === $this->value ) {
			return true;
		}

		if ( $this->expires_at - \time() > $this->ttl / 2 ) {
			return true;
		}

		$expires_at = \time() + $this->ttl;
		$renewed    = self::encode( $this->token, $expires_at );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$renewed,
				$this->option,
				$this->value
			)
		);

		if ( 1 !== (int) $updated ) {
			return false;
		}

		$this->own( $renewed, $this->token, $expires_at );
		$this->flush_cache( false );
		return true;
	}

	/**
	 * Release this request's lease without deleting a successor's lease.
	 */
	public function release(): void {
		global $wpdb;

		if ( null === $this->value ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $this->option,
				'option_value' => $this->value,
			)
		);

		$this->value = null;
		$this->flush_cache( true );
	}

	/**
	 * Record the lease this request now owns.
	 *
	 * @param string $value      Serialized lease value.
	 * @param string $token      Stable owner token.
	 * @param int    $expires_at Expiry timestamp.
	 */
	private function own( string $value, string $token, int $expires_at ): void {
		$this->value      = $value;
		$this->token      = $token;
		$this->expires_at = $expires_at;
	}

	/**
	 * Build one serialized lease value.
	 *
	 * @param string $token      Stable owner token.
	 * @param int    $expires_at Expiry timestamp.
	 * @return string Serialized lease value.
	 */
	private static function encode( string $token, int $expires_at ): string {
		return (string) \wp_json_encode(
			array(
				'expires_at' => $expires_at,
				'token'      => $token,
			)
		);
	}

	/**
	 * Read the expiry timestamp from a serialized lease value.
	 *
	 * @param string $value Serialized lease value.
	 * @return int Expiry timestamp, or 0 for a malformed value.
	 */
	private static function expiry( string $value ): int {
		$decoded = \json_decode( $value, true );

		return \is_array( $decoded ) ? (int) ( $decoded['expires_at'] ?? 0 ) : 0;
	}

	/**
	 * Invalidate option-cache entries after direct lock-row writes.
	 *
	 * The `notoptions` map only changes when the row is created or removed,
	 * so value-only writes (steal, renew) leave it untouched — wiping it
	 * would force a fresh options query for every unknown option site-wide.
	 *
	 * @param bool $existence_changed Whether the row was created or removed.
	 */
	private function flush_cache( bool $existence_changed ): void {
		\wp_cache_delete( $this->option, 'options' );

		if ( $existence_changed ) {
			\wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
