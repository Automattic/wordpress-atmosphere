<?php
/**
 * Orchestrates publishing WordPress content to the AT Protocol.
 *
 * A WordPress post corresponds to:
 * - One or more `app.bsky.feed.post` records (a thread, for the
 *   long-form `teaser-thread` strategy).
 * - Exactly one `site.standard.document` record.
 * Outbound WordPress comments publish as separate bsky reply records.
 *
 * Single-record post publishes (short-form, link-card, truncate-link)
 * use one atomic `applyWrites` call with the bsky post + doc. Threads
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
 * The generic publish/update/delete entry points dispatch by object
 * type so callers can stay polymorphic across posts and comments.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Transformer\Comment;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Publication;
use Atmosphere\Transformer\TID;

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
	 * Publish a post to AT Protocol (bsky record(s) + document).
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error applyWrites response(s) or error.
	 */
	public static function publish_post( \WP_Post $post ): array|\WP_Error {
		$bsky_transformer = new Post( $post );
		$doc_transformer  = new Document( $post );

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
	 * `createdAt` defaults to the post's `post_date_gmt` when the record
	 * doesn't already carry one, so the Bluesky timeline mirrors the
	 * WordPress publish date (critical for backfill — otherwise every
	 * re-synced post would stamp with the backfill-run time and
	 * collapse chronological order). `transform()` and the long-form
	 * record builders normally set it already; Publisher only backfills
	 * here when a record arrives without `createdAt` (for example, if a
	 * filter stripped it).
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
			$bsky_record['createdAt'] = to_iso8601( $post->post_date_gmt );
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

		self::store_document_meta( $post->ID, $result, $doc_transformer );
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

		$doc_ref_result = self::update_document_bsky_ref( $post, $doc_transformer );
		if ( \is_wp_error( $doc_ref_result ) ) {
			return $doc_ref_result;
		}

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
		$root_record = $records[0];
		if ( empty( $root_record['createdAt'] ) ) {
			$root_record['createdAt'] = to_iso8601( $post->post_date_gmt );
		}
		$root_rkey = $bsky_transformer->get_rkey();

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
			// Root + doc were written, but without a CID we can't chain
			// replies. Roll back so a retry starts from a clean slate
			// instead of hitting "record already exists" on the same TID.
			return self::rollback_thread(
				$post,
				array( $root_triple ),
				$doc_transformer,
				new \WP_Error(
					'atmosphere_missing_cid',
					\__( 'Root post created but PDS response lacked a CID; rolling back thread.', 'atmosphere' )
				)
			);
		}

		$thread_records = array( $root_triple );
		$created_at     = to_iso8601( $post->post_date_gmt );

		self::store_document_meta( $post->ID, $root_result, $doc_transformer );
		self::mirror_thread_records_meta( $post->ID, $thread_records );

		// Best-effort: a doc-ref update failure must not abort thread
		// publishing after the root + document are already created.
		// Bailing here leaves META_THREAD_RECORDS at length=1, and a
		// subsequent edit treats that as a shape change and rewrites
		// the (already-published) root — invalidating likes/reposts/
		// external replies pointing at it. Persist the gap to
		// META_DOC_REF_PENDING so operators (and any admin/Site Health
		// surface) can see it; the next edit retries the doc-ref via
		// update_*'s normal call to update_document_bsky_ref.
		$doc_ref_result = self::update_document_bsky_ref( $post, $doc_transformer );
		if ( \is_wp_error( $doc_ref_result ) ) {
			self::record_doc_ref_pending( $post->ID, $doc_ref_result );
		}

		$aggregated_results = $root_result['results'] ?? array();

		$count = \count( $records );
		for ( $i = 1; $i < $count; $i++ ) {
			$reply_rkey   = TID::generate();
			$reply_record = $records[ $i ];

			if ( empty( $reply_record['createdAt'] ) ) {
				$reply_record['createdAt'] = $created_at;
			}
			$reply_record['reply'] = array(
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
				// `apply_writes` errors are ambiguous: the PDS may have
				// committed the create even when WP got a transport-level
				// failure back (server-side commit + response timeout /
				// connection drop). The rkey is generated locally and is
				// known regardless of commit state, so include a synthetic
				// triple in the rollback list. If the record was never
				// committed, the compensating delete is a no-op (or
				// surfaces in the orphan manifest if the PDS rejects it).
				// If it was committed, rollback cleans it up. Either way
				// we don't leave a live reply that META_THREAD_RECORDS
				// can't see.
				$ambiguous_triple = array(
					'uri' => build_at_uri( get_did(), 'app.bsky.feed.post', $reply_rkey ),
					'cid' => '',
					'tid' => $reply_rkey,
				);
				return self::rollback_thread(
					$post,
					\array_merge( $thread_records, array( $ambiguous_triple ) ),
					$doc_transformer,
					$reply_result
				);
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
					\array_merge( $thread_records, array( $reply_triple ) ),
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
	 * Active-record meta is always cleared so the local state stays
	 * consistent with "no published thread." When rollback itself fails,
	 * the partial thread is *also* persisted to `Post::META_ORPHAN_RECORDS`
	 * (and error-logged) so an operator or recovery worker can issue
	 * manual deletes later — the orphan manifest in the returned
	 * `WP_Error` data otherwise disappears the moment the cron closure
	 * returns.
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
		$doc_rkey = $doc_transformer->get_rkey();

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
			'rkey'       => $doc_rkey,
		);

		$rollback_result = API::apply_writes( $rollback_writes );

		self::clear_all_record_meta( $post->ID );

		if ( \is_wp_error( $rollback_result ) ) {
			self::persist_orphan_records(
				$post->ID,
				$thread_records,
				$doc_rkey,
				$original_error,
				$rollback_result
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
	 * Record the partial thread state left on the PDS after a failed
	 * rollback so a human (or a future recovery worker) can find it.
	 *
	 * Writes `Post::META_ORPHAN_RECORDS` and error-logs a
	 * machine-parseable summary. The post meta is the source of truth —
	 * the log line is a convenience for ops grepping a filesystem tail.
	 *
	 * TODO: surface the manifest in admin / Site Health / WP-CLI so
	 * orphans don't require a manual `get_post_meta()` to discover.
	 * Tracked in issue 44.
	 *
	 * @param int       $post_id         WordPress post ID.
	 * @param array[]   $thread_records  Thread records that survived rollback.
	 * @param string    $doc_rkey        Document rkey that survived rollback.
	 * @param \WP_Error $original_error  Publish-time error that triggered rollback.
	 * @param \WP_Error $rollback_error  Rollback-time error.
	 */
	private static function persist_orphan_records(
		int $post_id,
		array $thread_records,
		string $doc_rkey,
		\WP_Error $original_error,
		\WP_Error $rollback_error
	): void {
		$entry = array(
			'stamp'          => \gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'bsky_records'   => $thread_records,
			'doc_rkey'       => $doc_rkey,
			'original_error' => $original_error->get_error_message(),
			'rollback_error' => $rollback_error->get_error_message(),
		);

		$existing = \get_post_meta( $post_id, Post::META_ORPHAN_RECORDS, true );
		if ( ! \is_array( $existing ) ) {
			$existing = array();
		}

		$existing[] = $entry;

		// Cap the manifest so a crash-looping cron can't grow the meta row
		// past MySQL's max_allowed_packet. Most-recent entries win.
		if ( \count( $existing ) > 10 ) {
			$existing = \array_slice( $existing, -10 );
		}

		\update_post_meta( $post_id, Post::META_ORPHAN_RECORDS, $existing );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log(
			\sprintf(
				'[atmosphere] thread rollback failed for post %d; orphans persisted to %s: %s',
				$post_id,
				Post::META_ORPHAN_RECORDS,
				\wp_json_encode( $entry )
			)
		);
	}

	/**
	 * Update the bsky + doc records for an existing post.
	 *
	 * - Stored record count == new record count: in-place update via
	 *   `applyWrites#update` on every bsky record + doc in one atomic
	 *   batch. Preserves TIDs, URIs, and external replies; each record
	 *   just gets a new CID from the PDS. Reply refs are rewired to
	 *   the pre-update CIDs so each record's `reply.parent.cid` still
	 *   resolves — clients treat the mismatch as "parent was edited."
	 * - Record counts differ (strategy change: link-card ↔ teaser-thread,
	 *   or 2-post thread ↔ 3-post thread): delete every existing record
	 *   and republish with the fresh composition. Thread updates via
	 *   this path arrive to followers as a fresh publish (new
	 *   `createdAt`) and any replies other Bluesky users posted become
	 *   orphaned.
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error
	 */
	public static function update_post( \WP_Post $post ): array|\WP_Error {
		$stored = self::stored_thread_records( $post->ID );

		if ( empty( $stored ) ) {
			// Never successfully published — do a fresh publish.
			return self::publish_post( $post );
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
			// Partial state: bsky exists but the document never did.
			// Calling publish() directly here would reuse the existing
			// bsky TID via get_rkey() and the PDS would reject the
			// create as already-existing. Route through rewrite_thread
			// with an empty doc_tid so the existing bsky records are
			// deleted before we republish with fresh TIDs.
			return self::rewrite_thread( $post, $stored, '' );
		}

		if ( ! $doc_tid ) {
			return new \WP_Error(
				'atmosphere_missing_tid',
				\__( 'Record URIs exist but TIDs are missing.', 'atmosphere' )
			);
		}

		$bsky_transformer = new Post( $post );
		$doc_transformer  = new Document( $post );

		// Pass the stored count to `build_long_form_records()` so the
		// transformer can preserve the existing thread shape on update
		// instead of triggering a destructive `rewrite_thread()` for
		// shape-shrinking optimisations like the redundant-CTA collapse
		// — that path would re-mint the root URI and orphan external
		// engagement on the original. New posts (no stored records) get
		// the optimised shape; live posts keep theirs forever.
		$new_records = $bsky_transformer->is_short_form_post()
			? array( $bsky_transformer->transform() )
			: $bsky_transformer->build_long_form_records( \count( $stored ) );

		// In-place update: matching record counts. Strategy is not
		// compared — a `truncate-link` (count=1) post that switches to
		// a `teaser-thread` whose empty-body guard downgrades to
		// `link-card` (count=1) takes this path. Output is structurally
		// correct because both end up as a single-record post; URIs and
		// TIDs are reused on the bsky side.
		if ( \count( $stored ) === \count( $new_records ) ) {
			if ( 1 === \count( $stored ) ) {
				return self::update_single(
					$post,
					$stored[0],
					$new_records[0],
					$bsky_transformer,
					$doc_transformer,
					$doc_tid
				);
			}

			return self::update_thread_in_place(
				$post,
				$stored,
				$new_records,
				$doc_transformer,
				$doc_tid
			);
		}

		// Strategy or shape change — delete everything and republish.
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
			$new_bsky_record['createdAt'] = to_iso8601( $post->post_date_gmt );
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

		self::store_document_meta( $post->ID, $result, $doc_transformer );
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

		$doc_ref_result = self::update_document_bsky_ref( $post, $doc_transformer );
		if ( \is_wp_error( $doc_ref_result ) ) {
			return $doc_ref_result;
		}

		return $result;
	}

	/**
	 * In-place `applyWrites#update` for every record in a thread +
	 * the document, in one atomic batch.
	 *
	 * Preserves URIs/TIDs/external reply integrity; each record's CID
	 * changes (since CID is a content hash). Reply refs are built from
	 * the *pre-update* CIDs stored in `META_THREAD_RECORDS` —
	 * structurally self-consistent at write time, and clients treat any
	 * post-update CID mismatch as "parent was edited" rather than
	 * broken.
	 *
	 * After the write, `META_THREAD_RECORDS` is refreshed with the new
	 * CIDs from the response so future updates chain from current CIDs.
	 *
	 * @param \WP_Post $post            WordPress post.
	 * @param array[]  $stored          Current {uri, cid, tid} triples in order.
	 * @param array[]  $new_records     Freshly composed bsky records, same count.
	 * @param Document $doc_transformer Document transformer.
	 * @param string   $doc_tid         Document record TID.
	 * @return array|\WP_Error
	 */
	private static function update_thread_in_place(
		\WP_Post $post,
		array $stored,
		array $new_records,
		Document $doc_transformer,
		string $doc_tid
	): array|\WP_Error {
		$root       = $stored[0];
		$created_at = to_iso8601( $post->post_date_gmt );
		$writes     = array();
		$bsky_count = \count( $new_records );

		foreach ( $new_records as $i => $record ) {
			if ( empty( $record['createdAt'] ) ) {
				$record['createdAt'] = $created_at;
			}

			if ( $i > 0 ) {
				$record['reply'] = array(
					'root'   => array(
						'uri' => $root['uri'],
						'cid' => $root['cid'],
					),
					'parent' => array(
						'uri' => $stored[ $i - 1 ]['uri'],
						'cid' => $stored[ $i - 1 ]['cid'],
					),
				);
			}

			$writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#update',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $stored[ $i ]['tid'],
				'value'      => $record,
			);
		}

		$writes[] = array(
			'$type'      => 'com.atproto.repo.applyWrites#update',
			'collection' => 'site.standard.document',
			'rkey'       => $doc_tid,
			'value'      => $doc_transformer->transform(),
		);

		$result = API::apply_writes( $writes );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		$results   = $result['results'] ?? array();
		$refreshed = array();
		foreach ( $stored as $i => $old ) {
			$entry       = $results[ $i ] ?? array();
			$refreshed[] = array(
				'uri' => $old['uri'],
				'cid' => (string) ( $entry['cid'] ?? $old['cid'] ),
				'tid' => $old['tid'],
			);
		}

		self::mirror_thread_records_meta( $post->ID, $refreshed );

		$doc_entry = $results[ $bsky_count ] ?? array();
		if ( ! empty( $doc_entry['uri'] ) ) {
			\update_post_meta( $post->ID, Document::META_URI, $doc_entry['uri'] );
		}
		if ( ! empty( $doc_entry['cid'] ) ) {
			\update_post_meta( $post->ID, Document::META_CID, $doc_entry['cid'] );
		}

		$doc_ref_result = self::update_document_bsky_ref( $post, $doc_transformer );
		if ( \is_wp_error( $doc_ref_result ) ) {
			return $doc_ref_result;
		}

		return $result;
	}

	/**
	 * Delete every stored bsky record + the doc atomically, then publish
	 * fresh. Used when the composition strategy changes (single ↔ thread)
	 * or when a thread updates to a thread with a different record count.
	 *
	 * The local meta is cleared between delete and publish so `publish()`
	 * sees a clean slate. If the republish step fails after the delete
	 * succeeded, the pre-rewrite manifest is persisted to
	 * `Post::META_ORPHAN_RECORDS` (marked `phase: rewrite`) so operators
	 * can see what was lost — a subsequent retry of `update()` sees
	 * empty stored records and goes straight to `publish()`, which
	 * self-heals with fresh TIDs.
	 *
	 * @param \WP_Post $post    WordPress post.
	 * @param array[]  $stored  Stored thread records (may be 1-entry).
	 * @param string   $doc_tid Document record TID (may be empty when
	 *                          recovering from a partial state where the
	 *                          bsky records exist but the doc never did).
	 * @return array|\WP_Error
	 */
	private static function rewrite_thread( \WP_Post $post, array $stored, string $doc_tid ): array|\WP_Error {
		$delete_writes = array();
		foreach ( $stored as $record ) {
			if ( empty( $record['tid'] ) ) {
				continue;
			}
			$delete_writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $record['tid'],
			);
		}
		if ( '' !== $doc_tid ) {
			$delete_writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'site.standard.document',
				'rkey'       => $doc_tid,
			);
		}

		if ( ! empty( $delete_writes ) ) {
			$delete_result = API::apply_writes( $delete_writes );

			if ( \is_wp_error( $delete_result ) ) {
				return $delete_result;
			}
		}

		self::clear_all_record_meta( $post->ID );

		$publish_result = self::publish( $post );

		if ( \is_wp_error( $publish_result ) ) {
			self::persist_rewrite_failure( $post->ID, $stored, $doc_tid, $publish_result );
		}

		return $publish_result;
	}

	/**
	 * Record a rewrite-thread failure in the orphan manifest so
	 * operators can trace what was deleted before the republish
	 * step failed. The deleted records are genuinely gone from the
	 * PDS (no recovery is possible), but the manifest gives a
	 * durable trail for audit / user communication.
	 *
	 * @param int       $post_id         Post ID.
	 * @param array[]   $pre_delete      The thread records as they existed before delete.
	 * @param string    $doc_tid         Document TID that was deleted (may be empty).
	 * @param \WP_Error $publish_error   The republish-step failure.
	 */
	private static function persist_rewrite_failure(
		int $post_id,
		array $pre_delete,
		string $doc_tid,
		\WP_Error $publish_error
	): void {
		$entry = array(
			'phase'         => 'rewrite',
			'stamp'         => \gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'deleted_bsky'  => $pre_delete,
			'deleted_doc'   => $doc_tid,
			'publish_error' => $publish_error->get_error_message(),
		);

		$existing = \get_post_meta( $post_id, Post::META_ORPHAN_RECORDS, true );
		if ( ! \is_array( $existing ) ) {
			$existing = array();
		}

		$existing[] = $entry;

		if ( \count( $existing ) > 10 ) {
			$existing = \array_slice( $existing, -10 );
		}

		\update_post_meta( $post_id, Post::META_ORPHAN_RECORDS, $existing );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log(
			\sprintf(
				'[atmosphere] rewrite_thread republish failed for post %d; deleted records logged to %s: %s',
				$post_id,
				Post::META_ORPHAN_RECORDS,
				$publish_error->get_error_message()
			)
		);
	}

	/**
	 * Maximum writes per `applyWrites` call.
	 *
	 * The AT Protocol `com.atproto.repo.applyWrites` lexicon caps the
	 * `writes` array at 200. We chunk well under that to leave headroom
	 * and keep request bodies small.
	 */
	private const APPLY_WRITES_CHUNK_SIZE = 100;

	/**
	 * Delete every bsky record (root + thread replies + outbound
	 * comment replies) and the document for a post.
	 *
	 * Handles thread posts (reads `META_THREAD_RECORDS`) and legacy
	 * single-record posts (falls back to the mirrored `META_URI` /
	 * `META_TID` / `META_CID` keys). Outbound comment replies live in
	 * our own repo keyed by their own TIDs — the AT Protocol has no
	 * cascade semantics, so they have to be enumerated alongside the
	 * post records or they orphan on Bluesky.
	 *
	 * Writes are chunked into bounded `applyWrites` calls (the lexicon
	 * caps a single batch at 200), so a high-traffic post with a long
	 * reply tail still cleans up cleanly.
	 *
	 * @param \WP_Post $post WordPress post.
	 * @return array|\WP_Error
	 */
	public static function delete_post( \WP_Post $post ): array|\WP_Error {
		$stored  = self::stored_thread_records( $post->ID );
		$doc_tid = \get_post_meta( $post->ID, Document::META_TID, true );

		$comment_tids = self::collect_published_comment_tids( $post->ID );

		if ( empty( $stored ) && ! $doc_tid && empty( $comment_tids ) ) {
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

		foreach ( $comment_tids as $comment_tid ) {
			$writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $comment_tid['tid'],
			);
		}

		if ( empty( $writes ) ) {
			return new \WP_Error(
				'atmosphere_not_published',
				\__( 'Post has no AT Protocol records.', 'atmosphere' )
			);
		}

		$result = self::apply_writes_chunked( $writes );

		if ( \is_wp_error( $result ) ) {
			// Leave meta intact so a retry can complete.
			return $result;
		}

		self::clear_all_record_meta( $post->ID );

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
	 * Delete AT Protocol records by TID without requiring the post to exist.
	 *
	 * Used when a post is permanently deleted and its meta is no longer
	 * accessible to `delete_post()`. Accepts either a single Bluesky TID
	 * string (legacy single-record posts) or an array of TIDs
	 * (thread-strategy posts), plus an optional list of outbound
	 * comment-reply TIDs. All are issued in one atomic `applyWrites`
	 * call so cleanup of root + thread + replies + document is atomic.
	 *
	 * @param string|string[] $bsky_tids    Bluesky post TID or array of TIDs (may be empty).
	 * @param string          $doc_tid      Document TID (may be empty).
	 * @param string[]        $comment_tids Comment reply TIDs to delete in the same batch.
	 * @return array|\WP_Error
	 */
	public static function delete_post_by_tids( $bsky_tids, string $doc_tid, array $comment_tids = array() ): array|\WP_Error {
		if ( \is_string( $bsky_tids ) ) {
			$bsky_tids = '' === $bsky_tids ? array() : array( $bsky_tids );
		} elseif ( ! \is_array( $bsky_tids ) ) {
			$bsky_tids = array();
		}

		$bsky_tids    = \array_values( \array_filter( \array_map( 'strval', $bsky_tids ), 'strlen' ) );
		$comment_tids = \array_values( \array_filter( \array_map( 'strval', $comment_tids ), 'strlen' ) );

		if ( empty( $bsky_tids ) && ! $doc_tid && empty( $comment_tids ) ) {
			return new \WP_Error( 'atmosphere_not_published', \__( 'No TIDs provided.', 'atmosphere' ) );
		}

		$writes = array();

		foreach ( $bsky_tids as $bsky_tid ) {
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
			$writes[] = array(
				'$type'      => 'com.atproto.repo.applyWrites#delete',
				'collection' => 'app.bsky.feed.post',
				'rkey'       => $comment_tid,
			);
		}

		return self::apply_writes_chunked( $writes );
	}

	/**
	 * Submit a `writes` batch in lexicon-bounded chunks.
	 *
	 * The PDS rejects an `applyWrites` whose `writes` array exceeds 200
	 * entries (`InvalidRequest`), so a high-traffic post with hundreds of
	 * outbound comment replies would otherwise fail the entire cascade
	 * atomically.
	 *
	 * Chunks are submitted sequentially. The first chunk failure is
	 * returned; the operator-visible error code includes how many chunks
	 * had already succeeded so the partial-success state is visible. The
	 * caller is responsible for keeping local meta intact on error so a
	 * retry can complete the remaining chunks.
	 *
	 * On success, results from each chunk are concatenated into a single
	 * `results` array — preserving the shape callers expect from
	 * `API::apply_writes()`.
	 *
	 * @param array $writes Full write batch.
	 * @return array|\WP_Error
	 */
	private static function apply_writes_chunked( array $writes ): array|\WP_Error {
		if ( \count( $writes ) <= self::APPLY_WRITES_CHUNK_SIZE ) {
			return API::apply_writes( $writes );
		}

		$chunks    = \array_chunk( $writes, self::APPLY_WRITES_CHUNK_SIZE );
		$total     = \count( $chunks );
		$results   = array();
		$succeeded = 0;

		foreach ( $chunks as $index => $chunk ) {
			$response = API::apply_writes( $chunk );

			if ( \is_wp_error( $response ) ) {
				$response->add_data(
					array(
						'chunk_index'      => $index,
						'chunks_total'     => $total,
						'chunks_succeeded' => $succeeded,
					),
					'atmosphere_chunked_apply_writes'
				);
				return $response;
			}

			if ( isset( $response['results'] ) && \is_array( $response['results'] ) ) {
				$results = \array_merge( $results, $response['results'] );
			}

			++$succeeded;
		}

		return array( 'results' => $results );
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
	 * Persist the document record's URI/CID from an applyWrites response.
	 *
	 * The document is always written at index 1 of the first applyWrites
	 * batch in every publish flow (root + doc, atomically). Post meta
	 * (`Post::META_URI` / `META_TID` / `META_CID`) is owned by
	 * `mirror_thread_records_meta()` and intentionally not touched here
	 * — single mirroring point keeps the two paths from drifting.
	 *
	 * @param int      $post_id         Post ID.
	 * @param array    $result          applyWrites response.
	 * @param Document $doc_transformer Document transformer.
	 */
	private static function store_document_meta( int $post_id, array $result, Document $doc_transformer ): void {
		$doc_entry = $result['results'][1] ?? null;

		if ( null === $doc_entry ) {
			return;
		}

		$uri = $doc_entry['uri'] ?? '';
		$cid = $doc_entry['cid'] ?? '';

		\update_post_meta( $post_id, Document::META_URI, $uri ?: $doc_transformer->get_uri() );

		if ( $cid ) {
			\update_post_meta( $post_id, Document::META_CID, $cid );
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
	 * @return array|\WP_Error|null
	 */
	private static function update_document_bsky_ref( \WP_Post $post, Document $doc_transformer ): array|\WP_Error|null {
		$bsky_uri = \get_post_meta( $post->ID, Post::META_URI, true );
		$bsky_cid = \get_post_meta( $post->ID, Post::META_CID, true );

		if ( ! $bsky_uri || ! $bsky_cid ) {
			return null;
		}

		// `transform()` reads Post::META_URI / META_CID fresh from post
		// meta on every call, so the existing transformer picks up the
		// values just persisted by mirror_thread_records_meta() above —
		// no need for a new instance.
		$record = $doc_transformer->transform();

		$result = API::post(
			'/xrpc/com.atproto.repo.putRecord',
			array(
				'repo'       => get_did(),
				'collection' => 'site.standard.document',
				'rkey'       => $doc_transformer->get_rkey(),
				'record'     => $record,
			)
		);

		// Clear any previous deferred-failure marker on success so
		// subsequent edits stop reporting the post as pending.
		if ( ! \is_wp_error( $result ) ) {
			\delete_post_meta( $post->ID, Post::META_DOC_REF_PENDING );
		}

		return $result;
	}

	/**
	 * Persist a deferred `update_document_bsky_ref` failure.
	 *
	 * Called from `publish_thread()` when the follow-up `putRecord`
	 * fails after the bsky thread root and document records have
	 * already been created. The publish itself is treated as a success
	 * (replies still ship; rewriting the root on the next edit would
	 * be the worse failure mode), but the gap is recorded here so
	 * operators / admin surfaces can see that the document's
	 * `bskyPostRef` is stale and that the post should be re-saved to
	 * trigger a retry.
	 *
	 * Cleared by a subsequent successful `update_document_bsky_ref`.
	 *
	 * TODO: surface in admin / Site Health alongside META_ORPHAN_RECORDS
	 * (issue 44).
	 *
	 * @param int       $post_id WordPress post ID.
	 * @param \WP_Error $error   The doc-ref failure to record.
	 */
	private static function record_doc_ref_pending( int $post_id, \WP_Error $error ): void {
		\update_post_meta(
			$post_id,
			Post::META_DOC_REF_PENDING,
			array(
				'stamp'   => \gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			)
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log(
			\sprintf(
				'[atmosphere] post %d: doc-ref update failed during thread publish (%s); continuing with replies',
				$post_id,
				$error->get_error_code()
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

		// A bare TID without a URI means the rkey was reserved via
		// Transformer::get_rkey() but no create ever succeeded on the
		// PDS (e.g. a prior publish failed mid-step, or a rewrite_thread
		// republish failed). Treat that as "nothing published" so the
		// caller falls back to a fresh publish and the reserved TID is
		// reused on the next attempt.
		if ( ! $uri ) {
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
	 * Persist the thread-records meta, mirror the root into the legacy
	 * single-record meta, and rebuild the flat per-URI index used by
	 * inbound reaction sync to resolve reply URIs back to the post.
	 *
	 * @param int     $post_id        Post ID.
	 * @param array[] $thread_records Ordered thread records.
	 */
	private static function mirror_thread_records_meta( int $post_id, array $thread_records ): void {
		\update_post_meta( $post_id, Post::META_THREAD_RECORDS, $thread_records );

		// Rebuild the flat URI index so reaction sync can resolve replies.
		\delete_post_meta( $post_id, Post::META_URI_INDEX );
		foreach ( $thread_records as $record ) {
			if ( ! empty( $record['uri'] ) ) {
				\add_post_meta( $post_id, Post::META_URI_INDEX, $record['uri'] );
			}
		}

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
	 * Build a single { uri, cid, tid } triple from an `applyWrites`
	 * result entry. Falls back to the transformer-computed URI when the
	 * PDS response omits one. Callers pass the rkey in because thread
	 * replies are created with a freshly-generated TID that isn't
	 * recoverable from the response URI alone.
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
		\delete_post_meta( $post_id, Post::META_URI_INDEX );
		\delete_post_meta( $post_id, Post::META_URI );
		\delete_post_meta( $post_id, Post::META_TID );
		\delete_post_meta( $post_id, Post::META_CID );
		\delete_post_meta( $post_id, Post::META_DOC_REF_PENDING );
		\delete_post_meta( $post_id, Document::META_URI );
		\delete_post_meta( $post_id, Document::META_TID );
		\delete_post_meta( $post_id, Document::META_CID );
	}
}
