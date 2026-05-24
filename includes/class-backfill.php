<?php
/**
 * AJAX-driven backfill for existing posts.
 *
 * The admin UI requests a count of unsynced posts, then sends
 * batches of post IDs for synchronisation. The CLI command in
 * {@see CLI::backfill()} reuses {@see Backfill::get_unsynced_post_ids()}
 * so the two paths cannot drift on which posts qualify for sync.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Transformer\Document;

/**
 * Backfill handler.
 */
class Backfill {

	/**
	 * Default chunk size for the paged unsynced-posts walk.
	 *
	 * Tuned for "large catalogue with a small unsynced tail" — the
	 * common case once a site has been running this plugin for a
	 * while. Smaller chunks fire more queries but cap meta-cache
	 * memory at $chunk * (meta rows per post); larger chunks reduce
	 * round trips at the cost of cache footprint.
	 *
	 * Overridable via the `atmosphere_backfill_query_chunk_size`
	 * filter so tests can drive the loop with small fixtures and
	 * operators can tune memory pressure on weird catalogues.
	 *
	 * @var int
	 */
	private const DEFAULT_QUERY_CHUNK_SIZE = 500;

	/**
	 * Register AJAX hooks.
	 */
	public static function register(): void {
		\add_action( 'wp_ajax_atmosphere_backfill_count', array( self::class, 'handle_count' ) );
		\add_action( 'wp_ajax_atmosphere_backfill_batch', array( self::class, 'handle_batch' ) );
	}

	/**
	 * AJAX: count unsynced published posts.
	 */
	public static function handle_count(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( 'Unauthorized.', 403 );
		}

		\check_ajax_referer( 'atmosphere_backfill', 'nonce' );

		$post_types = get_supported_post_types();

		/*
		 * Short-circuit when no post types are enabled. Passing an empty
		 * array to get_posts() falls back to the default `post` query,
		 * which would surface posts that nothing is configured to sync.
		 */
		if ( empty( $post_types ) ) {
			\wp_send_json_success(
				array(
					'total'    => 0,
					'post_ids' => array(),
				)
			);
		}

		/**
		 * Filters the maximum number of posts to backfill.
		 *
		 * Only the most recent unsynced posts (up to this limit) will
		 * be included in each backfill run. Use 0 or -1 to backfill all.
		 *
		 * @param int $limit Maximum number of posts. Default 10.
		 */
		$limit = (int) \apply_filters( 'atmosphere_backfill_limit', 10 );

		$unsynced = self::get_unsynced_post_ids( $limit, $post_types );

		\wp_send_json_success(
			array(
				'total'    => \count( $unsynced ),
				'post_ids' => $unsynced,
			)
		);
	}

	/**
	 * Get post IDs for published posts that have not been synced yet.
	 *
	 * Shared between {@see Backfill::handle_count()} (the admin AJAX
	 * path) and {@see CLI::backfill()} (the WP-CLI path) so the two
	 * surfaces cannot drift on eligibility rules — anything that
	 * tightens the unsynced query in one place tightens it in both.
	 *
	 * Returns the most-recent posts first. The result is capped at
	 * `$limit` when `$limit > 0`; pass `0` (or any non-positive value)
	 * for no cap. Posts that already carry the `Document::META_URI`
	 * marker are excluded — that meta is the signal Publisher sets
	 * after a successful publish.
	 *
	 * Walks the candidate set in chunks (size configurable via the
	 * `atmosphere_backfill_query_chunk_size` filter, default 500) so a
	 * 50k-post catalogue does not load every ID and prime every meta
	 * row before the limit is even checked. Each chunk is primed with
	 * `update_meta_cache()` so the per-post `get_post_meta()` reads do
	 * not fire one SELECT per ID. `no_found_rows` skips the
	 * `SQL_CALC_FOUND_ROWS` overhead — we never need the total count.
	 *
	 * @since unreleased
	 *
	 * @param int      $limit      Maximum IDs to return. 0 or negative for no cap.
	 * @param string[] $post_types Post type slugs to include. Caller should
	 *                             pass {@see get_supported_post_types()}; an
	 *                             empty array short-circuits to an empty
	 *                             result so we do not fall back to the
	 *                             default `post` query.
	 * @return int[] Post IDs that still need to be synced.
	 */
	public static function get_unsynced_post_ids( int $limit, array $post_types ): array {
		if ( empty( $post_types ) ) {
			return array();
		}

		/**
		 * Filters the page size for the paged unsynced-posts walk.
		 *
		 * Operators can lower the value on memory-constrained installs
		 * or pathological catalogues with very wide meta rows, or raise
		 * it to trade memory for fewer queries on healthy hosts. The
		 * filter also gives the test suite a knob for driving the paged
		 * loop with small fixtures.
		 *
		 * @since unreleased
		 *
		 * @param int $chunk_size Posts per page. Default 500.
		 */
		$chunk_size = (int) \apply_filters(
			'atmosphere_backfill_query_chunk_size',
			self::DEFAULT_QUERY_CHUNK_SIZE
		);

		/*
		 * Clamp to at least 1. <= 0 would loop forever without consuming
		 * rows. `max()` approximates an operator's "smaller chunks please"
		 * intent rather than silently reverting to the default — which
		 * would mask filter misconfiguration in production logs.
		 */
		$chunk_size = \max( 1, $chunk_size );

		$unsynced = array();
		$page     = 1;

		do {
			$chunk = \get_posts(
				array(
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					'has_password'           => false,
					'posts_per_page'         => $chunk_size,
					'paged'                  => $page,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);

			$chunk_count = \count( $chunk );

			if ( 0 === $chunk_count ) {
				break;
			}

			\update_meta_cache( 'post', $chunk );

			foreach ( $chunk as $id ) {
				if ( ! \get_post_meta( $id, Document::META_URI, true ) ) {
					$unsynced[] = (int) $id;
				}

				if ( $limit > 0 && \count( $unsynced ) >= $limit ) {
					break 2;
				}
			}

			/*
			 * Drop the chunk's primed post_meta rows before the next page
			 * loads. Without this, a 50k-post walk accumulates meta for
			 * every visited row in the in-process cache for the rest of
			 * the request, undoing most of the bound-memory benefit of
			 * the paged walk.
			 */
			\wp_cache_delete_multiple( $chunk, 'post_meta' );

			++$page;
		} while ( $chunk_count === $chunk_size );

		return $unsynced;
	}

	/**
	 * AJAX: sync a batch of post IDs.
	 */
	public static function handle_batch(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( 'Unauthorized.', 403 );
		}

		\check_ajax_referer( 'atmosphere_backfill', 'nonce' );

		$post_ids = isset( $_POST['post_ids'] )
			? \array_map( 'absint', (array) $_POST['post_ids'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();

		if ( empty( $post_ids ) ) {
			\wp_send_json_error( 'No post IDs provided.' );
		}

		$results = array();

		foreach ( $post_ids as $post_id ) {
			$post = \get_post( $post_id );

			if ( ! $post || ! is_post_publishable( $post ) ) {
				$results[] = array(
					'id'      => $post_id,
					'success' => false,
					'error'   => \__( 'Post not eligible for sync.', 'atmosphere' ),
				);
				continue;
			}

			$response = Publisher::publish_post( $post );

			if ( \is_wp_error( $response ) ) {
				$results[] = array(
					'id'      => $post_id,
					'title'   => \get_the_title( $post ),
					'success' => false,
					'error'   => $response->get_error_message(),
				);
			} else {
				$results[] = array(
					'id'      => $post_id,
					'title'   => \get_the_title( $post ),
					'success' => true,
				);
			}
		}

		\wp_send_json_success( array( 'results' => $results ) );
	}
}
