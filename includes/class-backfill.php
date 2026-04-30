<?php
/**
 * AJAX-driven backfill for existing posts.
 *
 * The admin UI requests a count of unsynced posts, then sends
 * batches of post IDs for synchronisation.
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

		$all_ids = \get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		\update_meta_cache( 'post', $all_ids );

		$unsynced = array();
		foreach ( $all_ids as $id ) {
			if ( ! \get_post_meta( $id, Document::META_URI, true ) ) {
				$unsynced[] = $id;
			}

			if ( $limit > 0 && \count( $unsynced ) >= $limit ) {
				break;
			}
		}

		\wp_send_json_success(
			array(
				'total'    => \count( $unsynced ),
				'post_ids' => $unsynced,
			)
		);
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

		// Resolve supported post types once for the whole batch.
		$supported = get_supported_post_types();
		$results   = array();

		foreach ( $post_ids as $post_id ) {
			$post = \get_post( $post_id );

			if ( ! $post || 'publish' !== $post->post_status || ! \in_array( $post->post_type, $supported, true ) ) {
				$results[] = array(
					'id'      => $post_id,
					'success' => false,
					'error'   => \__( 'Post not eligible for sync.', 'atmosphere' ),
				);
				continue;
			}

			$response = Publisher::publish( $post );

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
