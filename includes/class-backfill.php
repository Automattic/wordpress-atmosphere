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

		$post_types = self::syncable_post_types();

		$all_ids = \get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		\update_meta_cache( 'post', $all_ids );

		$unsynced = array();
		foreach ( $all_ids as $id ) {
			if ( ! \get_post_meta( $id, Document::META_URI, true ) ) {
				$unsynced[] = $id;
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

		$results = array();

		foreach ( $post_ids as $post_id ) {
			$post = \get_post( $post_id );

			if ( ! $post || 'publish' !== $post->post_status ) {
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

	/**
	 * Get the post types eligible for syncing.
	 *
	 * @return string[]
	 */
	public static function syncable_post_types(): array {
		/**
		 * Filters the post types that can be synced to AT Protocol.
		 *
		 * @param string[] $post_types Post type slugs.
		 */
		return \apply_filters( 'atmosphere_syncable_post_types', array( 'post' ) );
	}
}
