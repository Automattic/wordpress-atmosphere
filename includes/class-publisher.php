<?php
/**
 * Orchestrates publishing WordPress content to the AT Protocol.
 *
 * Posts go out as an app.bsky.feed.post + site.standard.document
 * pair via a single applyWrites call. Comments go out as bsky reply
 * records. The generic publish/update/delete entry points dispatch
 * by object type so callers can stay polymorphic.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Transformer\Comment;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Publication;

/**
 * Publisher class.
 */
class Publisher {

	/**
	 * Dispatch a publish by object type.
	 *
	 * @param \WP_Post|\WP_Comment $object WordPress post or comment.
	 * @return array|\WP_Error
	 */
	public static function publish( \WP_Post|\WP_Comment $object ): array|\WP_Error { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		if ( $object instanceof \WP_Comment ) {
			return self::publish_comment( $object );
		}

		return self::publish_post( $object );
	}

	/**
	 * Dispatch an update by object type.
	 *
	 * @param \WP_Post|\WP_Comment $object WordPress post or comment.
	 * @return array|\WP_Error
	 */
	public static function update( \WP_Post|\WP_Comment $object ): array|\WP_Error { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		if ( $object instanceof \WP_Comment ) {
			return self::update_comment( $object );
		}

		return self::update_post( $object );
	}

	/**
	 * Dispatch a delete by object type.
	 *
	 * @param \WP_Post|\WP_Comment $object WordPress post or comment.
	 * @return array|\WP_Error
	 */
	public static function delete( \WP_Post|\WP_Comment $object ): array|\WP_Error { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		if ( $object instanceof \WP_Comment ) {
			return self::delete_comment( $object );
		}

		return self::delete_post( $object );
	}

	/**
	 * Publish a post to AT Protocol (both record types).
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error applyWrites response or error.
	 */
	public static function publish_post( \WP_Post $post ): array|\WP_Error {
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
		self::store_post_result( $post->ID, $result, $bsky_transformer, $doc_transformer );

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
	public static function update_post( \WP_Post $post ): array|\WP_Error {
		$bsky_uri = \get_post_meta( $post->ID, Post::META_URI, true );
		$doc_uri  = \get_post_meta( $post->ID, Document::META_URI, true );

		if ( ! $bsky_uri || ! $doc_uri ) {
			// Not yet published — do a fresh publish instead.
			return self::publish_post( $post );
		}

		$bsky_tid = \get_post_meta( $post->ID, Post::META_TID, true );
		$doc_tid  = \get_post_meta( $post->ID, Document::META_TID, true );

		if ( ! $bsky_tid || ! $doc_tid ) {
			return new \WP_Error( 'atmosphere_missing_tid', \__( 'Record URIs exist but TIDs are missing.', 'atmosphere' ) );
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

		self::store_post_result( $post->ID, $result, $bsky_transformer, $doc_transformer );

		// Update document with bsky post reference (CID may have changed).
		self::update_document_bsky_ref( $post, $doc_transformer );

		return $result;
	}

	/**
	 * Delete both records for a post, plus any outbound comment replies.
	 *
	 * Comment reply records live in our own repo keyed by their own
	 * TIDs — the AT Protocol has no cascade semantics, so deleting the
	 * root post does not remove them. If we do not include them here,
	 * unpublishing a post leaves the comment replies on Bluesky with
	 * no remaining pointer from WordPress that could clean them up.
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error
	 */
	public static function delete_post( \WP_Post $post ): array|\WP_Error {
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

		$comment_tids = self::collect_published_comment_tids( $post->ID );
		foreach ( $comment_tids as $comment_tid ) {
			$writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $comment_tid['tid'],
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

		// Clean up comment meta for every reply we just deleted.
		foreach ( $comment_tids as $comment_tid ) {
			\delete_comment_meta( $comment_tid['comment_id'], Comment::META_TID );
			\delete_comment_meta( $comment_tid['comment_id'], Comment::META_URI );
			\delete_comment_meta( $comment_tid['comment_id'], Comment::META_CID );
			\delete_comment_meta( $comment_tid['comment_id'], Reaction_Sync::META_SOURCE_ID );
		}

		return $result;
	}

	/**
	 * Collect { comment_id, tid } pairs for all outbound comment replies
	 * on a post. Only comments that actually reached the PDS (META_URI
	 * present) are returned — stale TIDs from a previously-failed
	 * publish would refer to a non-existent record and the delete would
	 * fail.
	 *
	 * Public so the permanent-delete path (`on_before_delete`) can
	 * collect the same TIDs while comments still exist, before WP's
	 * natural cascade removes them.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array{comment_id:int, tid:string}>
	 */
	public static function collect_published_comment_tids( int $post_id ): array {
		$comments = \get_comments(
			array(
				'post_id'    => $post_id,
				'status'     => 'any',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Comment::META_URI,
						'compare' => 'EXISTS',
					),
				),
				'fields'     => 'ids',
			)
		);

		$out = array();

		foreach ( $comments as $comment_id ) {
			$tid = \get_comment_meta( (int) $comment_id, Comment::META_TID, true );
			if ( ! empty( $tid ) ) {
				$out[] = array(
					'comment_id' => (int) $comment_id,
					'tid'        => (string) $tid,
				);
			}
		}

		return $out;
	}

	/**
	 * Delete post AT Protocol records by TID, without requiring the post to exist.
	 *
	 * Used when a post is permanently deleted and post meta is no longer available.
	 *
	 * @param string   $bsky_tid     Bluesky post TID (may be empty).
	 * @param string   $doc_tid      Document TID (may be empty).
	 * @param string[] $comment_tids Comment reply TIDs to delete in the same batch.
	 * @return array|\WP_Error
	 */
	public static function delete_post_by_tids( string $bsky_tid, string $doc_tid, array $comment_tids = array() ): array|\WP_Error {
		if ( ! $bsky_tid && ! $doc_tid && empty( $comment_tids ) ) {
			return new \WP_Error( 'atmosphere_not_published', \__( 'No TIDs provided.', 'atmosphere' ) );
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

		foreach ( $comment_tids as $comment_tid ) {
			if ( '' === (string) $comment_tid ) {
				continue;
			}
			$writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => (string) $comment_tid,
			);
		}

		return API::apply_writes( $writes );
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
	 * Publish a WordPress comment as an app.bsky.feed.post reply.
	 *
	 * @param \WP_Comment $comment WordPress comment.
	 * @return array|\WP_Error applyWrites response or error.
	 */
	public static function publish_comment( \WP_Comment $comment ): array|\WP_Error {
		$transformer = new Comment( $comment );
		$rkey        = $transformer->get_rkey();
		$record      = $transformer->transform();

		$writes = array(
			array(
				'$type'      => 'com.atproto.repo.applyWrites#create',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $rkey,
				'value'      => $record,
			),
		);

		$result = API::apply_writes( $writes );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		$stored = self::store_comment_result( (int) $comment->comment_ID, $result );
		if ( \is_wp_error( $stored ) ) {
			return $stored;
		}

		return $result;
	}

	/**
	 * Update an existing bsky reply for a WordPress comment.
	 *
	 * Falls through to publish_comment when no URI is stored — the URI
	 * is only written after a successful API call, so an absent URI
	 * means the record was never created on the PDS. Keying off the
	 * TID instead would be unsafe because Comment::get_rkey() persists
	 * the TID before the API call, so a failed publish would leave the
	 * TID present and send every subsequent attempt down the #update
	 * path for a record that does not exist.
	 *
	 * @param \WP_Comment $comment WordPress comment.
	 * @return array|\WP_Error
	 */
	public static function update_comment( \WP_Comment $comment ): array|\WP_Error {
		$comment_id = (int) $comment->comment_ID;
		$uri        = \get_comment_meta( $comment_id, Comment::META_URI, true );

		if ( empty( $uri ) ) {
			return self::publish_comment( $comment );
		}

		$tid = \get_comment_meta( $comment_id, Comment::META_TID, true );

		if ( empty( $tid ) ) {
			return new \WP_Error( 'atmosphere_missing_tid', \__( 'Comment URI exists but TID is missing.', 'atmosphere' ) );
		}

		$transformer = new Comment( $comment );

		$writes = array(
			array(
				'$type'      => 'com.atproto.repo.applyWrites#update',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $tid,
				'value'      => $transformer->transform(),
			),
		);

		$result = API::apply_writes( $writes );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		$stored = self::store_comment_result( $comment_id, $result );
		if ( \is_wp_error( $stored ) ) {
			return $stored;
		}

		return $result;
	}

	/**
	 * Delete the bsky reply record for a WordPress comment.
	 *
	 * Keys off META_URI rather than META_TID so a previously-failed
	 * publish (which persisted the TID but never wrote the URI) is
	 * correctly treated as nothing-to-delete.
	 *
	 * @param \WP_Comment $comment WordPress comment.
	 * @return array|\WP_Error
	 */
	public static function delete_comment( \WP_Comment $comment ): array|\WP_Error {
		$comment_id = (int) $comment->comment_ID;
		$uri        = \get_comment_meta( $comment_id, Comment::META_URI, true );

		if ( empty( $uri ) ) {
			return new \WP_Error( 'atmosphere_not_published', \__( 'Comment has no AT Protocol record.', 'atmosphere' ) );
		}

		$tid = \get_comment_meta( $comment_id, Comment::META_TID, true );

		if ( empty( $tid ) ) {
			return new \WP_Error( 'atmosphere_missing_tid', \__( 'Comment URI exists but TID is missing.', 'atmosphere' ) );
		}

		$writes = array(
			array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $tid,
			),
		);

		$result = API::apply_writes( $writes );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		\delete_comment_meta( $comment_id, Comment::META_TID );
		\delete_comment_meta( $comment_id, Comment::META_URI );
		\delete_comment_meta( $comment_id, Comment::META_CID );
		\delete_comment_meta( $comment_id, Reaction_Sync::META_SOURCE_ID );

		return $result;
	}

	/**
	 * Delete a bsky comment reply by TID, without needing the comment row.
	 *
	 * Used when a comment is permanently deleted and its meta is no
	 * longer reachable at the point the cron fires.
	 *
	 * @param string $tid Comment record TID.
	 * @return array|\WP_Error
	 */
	public static function delete_comment_by_tid( string $tid ): array|\WP_Error {
		if ( '' === $tid ) {
			return new \WP_Error( 'atmosphere_not_published', \__( 'No TID provided.', 'atmosphere' ) );
		}

		$writes = array(
			array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $tid,
			),
		);

		return API::apply_writes( $writes );
	}

	/**
	 * Extract URIs/CIDs from an applyWrites response and store in post meta.
	 *
	 * @param int      $post_id          Post ID.
	 * @param array    $result           applyWrites response.
	 * @param Post     $bsky_transformer Bsky transformer.
	 * @param Document $doc_transformer  Document transformer.
	 */
	private static function store_post_result( int $post_id, array $result, Post $bsky_transformer, Document $doc_transformer ): void {
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
	 * Store the applyWrites response for a comment publish/update.
	 *
	 * Mirrors the comment's AT-URI into Reaction_Sync::META_SOURCE_ID so
	 * that when listRecords feeds our own reply back through the inbound
	 * sync, find_comment_by_source_id() matches this row and skips it.
	 *
	 * Treats a 2xx response that omits `results[0].uri` as a failure
	 * and returns a WP_Error. A locally-synthesized URI fallback would
	 * make a malformed server response indistinguishable from a clean
	 * publish, poison the dedup key, and steer later update/delete
	 * calls at a record that may not exist.
	 *
	 * @param int   $comment_id WordPress comment ID.
	 * @param array $result     applyWrites response.
	 * @return true|\WP_Error True on success, WP_Error on missing URI.
	 */
	private static function store_comment_result( int $comment_id, array $result ): true|\WP_Error {
		$first = $result['results'][0] ?? array();
		$uri   = $first['uri'] ?? '';
		$cid   = $first['cid'] ?? '';

		if ( '' === $uri ) {
			return new \WP_Error(
				'atmosphere_missing_uri',
				\__( 'applyWrites response did not include a record URI.', 'atmosphere' )
			);
		}

		\update_comment_meta( $comment_id, Comment::META_URI, $uri );
		\update_comment_meta( $comment_id, Reaction_Sync::META_SOURCE_ID, $uri );

		if ( $cid ) {
			\update_comment_meta( $comment_id, Comment::META_CID, $cid );
		}

		return true;
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
