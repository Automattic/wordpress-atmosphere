<?php
/**
 * Orchestrates publishing WordPress posts to the AT Protocol.
 *
 * A WordPress post corresponds to:
 * - One or more `app.bsky.feed.post` records (a thread, for the
 *   long-form `teaser-thread` strategy).
 * - Exactly one `site.standard.document` record.
 *
 * Single-record publishes (short-form, link-card, truncate-link) use
 * one atomic `applyWrites` call with the bsky post + doc. Threads
 * write the root + doc atomically, then each reply as its own
 * `applyWrites` call so reply refs can carry the parent's CID (the
 * PDS only returns CIDs in the response, so we can't assemble a
 * single atomic batch for the full thread).
 *
 * Thread publishes persist partial meta after each successful write,
 * so an interrupted thread can be surfaced for retry. A mid-thread
 * failure issues compensating `applyWrites#delete` calls in reverse
 * order to roll back to a "nothing published" state.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Publication;
use Atmosphere\Transformer\TID;

/**
 * Publisher class.
 */
class Publisher {

	/**
	 * Publish a post to AT Protocol (bsky record(s) + document).
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error applyWrites response(s) or error.
	 */
	public static function publish( \WP_Post $post ): array|\WP_Error {
		$bsky_transformer = new Post( $post );
		$doc_transformer  = new Document( $post );

		// Ensure TIDs exist (generates if needed).
		$bsky_transformer->get_rkey();
		$doc_transformer->get_rkey();

		if ( $bsky_transformer->is_short_form_post() ) {
			// Short-form path: single record via today's transform().
			return self::publish_single(
				$post,
				$bsky_transformer->transform(),
				$bsky_transformer,
				$doc_transformer
			);
		}

		$records = $bsky_transformer->build_long_form_records();

		if ( 1 === \count( $records ) ) {
			return self::publish_single( $post, $records[0], $bsky_transformer, $doc_transformer );
		}

		return self::publish_thread( $post, $records, $bsky_transformer, $doc_transformer );
	}

	/**
	 * Write a single bsky post + document atomically.
	 *
	 * Used for short-form (via `transform()`'s output) and for the
	 * `link-card` / `truncate-link` long-form strategies (via
	 * `build_long_form_records()`'s single-element output).
	 *
	 * `createdAt` defaults to `wp_date( 'c' )` when the record doesn't
	 * already carry one. `transform()` sets `createdAt` from the post's
	 * `post_date_gmt` (preserving today's short-form behavior);
	 * `build_long_form_records()` omits it on purpose.
	 *
	 * @param \WP_Post $post             WordPress post.
	 * @param array    $bsky_record      Pre-composed bsky post record.
	 * @param Post     $bsky_transformer Bsky transformer instance.
	 * @param Document $doc_transformer  Document transformer instance.
	 * @return array|\WP_Error
	 */
	private static function publish_single(
		\WP_Post $post,
		array $bsky_record,
		Post $bsky_transformer,
		Document $doc_transformer
	): array|\WP_Error {
		if ( empty( $bsky_record['createdAt'] ) ) {
			$bsky_record['createdAt'] = \wp_date( 'c' );
		}

		$writes = array(
			array(
				'$type'      => 'com.atproto.repo.applyWrites#create',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $bsky_transformer->get_rkey(),
				'value'      => $bsky_record,
			),
			array(
				'$type'      => 'com.atproto.repo.applyWrites#create',
				'collection' => 'site.standard.document',
				'rkey'       => $doc_transformer->get_rkey(),
				'value'      => $doc_transformer->transform(),
			),
		);

		$result = API::apply_writes( $writes );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		self::store_results( $post->ID, $result, $bsky_transformer, $doc_transformer );
		self::mirror_thread_records_meta(
			$post->ID,
			array(
				self::build_triple_from_result(
					$result,
					0,
					$bsky_transformer->get_uri(),
					$bsky_transformer->get_rkey()
				),
			)
		);
		self::update_document_bsky_ref( $post, $doc_transformer );

		return $result;
	}

	/**
	 * Sequential-writes-with-rollback for thread-strategy publishes.
	 *
	 * Step 1 writes root + doc atomically. Partial meta is persisted
	 * immediately so crash recovery has a pointer to the root record.
	 * Step 2..N writes each reply on its own, with reply refs derived
	 * from the already-persisted thread records. Meta is updated after
	 * each successful create so an interrupted thread is visible.
	 *
	 * On any reply failure, compensating deletes run in reverse order
	 * (tail-first), all meta is cleared, and the original failing
	 * WP_Error is returned. If rollback also fails, the return wraps
	 * both errors and includes the partial thread state.
	 *
	 * @param \WP_Post $post             WordPress post.
	 * @param array[]  $records          Records from build_long_form_records().
	 * @param Post     $bsky_transformer Bsky transformer instance.
	 * @param Document $doc_transformer  Document transformer instance.
	 * @return array|\WP_Error
	 */
	private static function publish_thread(
		\WP_Post $post,
		array $records,
		Post $bsky_transformer,
		Document $doc_transformer
	): array|\WP_Error {
		$root_record              = $records[0];
		$root_record['createdAt'] = \wp_date( 'c' );
		$root_rkey                = $bsky_transformer->get_rkey();

		$root_result = API::apply_writes(
			array(
				array(
					'$type'      => 'com.atproto.repo.applyWrites#create',
					'collection' => 'app.bsky.feed.post',
					'rkey'       => $root_rkey,
					'value'      => $root_record,
				),
				array(
					'$type'      => 'com.atproto.repo.applyWrites#create',
					'collection' => 'site.standard.document',
					'rkey'       => $doc_transformer->get_rkey(),
					'value'      => $doc_transformer->transform(),
				),
			)
		);

		if ( \is_wp_error( $root_result ) ) {
			return $root_result;
		}

		$root_triple = self::build_triple_from_result(
			$root_result,
			0,
			$bsky_transformer->get_uri(),
			$root_rkey
		);

		if ( empty( $root_triple['cid'] ) ) {
			return new \WP_Error(
				'atmosphere_missing_cid',
				\__( 'Root post created but PDS response lacked a CID; cannot chain thread replies.', 'atmosphere' )
			);
		}

		$thread_records = array( $root_triple );

		self::store_results( $post->ID, $root_result, $bsky_transformer, $doc_transformer );
		self::mirror_thread_records_meta( $post->ID, $thread_records );
		self::update_document_bsky_ref( $post, $doc_transformer );

		$aggregated_results = $root_result['results'] ?? array();

		$count = \count( $records );
		for ( $i = 1; $i < $count; $i++ ) {
			$reply_rkey   = TID::generate();
			$reply_record = $records[ $i ];

			$reply_record['createdAt'] = \wp_date( 'c' );
			$reply_record['reply']     = array(
				'root'   => array(
					'uri' => $thread_records[0]['uri'],
					'cid' => $thread_records[0]['cid'],
				),
				'parent' => array(
					'uri' => $thread_records[ $i - 1 ]['uri'],
					'cid' => $thread_records[ $i - 1 ]['cid'],
				),
			);

			$reply_result = API::apply_writes(
				array(
					array(
						'$type'      => 'com.atproto.repo.applyWrites#create',
						'collection' => 'app.bsky.feed.post',
						'rkey'       => $reply_rkey,
						'value'      => $reply_record,
					),
				)
			);

			if ( \is_wp_error( $reply_result ) ) {
				return self::rollback_thread( $post, $thread_records, $doc_transformer, $reply_result );
			}

			$reply_triple = self::build_triple_from_result(
				$reply_result,
				0,
				build_at_uri( get_did(), 'app.bsky.feed.post', $reply_rkey ),
				$reply_rkey
			);

			if ( empty( $reply_triple['cid'] ) ) {
				return self::rollback_thread(
					$post,
					$thread_records,
					$doc_transformer,
					new \WP_Error(
						'atmosphere_missing_cid',
						\__( 'Reply created but PDS response lacked a CID; rolling back thread.', 'atmosphere' )
					)
				);
			}

			$thread_records[] = $reply_triple;
			self::mirror_thread_records_meta( $post->ID, $thread_records );

			$aggregated_results = \array_merge( $aggregated_results, $reply_result['results'] ?? array() );
		}

		return array( 'results' => $aggregated_results );
	}

	/**
	 * Delete every already-written record in a partially-published thread.
	 *
	 * Posts are deleted tail-first so the root survives until last —
	 * replies pointing at the (still-live) root remain valid until their
	 * own delete lands. The document record is deleted last.
	 *
	 * Meta is always cleared — a failed rollback leaves orphans on the
	 * PDS but the local state stays consistent with "no published thread."
	 * When rollback itself fails, the returned `WP_Error` wraps both
	 * errors and carries `partial_records` so an operator retrying can
	 * clean up by hand.
	 *
	 * @param \WP_Post  $post            WordPress post.
	 * @param array[]   $thread_records  Already-written thread records (uri/cid/tid each).
	 * @param Document  $doc_transformer Document transformer instance.
	 * @param \WP_Error $original_error The failure that triggered rollback.
	 * @return \WP_Error
	 */
	private static function rollback_thread(
		\WP_Post $post,
		array $thread_records,
		Document $doc_transformer,
		\WP_Error $original_error
	): \WP_Error {
		$rollback_writes = array();

		for ( $i = \count( $thread_records ) - 1; $i >= 0; $i-- ) {
			$rollback_writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $thread_records[ $i ]['tid'],
			);
		}
		$rollback_writes[] = array(
			'$type'      => 'com.atproto.repo.applyWrites#delete',
			'collection' => 'site.standard.document',
			'rkey'       => $doc_transformer->get_rkey(),
		);

		$rollback_result = API::apply_writes( $rollback_writes );

		self::clear_all_record_meta( $post->ID );

		if ( \is_wp_error( $rollback_result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				\sprintf(
					'[atmosphere] thread rollback failed for post %d: %s',
					$post->ID,
					$rollback_result->get_error_message()
				)
			);

			return new \WP_Error(
				'atmosphere_thread_rollback_failed',
				\sprintf(
					/* translators: %s: the original error message. */
					\__( 'Thread publish failed and rollback also failed: %s', 'atmosphere' ),
					$original_error->get_error_message()
				),
				array(
					'original_error'  => $original_error,
					'rollback_error'  => $rollback_result,
					'partial_records' => $thread_records,
				)
			);
		}

		return $original_error;
	}

	/**
	 * Update the bsky + doc records for an existing post.
	 *
	 * - Single record stored + single record composed: in-place update
	 *   via `applyWrites#update` on both.
	 * - Any other shape (strategy change, thread ↔ single, or
	 *   thread ↔ thread): delete every existing record and republish
	 *   with the fresh composition. Thread updates therefore arrive to
	 *   followers as a fresh publish (new `createdAt`), and any replies
	 *   other Bluesky users posted become orphaned.
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error
	 */
	public static function update( \WP_Post $post ): array|\WP_Error {
		$stored = self::stored_thread_records( $post->ID );

		if ( empty( $stored ) ) {
			// Never successfully published — do a fresh publish.
			return self::publish( $post );
		}

		foreach ( $stored as $entry ) {
			if ( empty( $entry['tid'] ) ) {
				return new \WP_Error(
					'atmosphere_missing_tid',
					\__( 'Record URIs exist but TIDs are missing.', 'atmosphere' )
				);
			}
		}

		$doc_uri = \get_post_meta( $post->ID, Document::META_URI, true );
		$doc_tid = \get_post_meta( $post->ID, Document::META_TID, true );

		if ( ! $doc_uri ) {
			// Partial state: bsky exists but doc never did. Safer to
			// republish than to patch around missing doc.
			return self::publish( $post );
		}

		if ( ! $doc_tid ) {
			return new \WP_Error(
				'atmosphere_missing_tid',
				\__( 'Record URIs exist but TIDs are missing.', 'atmosphere' )
			);
		}

		$bsky_transformer = new Post( $post );
		$doc_transformer  = new Document( $post );

		$new_records = $bsky_transformer->is_short_form_post()
			? array( $bsky_transformer->transform() )
			: $bsky_transformer->build_long_form_records();

		// In-place update: single stored + single new composition.
		if ( 1 === \count( $stored ) && 1 === \count( $new_records ) ) {
			return self::update_single(
				$post,
				$stored[0],
				$new_records[0],
				$bsky_transformer,
				$doc_transformer,
				$doc_tid
			);
		}

		// Strategy change or thread shape — delete everything and republish.
		return self::rewrite_thread( $post, $stored, $doc_tid );
	}

	/**
	 * In-place `applyWrites#update` for both bsky + doc, mirroring today's
	 * update path. Extended only to refresh `META_THREAD_RECORDS` with the
	 * post-update CID.
	 *
	 * @param \WP_Post $post             WordPress post.
	 * @param array    $stored_root      The single stored {uri, cid, tid} triple.
	 * @param array    $new_bsky_record  Freshly composed bsky record.
	 * @param Post     $bsky_transformer Bsky transformer instance.
	 * @param Document $doc_transformer  Document transformer instance.
	 * @param string   $doc_tid          Document record TID.
	 * @return array|\WP_Error
	 */
	private static function update_single(
		\WP_Post $post,
		array $stored_root,
		array $new_bsky_record,
		Post $bsky_transformer,
		Document $doc_transformer,
		string $doc_tid
	): array|\WP_Error {
		if ( empty( $new_bsky_record['createdAt'] ) ) {
			$new_bsky_record['createdAt'] = \wp_date( 'c' );
		}

		$writes = array(
			array(
				'$type'      => 'com.atproto.repo.applyWrites#update',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $stored_root['tid'],
				'value'      => $new_bsky_record,
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
		self::mirror_thread_records_meta(
			$post->ID,
			array(
				self::build_triple_from_result(
					$result,
					0,
					$stored_root['uri'],
					$stored_root['tid']
				),
			)
		);
		self::update_document_bsky_ref( $post, $doc_transformer );

		return $result;
	}

	/**
	 * Delete every stored bsky record + the doc atomically, then publish
	 * fresh. Used when the composition strategy changes (single ↔ thread)
	 * or when a thread updates to a thread with a different record count.
	 *
	 * The local meta is cleared between delete and publish so `publish()`
	 * sees a clean slate.
	 *
	 * @param \WP_Post $post    WordPress post.
	 * @param array[]  $stored  Stored thread records (may be 1-entry).
	 * @param string   $doc_tid Document record TID.
	 * @return array|\WP_Error
	 */
	private static function rewrite_thread( \WP_Post $post, array $stored, string $doc_tid ): array|\WP_Error {
		$delete_writes = array();
		foreach ( $stored as $record ) {
			$delete_writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $record['tid'],
			);
		}
		$delete_writes[] = array(
			'$type'      => 'com.atproto.repo.applyWrites#delete',
			'collection' => 'site.standard.document',
			'rkey'       => $doc_tid,
		);

		$delete_result = API::apply_writes( $delete_writes );

		if ( \is_wp_error( $delete_result ) ) {
			return $delete_result;
		}

		self::clear_all_record_meta( $post->ID );

		return self::publish( $post );
	}

	/**
	 * Delete every bsky record + the doc for a post.
	 *
	 * Handles thread posts (reads `META_THREAD_RECORDS`) and legacy
	 * single-record posts (falls back to the mirrored `META_URI` /
	 * `META_TID` / `META_CID` keys).
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error
	 */
	public static function delete( \WP_Post $post ): array|\WP_Error {
		$stored  = self::stored_thread_records( $post->ID );
		$doc_tid = \get_post_meta( $post->ID, Document::META_TID, true );

		if ( empty( $stored ) && ! $doc_tid ) {
			return new \WP_Error(
				'atmosphere_not_published',
				\__( 'Post has no AT Protocol records.', 'atmosphere' )
			);
		}

		$writes = array();
		foreach ( $stored as $record ) {
			if ( empty( $record['tid'] ) ) {
				continue;
			}
			$writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $record['tid'],
			);
		}
		if ( $doc_tid ) {
			$writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'site.standard.document',
				'rkey'       => $doc_tid,
			);
		}

		if ( empty( $writes ) ) {
			return new \WP_Error(
				'atmosphere_not_published',
				\__( 'Post has no AT Protocol records.', 'atmosphere' )
			);
		}

		$result = API::apply_writes( $writes );

		if ( \is_wp_error( $result ) ) {
			// Leave meta intact so a retry can complete.
			return $result;
		}

		self::clear_all_record_meta( $post->ID );

		return $result;
	}

	/**
	 * Delete AT Protocol records by TID without requiring the post to exist.
	 *
	 * Used when a post is permanently deleted and its meta is no longer
	 * accessible to `delete()`. Kept at the single-TID signature for
	 * backwards compatibility with queued cron events; force-deletion of
	 * thread-strategy posts is handled by the caller by reading
	 * META_THREAD_RECORDS pre-deletion and scheduling a separate cron per
	 * thread record.
	 *
	 * @param string $bsky_tid Bluesky post TID (may be empty).
	 * @param string $doc_tid  Document TID (may be empty).
	 * @return array|\WP_Error
	 */
	public static function delete_by_tids( string $bsky_tid, string $doc_tid ): array|\WP_Error {
		if ( ! $bsky_tid && ! $doc_tid ) {
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
	 * Extract URIs/CIDs from applyWrites response and mirror into the
	 * legacy single-record meta keys (META_URI / META_TID / META_CID
	 * on Post and Document).
	 *
	 * Called for the root + doc write in every publish flow. Thread
	 * reply writes don't go through this helper — they're captured in
	 * `META_THREAD_RECORDS` by `publish_thread()` directly.
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
				if ( $uri ) {
					\update_post_meta( $post_id, Post::META_URI, $uri );
				} else {
					\update_post_meta( $post_id, Post::META_URI, $bsky_transformer->get_uri() );
				}
				if ( $cid ) {
					\update_post_meta( $post_id, Post::META_CID, $cid );
				}
			} elseif ( 1 === $i ) {
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
	 * Update the document record with the bsky root strong reference.
	 *
	 * After the initial applyWrites, the bsky root's CID is known, so
	 * we re-transform the document (which picks up the ref via its
	 * own read of `Post::META_URI` / `META_CID`) and persist via
	 * `putRecord`. Called once per publish — the doc always references
	 * the thread root, regardless of thread length.
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

	/**
	 * Read the ordered thread records for a post.
	 *
	 * Prefers `Post::META_THREAD_RECORDS`. Falls back to legacy single-record
	 * meta so posts published before this key existed still delete/update
	 * correctly.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Array of { uri, cid, tid } triples, possibly empty.
	 */
	private static function stored_thread_records( int $post_id ): array {
		$stored = \get_post_meta( $post_id, Post::META_THREAD_RECORDS, true );
		if ( \is_array( $stored ) && ! empty( $stored ) ) {
			return $stored;
		}

		$uri = \get_post_meta( $post_id, Post::META_URI, true );
		$tid = \get_post_meta( $post_id, Post::META_TID, true );
		$cid = \get_post_meta( $post_id, Post::META_CID, true );

		if ( ! $uri && ! $tid ) {
			return array();
		}

		return array(
			array(
				'uri' => (string) $uri,
				'cid' => (string) $cid,
				'tid' => (string) $tid,
			),
		);
	}

	/**
	 * Persist the thread-records meta and keep the root-mirrored single-record
	 * meta in sync with it.
	 *
	 * @param int     $post_id        Post ID.
	 * @param array[] $thread_records Ordered thread records.
	 */
	private static function mirror_thread_records_meta( int $post_id, array $thread_records ): void {
		\update_post_meta( $post_id, Post::META_THREAD_RECORDS, $thread_records );

		if ( empty( $thread_records ) ) {
			return;
		}

		$root = $thread_records[0];
		if ( ! empty( $root['uri'] ) ) {
			\update_post_meta( $post_id, Post::META_URI, $root['uri'] );
		}
		if ( ! empty( $root['tid'] ) ) {
			\update_post_meta( $post_id, Post::META_TID, $root['tid'] );
		}
		if ( ! empty( $root['cid'] ) ) {
			\update_post_meta( $post_id, Post::META_CID, $root['cid'] );
		}
	}

	/**
	 * Build a single { uri, cid, tid } triple from an `applyWrites` result
	 * entry, with sensible fallbacks when the PDS omits the URI (falling
	 * back to the transformer-computed URI) or when the caller needs a
	 * specific rkey (`Thread replies generate their own rkey so we can't
	 * derive it from `$item['uri']` reliably — we pass it in).
	 *
	 * @param array  $result         applyWrites response.
	 * @param int    $index          Zero-based index into `$result['results']`.
	 * @param string $fallback_uri   AT-URI to use if the response omits one.
	 * @param string $tid            Known rkey (generated client-side).
	 * @return array{ uri: string, cid: string, tid: string }
	 */
	private static function build_triple_from_result( array $result, int $index, string $fallback_uri, string $tid ): array {
		$entry = $result['results'][ $index ] ?? array();

		return array(
			'uri' => (string) ( $entry['uri'] ?? $fallback_uri ),
			'cid' => (string) ( $entry['cid'] ?? '' ),
			'tid' => $tid,
		);
	}

	/**
	 * Clear every post-meta key tied to AT Protocol records for the post.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function clear_all_record_meta( int $post_id ): void {
		\delete_post_meta( $post_id, Post::META_THREAD_RECORDS );
		\delete_post_meta( $post_id, Post::META_URI );
		\delete_post_meta( $post_id, Post::META_TID );
		\delete_post_meta( $post_id, Post::META_CID );
		\delete_post_meta( $post_id, Document::META_URI );
		\delete_post_meta( $post_id, Document::META_TID );
		\delete_post_meta( $post_id, Document::META_CID );
	}
}
