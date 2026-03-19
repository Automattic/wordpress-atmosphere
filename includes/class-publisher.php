<?php
/**
 * Orchestrates publishing WordPress posts to the AT Protocol.
 *
 * Every publish creates both an app.bsky.feed.post and a
 * site.standard.document atomically via a single applyWrites call.
 * Updates and deletions follow the same pattern.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Publication;

/**
 * Publisher class.
 */
class Publisher {

	/**
	 * Publish a post to AT Protocol (both record types).
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error applyWrites response or error.
	 */
	public static function publish( \WP_Post $post ): array|\WP_Error {
		$bsky_transformer = new Post( $post );
		$doc_transformer  = new Document( $post );

		// Ensure TIDs exist (generates if needed).
		$bsky_rkey = $bsky_transformer->get_rkey();
		$doc_rkey  = $doc_transformer->get_rkey();

		// Build records.
		$bsky_record = $bsky_transformer->transform();
		$doc_record  = $doc_transformer->transform();

		$writes = array(
			array(
				'$type'      => 'com.atproto.repo.applyWrites#create',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $bsky_rkey,
				'value'      => $bsky_record,
			),
			array(
				'$type'      => 'com.atproto.repo.applyWrites#create',
				'collection' => 'site.standard.document',
				'rkey'       => $doc_rkey,
				'value'      => $doc_record,
			),
		);

		$result = API::apply_writes( $writes );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		// Store URIs and CIDs from the response.
		self::store_results( $post->ID, $result, $bsky_transformer, $doc_transformer );

		// Follow-up: update document with bsky post reference (now that we have the CID).
		self::update_document_bsky_ref( $post, $doc_transformer );

		return $result;
	}

	/**
	 * Update both records for an existing post.
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error
	 */
	public static function update( \WP_Post $post ): array|\WP_Error {
		$bsky_tid = \get_post_meta( $post->ID, Post::META_TID, true );
		$doc_tid  = \get_post_meta( $post->ID, Document::META_TID, true );

		if ( ! $bsky_tid || ! $doc_tid ) {
			// Not yet published — do a fresh publish instead.
			return self::publish( $post );
		}

		$bsky_transformer = new Post( $post );
		$doc_transformer  = new Document( $post );

		$writes = array(
			array(
				'$type'      => 'com.atproto.repo.applyWrites#update',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $bsky_tid,
				'value'      => $bsky_transformer->transform(),
			),
			array(
				'$type'      => 'com.atproto.repo.applyWrites#update',
				'collection' => 'site.standard.document',
				'rkey'       => $doc_tid,
				'value'      => $doc_transformer->transform(),
			),
		);

		$result = API::apply_writes( $writes );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		self::store_results( $post->ID, $result, $bsky_transformer, $doc_transformer );

		return $result;
	}

	/**
	 * Delete both records for a post.
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error
	 */
	public static function delete( \WP_Post $post ): array|\WP_Error {
		$bsky_tid = \get_post_meta( $post->ID, Post::META_TID, true );
		$doc_tid  = \get_post_meta( $post->ID, Document::META_TID, true );

		if ( ! $bsky_tid && ! $doc_tid ) {
			return new \WP_Error( 'atmosphere_not_published', \__( 'Post has no AT Protocol records.', 'atmosphere' ) );
		}

		$writes = array();

		if ( $bsky_tid ) {
			$writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $bsky_tid,
			);
		}

		if ( $doc_tid ) {
			$writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'site.standard.document',
				'rkey'       => $doc_tid,
			);
		}

		$result = API::apply_writes( $writes );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		// Clean up post meta.
		\delete_post_meta( $post->ID, Post::META_TID );
		\delete_post_meta( $post->ID, Post::META_URI );
		\delete_post_meta( $post->ID, Post::META_CID );
		\delete_post_meta( $post->ID, Document::META_TID );
		\delete_post_meta( $post->ID, Document::META_URI );
		\delete_post_meta( $post->ID, Document::META_CID );

		return $result;
	}

	/**
	 * Publish or update the site.standard.publication record.
	 *
	 * @return array|\WP_Error
	 */
	public static function sync_publication(): array|\WP_Error {
		$pub = new Publication( null );

		$existing_uri = \get_option( Publication::OPTION_URI );

		if ( $existing_uri ) {
			// Update existing.
			$result = API::post(
				'/xrpc/com.atproto.repo.putRecord',
				array(
					'repo'       => get_did(),
					'collection' => 'site.standard.publication',
					'rkey'       => $pub->get_rkey(),
					'record'     => $pub->transform(),
				)
			);
		} else {
			// Create new.
			$result = API::post(
				'/xrpc/com.atproto.repo.createRecord',
				array(
					'repo'       => get_did(),
					'collection' => 'site.standard.publication',
					'rkey'       => $pub->get_rkey(),
					'record'     => $pub->transform(),
				)
			);
		}

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		$uri = $result['uri'] ?? $pub->get_uri();
		\update_option( Publication::OPTION_URI, $uri, false );

		return $result;
	}

	/**
	 * Extract URIs/CIDs from applyWrites response and store in post meta.
	 *
	 * @param int      $post_id          Post ID.
	 * @param array    $result           applyWrites response.
	 * @param Post     $bsky_transformer Bsky transformer.
	 * @param Document $doc_transformer  Document transformer.
	 */
	private static function store_results( int $post_id, array $result, Post $bsky_transformer, Document $doc_transformer ): void {
		$results = $result['results'] ?? array();

		foreach ( $results as $i => $item ) {
			$uri = $item['uri'] ?? '';
			$cid = $item['cid'] ?? '';

			if ( 0 === $i ) {
				// First write = bsky post.
				if ( $uri ) {
					\update_post_meta( $post_id, Post::META_URI, $uri );
				} else {
					\update_post_meta( $post_id, Post::META_URI, $bsky_transformer->get_uri() );
				}
				if ( $cid ) {
					\update_post_meta( $post_id, Post::META_CID, $cid );
				}
			} elseif ( 1 === $i ) {
				// Second write = document.
				if ( $uri ) {
					\update_post_meta( $post_id, Document::META_URI, $uri );
				} else {
					\update_post_meta( $post_id, Document::META_URI, $doc_transformer->get_uri() );
				}
				if ( $cid ) {
					\update_post_meta( $post_id, Document::META_CID, $cid );
				}
			}
		}
	}

	/**
	 * Update the document record with the bsky post strong reference.
	 *
	 * After the initial applyWrites, we now know the bsky post's CID,
	 * so we update the document to include the bskyPostRef field.
	 *
	 * @param \WP_Post $post            WordPress post.
	 * @param Document $doc_transformer Document transformer.
	 */
	private static function update_document_bsky_ref( \WP_Post $post, Document $doc_transformer ): void {
		$bsky_uri = \get_post_meta( $post->ID, Post::META_URI, true );
		$bsky_cid = \get_post_meta( $post->ID, Post::META_CID, true );

		if ( ! $bsky_uri || ! $bsky_cid ) {
			return;
		}

		// Re-transform the document (now includes the bskyPostRef).
		$updated_doc = new Document( $post );
		$record      = $updated_doc->transform();

		API::post(
			'/xrpc/com.atproto.repo.putRecord',
			array(
				'repo'       => get_did(),
				'collection' => 'site.standard.document',
				'rkey'       => $doc_transformer->get_rkey(),
				'record'     => $record,
			)
		);
	}
}
