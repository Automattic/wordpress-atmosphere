<?php
/**
 * Polls Bluesky for reactions on posts we've published and inserts
 * them as WordPress comments with AT Protocol metadata.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Client;
use Atmosphere\Transformer\Facet;
use Atmosphere\Transformer\Post as BskyPost;

/**
 * Reaction sync engine.
 */
class Reaction_Sync {

	/**
	 * Comment meta key for the protocol identifier.
	 *
	 * Matches the key used by wordpress-activitypub.
	 *
	 * @var string
	 */
	public const META_PROTOCOL = 'protocol';

	/**
	 * Comment meta key for the source object identifier.
	 *
	 * Stores the AT-URI. Dedup key. Matches the key used by
	 * wordpress-activitypub (which stores an HTTP URL there).
	 *
	 * @var string
	 */
	public const META_SOURCE_ID = 'source_id';

	/**
	 * Comment meta key for the human-visitable URL of the reaction.
	 *
	 * Stores https://bsky.app/profile/<handle>/post/<rkey>.
	 *
	 * @var string
	 */
	public const META_SOURCE_URL = 'source_url';

	/**
	 * Comment meta key for the Bluesky CID.
	 *
	 * @var string
	 */
	public const META_BSKY_CID = '_atmosphere_bsky_cid';

	/**
	 * Comment meta key for the reaction author's DID.
	 *
	 * @var string
	 */
	public const META_AUTHOR_DID = '_atmosphere_author_did';

	/**
	 * Comment meta key for the reaction author's avatar URL.
	 *
	 * Populated at insert time so get_avatar() does not fall through
	 * to gravatar.
	 *
	 * @var string
	 */
	public const META_AUTHOR_AVATAR = '_atmosphere_author_avatar';

	/**
	 * Maximum pages fetched per stream per run.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 5;

	/**
	 * Items re-walked strictly past the prior run's watermark on each run.
	 *
	 * Covers the publish→immediate-reaction race: a reaction can land
	 * in the stream before the target post's _atmosphere_bsky_uri meta
	 * is written, get dropped on the first run, and then be skipped
	 * forever once the watermark moves past it. Re-walking a small
	 * window gives transient drops a retry; dedup via source_id meta
	 * keeps already-processed items from being re-inserted.
	 *
	 * @var int
	 */
	private const WATERMARK_GRACE = 10;

	/**
	 * Watermark option for the listNotifications stream.
	 *
	 * @var string
	 */
	private const OPTION_LAST_SEEN_NOTIFICATION = 'atmosphere_last_seen_notification';

	/**
	 * Watermark option prefix for own-repo streams (one per collection).
	 *
	 * @var string
	 */
	private const OPTION_LAST_SEEN_OWN_PREFIX = 'atmosphere_last_seen_own_';

	/**
	 * In-progress pagination state for streams that exceed one cron run.
	 *
	 * Keyed by the stream's watermark option so notifications and each own-
	 * repo collection can resume independently.
	 *
	 * @var string
	 */
	private const OPTION_PAGINATION_STATE = 'atmosphere_reaction_sync_pagination';

	/**
	 * DID whose reaction-sync watermarks belong to the current account.
	 *
	 * @var string
	 */
	private const OPTION_SYNC_DID = 'atmosphere_reaction_sync_did';

	/**
	 * Post meta recording the most recent automatic reply-backfill attempt.
	 *
	 * @var string
	 */
	private const META_BACKFILL_CHECKED_AT = '_atmosphere_reply_backfill_checked_at';

	/**
	 * Posts audited during each daily automatic reply-backfill run.
	 *
	 * @var int
	 */
	private const DEFAULT_BACKFILL_BATCH_SIZE = 5;

	/**
	 * Comment statuses considered by the source-URI dedup lookups.
	 *
	 * `WP_Comment_Query`'s default status only matches approved and pending
	 * comments. Spammed and trashed comments must stay visible to dedup, or
	 * the thread backfill would re-import a reply the admin moderated away
	 * on every audit.
	 *
	 * @var string[]
	 */
	private const DEDUP_COMMENT_STATUSES = array( 'approve', 'hold', 'spam', 'trash', 'post-trashed' );

	/**
	 * Comment statuses a nested reply may thread under.
	 *
	 * Deliberately narrower than {@see self::DEDUP_COMMENT_STATUSES}: a
	 * parent the admin moderated away (spam/trash) must not resolve, or
	 * its replies would import and render as top-level comments —
	 * resurfacing a suppressed conversation without its context. An
	 * unresolved parent drops the reply as an orphan instead (see the
	 * rationale in {@see self::process_reply()}).
	 *
	 * @var string[]
	 */
	private const PARENT_COMMENT_STATUSES = array( 'approve', 'hold' );

	/**
	 * Maximum reply nodes imported from one getPostThread response.
	 *
	 * Bounds one backfill run's comment inserts (and their spam-check HTTP
	 * calls) when a thread is unexpectedly huge.
	 *
	 * @var int
	 */
	private const MAX_THREAD_REPLIES = 500;

	/**
	 * Cross-process lock guarding reaction sync and targeted reply backfills.
	 *
	 * @var string
	 */
	public const LOCK_OPTION = '_atmosphere_reaction_sync_lock';

	/**
	 * Maximum lock age before another worker may reclaim it.
	 *
	 * The lock is renewed between remote requests and imported items, so this
	 * only needs to exceed the longest individual request timeout (120 seconds
	 * for getPostThread) plus local processing overhead.
	 *
	 * @var int
	 */
	private const LOCK_TTL = 180;

	/**
	 * Consecutive continuation failures before restarting from the newest page.
	 *
	 * Restarting is safe because the old watermark is not advanced while a
	 * continuation is active and imported reactions are deduplicated by URI.
	 *
	 * @var int
	 */
	private const MAX_PAGINATION_FAILURES = 3;

	/**
	 * Cross-process lock shared by reaction sync and reply backfills.
	 *
	 * @var Lock|null
	 */
	private static ?Lock $lock = null;

	/**
	 * Register display-side hooks.
	 */
	public static function register(): void {
		\add_filter( 'get_avatar_comment_types', array( self::class, 'avatar_comment_types' ) );
		\add_filter( 'pre_get_avatar_data', array( self::class, 'filter_avatar_data' ), 10, 2 );

		/*
		 * Keep synced likes and reposts out of the post's comment list and
		 * count — they're surfaced via the reactions block instead. Replies
		 * (comment_type `comment`) stay, as they are genuine comments.
		 *
		 * Skipped when the ActivityPub plugin is active: it already excludes
		 * these comment types (they share its shape), so running our own
		 * filters too would double up.
		 */
		if ( ! is_activitypub_active() ) {
			\add_action( 'pre_get_comments', array( self::class, 'exclude_reactions_from_comments' ) );
			\add_filter( 'pre_wp_update_comment_count_now', array( self::class, 'exclude_reactions_from_count' ), 5, 3 );
		}
	}

	/**
	 * The comment types surfaced via the reactions block, not the comment list.
	 *
	 * @return string[]
	 */
	private static function reaction_comment_types(): array {
		return array( 'like', 'repost' );
	}

	/**
	 * Exclude likes and reposts from front-end comment queries.
	 *
	 * Bails for admin, REST, and non-singular contexts, and for any query
	 * that already constrains comment types, so only the default front-end
	 * comment list on a single post is filtered.
	 *
	 * @param \WP_Comment_Query $query The comment query.
	 * @return void
	 */
	public static function exclude_reactions_from_comments( $query ): void {
		if ( ! $query instanceof \WP_Comment_Query ) {
			return;
		}

		if ( \is_admin() || ( \defined( 'REST_REQUEST' ) && \REST_REQUEST ) ) {
			return;
		}

		if ( ! \is_singular() ) {
			return;
		}

		if ( ! empty( $query->query_vars['type'] )
			|| ! empty( $query->query_vars['type__in'] )
			|| ! empty( $query->query_vars['type__not_in'] )
		) {
			return;
		}

		$query->query_vars['type__not_in'] = self::reaction_comment_types();
	}

	/**
	 * Exclude likes and reposts from a post's comment count.
	 *
	 * Hooked early on `pre_wp_update_comment_count_now`; recomputes the count
	 * over everything except the reaction comment types so the "N comments"
	 * total reflects replies only.
	 *
	 * @param int|null $new_count The new count, or null to use the default.
	 * @param int      $old_count The old count.
	 * @param int      $post_id   The post ID.
	 * @return int The comment count excluding reactions.
	 */
	public static function exclude_reactions_from_count( $new_count, $old_count, $post_id ) {
		if ( null !== $new_count ) {
			return $new_count;
		}

		$excluded     = self::reaction_comment_types();
		$placeholders = \implode( ', ', \array_fill( 0, \count( $excluded ), '%s' ) );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_approved = '1' AND comment_type NOT IN ( {$placeholders} )",
				\array_merge( array( $post_id ), $excluded )
			)
		);
	}

	/**
	 * Whether likes and reposts are imported (user setting).
	 *
	 * The gate is intentionally per-item: the sync watermarks keep
	 * advancing while the setting is off, so interactions from the
	 * off period are skipped for good rather than imported
	 * retroactively when the setting is re-enabled.
	 *
	 * @return bool
	 */
	private static function reactions_enabled(): bool {
		return is_reaction_sync_enabled();
	}

	/**
	 * Whether replies are imported as comments (user setting).
	 *
	 * Same going-forward semantics as {@see self::reactions_enabled()}:
	 * replies that arrive while the setting is off are not imported
	 * retroactively on re-enable.
	 *
	 * @return bool
	 */
	private static function replies_enabled(): bool {
		return is_reply_sync_enabled();
	}

	/**
	 * Tell WordPress that like and repost comments are avatar-eligible.
	 *
	 * @param array $types Registered avatar-eligible comment types.
	 * @return array
	 */
	public static function avatar_comment_types( array $types ): array {
		return \array_values( \array_unique( \array_merge( $types, array( 'comment', 'like', 'repost' ) ) ) );
	}

	/**
	 * Short-circuit get_avatar_data for atproto-sourced comments.
	 *
	 * @param array $args        Avatar args.
	 * @param mixed $id_or_email The comment, user, or email being rendered.
	 * @return array
	 */
	public static function filter_avatar_data( array $args, $id_or_email ): array {
		if ( ! $id_or_email instanceof \WP_Comment ) {
			return $args;
		}

		if ( 'atproto' !== \get_comment_meta( (int) $id_or_email->comment_ID, self::META_PROTOCOL, true ) ) {
			return $args;
		}

		$url = \get_comment_meta( (int) $id_or_email->comment_ID, self::META_AUTHOR_AVATAR, true );

		if ( ! $url ) {
			return $args;
		}

		$args['url']          = $url;
		$args['found_avatar'] = true;

		return $args;
	}

	/**
	 * Run the sync. Called by WP-Cron.
	 */
	public static function sync(): void {
		/*
		 * Connection-only mode: another plugin owns the connection and does
		 * not want ATmosphere reaching out to the PDS on its own. Skip the
		 * whole poll — the per-item gates already drop any writes, but bailing
		 * here also spares the hourly `listNotifications` + `listRecords`
		 * calls. Deliberately return *before* the watermarks advance, unlike
		 * the per-setting toggles: if the host later leaves connection-only
		 * mode, the interactions that arrived meanwhile are picked up rather
		 * than skipped for good.
		 *
		 * Gate on connection-only mode AND both lanes being off, so the
		 * documented filter contract still holds — a host that re-enables a
		 * lane via `atmosphere_should_sync_reactions` / `_replies` reaches the
		 * poll. Crucially, this must NOT fire for a regular site that simply
		 * unchecks both sync toggles: there the poll must still run so the
		 * per-item gates skip writes *while the watermarks advance*, keeping the
		 * off period "skipped for good" rather than replayed on re-enable.
		 */
		if ( is_connection_only_mode() && ! is_reaction_sync_enabled() && ! is_reply_sync_enabled() ) {
			return;
		}

		if ( ! is_connected() ) {
			return;
		}

		if ( ! self::lock() ) {
			debug_log( 'reaction sync skipped: another reaction sync or reply backfill is already running' );
			return;
		}

		try {
			self::sync_locked();
		} finally {
			self::unlock();
		}
	}

	/**
	 * Run reaction sync while holding the cross-process lock.
	 */
	private static function sync_locked(): void {
		self::prepare_account_state();

		/*
		 * Probe the access token once before walking the four streams.
		 * Without this, a refresh that's locked or has just flipped
		 * `needs_reauth` would be hit independently by each
		 * {@see self::paginate()} call below — surfacing the same
		 * incident four times in the error log and re-triggering the
		 * refresh path on a session we already know is broken.
		 */
		$token = Client::access_token();
		if ( \is_wp_error( $token ) ) {
			debug_log(
				\sprintf(
					'reaction sync aborted: %s — %s',
					$token->get_error_code(),
					$token->get_error_message()
				)
			);
			return;
		}

		// Reactions from other users.
		self::paginate(
			static fn( ?string $cursor ) => self::fetch_notifications( $cursor ),
			'notifications',
			self::OPTION_LAST_SEEN_NOTIFICATION,
			static fn( array $item ) => self::process_notification( $item )
		);

		// Self-reactions on our own posts (listNotifications omits these).
		self::sync_own_collection( 'app.bsky.feed.like', 'like' );
		self::sync_own_collection( 'app.bsky.feed.repost', 'repost' );
		self::sync_own_collection( 'app.bsky.feed.post', 'comment' );
	}

	/**
	 * Backfill replies for one already-published WordPress post.
	 *
	 * Unlike the notification stream, getPostThread is scoped to the target
	 * post, so it can recover replies that an older watermark already skipped
	 * without replaying the connected account's entire notification history.
	 * Existing comments are deduplicated by source AT-URI.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array{found: int, imported: int, existing: int, skipped: int, pending: int}|\WP_Error Result counts or an error.
	 */
	public static function backfill_replies( int $post_id ): array|\WP_Error {
		if ( ! is_connected() ) {
			return new \WP_Error(
				'atmosphere_not_connected',
				\__( 'Not connected to AT Protocol.', 'atmosphere' )
			);
		}

		if ( ! self::replies_enabled() ) {
			// Distinguish the user's own setting from an external override
			// (connection-only mode or the `atmosphere_should_sync_replies`
			// filter) so the reported reason matches the actual cause.
			$stored_on = '1' === (string) \get_option( 'atmosphere_sync_replies', '1' );
			return new \WP_Error(
				'atmosphere_reply_sync_disabled',
				$stored_on
					? \__( 'Reply syncing is turned off by another plugin on this site.', 'atmosphere' )
					: \__( 'Reply syncing is disabled in the ATmosphere settings.', 'atmosphere' )
			);
		}

		if ( ! self::lock() ) {
			return new \WP_Error(
				'atmosphere_reaction_sync_locked',
				\__( 'Another reaction sync or reply backfill is already running. Try again shortly.', 'atmosphere' )
			);
		}

		try {
			return self::backfill_replies_locked( $post_id );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Audit a bounded batch of published Bluesky posts for missed replies.
	 *
	 * Called by the daily `atmosphere_backfill_replies` cron event. Posts that
	 * have never been checked are selected first; after the initial sweep, the
	 * least-recently checked posts rotate back through the batch. Each attempt
	 * gets a timestamp even when the public AppView request fails, preventing a
	 * single unavailable thread from starving every newer post indefinitely.
	 */
	public static function backfill_scheduled_replies(): void {
		if ( ! is_connected() || ! self::replies_enabled() ) {
			return;
		}

		if ( ! self::lock() ) {
			debug_log( 'automatic reply backfill skipped: another reaction sync or reply backfill is already running' );
			return;
		}

		try {
			self::prepare_account_state();

			/**
			 * Filters how many posts the automatic reply backfill checks per run.
			 *
			 * Return 0 to disable the rolling audit while leaving normal reaction
			 * sync and the explicit WP-CLI backfill command enabled. Values above
			 * 20 are clamped to keep one WP-Cron request bounded.
			 *
			 * @since 2.1.0
			 *
			 * @param int $batch_size Posts checked per daily run. Default 5.
			 */
			$batch_size = (int) \apply_filters(
				'atmosphere_reply_backfill_batch_size',
				self::DEFAULT_BACKFILL_BATCH_SIZE
			);
			$batch_size = \min( 20, \max( 0, $batch_size ) );

			if ( 0 === $batch_size ) {
				return;
			}

			foreach ( self::get_backfill_post_ids( $batch_size ) as $post_id ) {
				if ( ! self::refresh_lock() ) {
					debug_log( 'automatic reply backfill stopped after losing its coordination lock' );
					break;
				}

				$result = self::backfill_replies_locked( $post_id );

				if ( \is_wp_error( $result ) ) {
					debug_log(
						\sprintf(
							'automatic reply backfill failed for post %d: %s — %s',
							$post_id,
							$result->get_error_code(),
							$result->get_error_message()
						)
					);

					/*
					 * A lost lock aborts the whole batch without stamping the
					 * post, so the next run retries it. Other errors count as
					 * an attempt and move on to the next post.
					 */
					if ( 'atmosphere_reaction_sync_lock_lost' === $result->get_error_code() ) {
						break;
					}

					\update_post_meta( $post_id, self::META_BACKFILL_CHECKED_AT, \time() );
					continue;
				}

				\update_post_meta( $post_id, self::META_BACKFILL_CHECKED_AT, \time() );

				if ( 0 < $result['imported'] ) {
					debug_log(
						\sprintf(
							'automatic reply backfill imported %d replies for post %d',
							$result['imported'],
							$post_id
						)
					);
				}
			}
		} finally {
			self::unlock();
		}
	}

	/**
	 * Backfill replies for one post while holding the cross-process lock.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array{found: int, imported: int, existing: int, skipped: int, pending: int}|\WP_Error Result counts or an error.
	 */
	private static function backfill_replies_locked( int $post_id ): array|\WP_Error {

		$post_uri         = (string) \get_post_meta( $post_id, BskyPost::META_URI, true );
		$resolved_post_id = self::find_post_by_bsky_uri( $post_uri );

		if ( '' === $post_uri || $post_id !== $resolved_post_id ) {
			return new \WP_Error(
				'atmosphere_reply_backfill_unavailable',
				\__( 'This post does not have a public Bluesky post to backfill.', 'atmosphere' )
			);
		}

		/*
		 * getPostThread is public and is not part of the plugin's requested
		 * OAuth RPC permissions. Call the public Bluesky AppView directly;
		 * routing it through the PDS would require a service-proxy grant that
		 * existing connections do not hold.
		 */
		$url      = \add_query_arg(
			array(
				'uri'          => $post_uri,
				'depth'        => 100,
				'parentHeight' => 0,
			),
			'https://public.api.bsky.app/xrpc/app.bsky.feed.getPostThread'
		);
		$response = \wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 120,
				'redirection' => 0,
			)
		);

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! self::refresh_lock() ) {
			return new \WP_Error(
				'atmosphere_reaction_sync_lock_lost',
				\__( 'The reply backfill lock expired before the Bluesky thread finished loading. Run the command again.', 'atmosphere' )
			);
		}

		$status = \wp_remote_retrieve_response_code( $response );

		if ( ! is_success_status( $status ) ) {
			return new \WP_Error(
				'atmosphere_reply_backfill_request_failed',
				\__( 'Could not load this post’s Bluesky thread.', 'atmosphere' ),
				array( 'status' => $status )
			);
		}

		$response = \json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( ! \is_array( $response ) ) {
			return new \WP_Error(
				'atmosphere_reply_backfill_invalid_response',
				\__( 'Bluesky returned an invalid thread response.', 'atmosphere' )
			);
		}

		$thread     = $response['thread'] ?? null;
		$thread_uri = \is_array( $thread ) ? (string) ( $thread['post']['uri'] ?? '' ) : '';

		if ( ! \is_array( $thread ) || $post_uri !== $thread_uri ) {
			return new \WP_Error(
				'atmosphere_reply_backfill_invalid_thread',
				\__( 'Bluesky returned an invalid thread for this post.', 'atmosphere' )
			);
		}

		return self::import_thread_replies( $post_uri, $thread );
	}

	/**
	 * Select posts for the next rolling automatic reply-backfill batch.
	 *
	 * Published, password-free posts with a Bluesky root URI are eligible.
	 * Never-checked posts are returned by ascending ID first, making the initial
	 * historical sweep deterministic. Remaining capacity is filled by the
	 * oldest checked timestamps so every post is eventually revisited.
	 *
	 * @param int $limit Maximum post IDs to return.
	 * @return int[] Post IDs to audit.
	 */
	private static function get_backfill_post_ids( int $limit ): array {
		$post_types = get_supported_post_types();

		if ( 1 > $limit || empty( $post_types ) ) {
			return array();
		}

		$common = array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$unchecked = \get_posts(
			\array_merge(
				$common,
				array(
					'posts_per_page' => $limit,
					'orderby'        => array( 'ID' => 'ASC' ),
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'     => BskyPost::META_URI,
							'value'   => '',
							'compare' => '!=',
						),
						array(
							'key'     => self::META_BACKFILL_CHECKED_AT,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			)
		);

		$post_ids  = \array_map( 'intval', $unchecked );
		$remaining = $limit - \count( $post_ids );

		if ( 1 > $remaining ) {
			return $post_ids;
		}

		$checked = \get_posts(
			\array_merge(
				$common,
				array(
					'posts_per_page' => $remaining,
					'meta_key'       => self::META_BACKFILL_CHECKED_AT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'orderby'        => array(
						'meta_value_num' => 'ASC',
						'ID'             => 'ASC',
					),
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => BskyPost::META_URI,
							'value'   => '',
							'compare' => '!=',
						),
					),
				)
			)
		);

		return \array_merge( $post_ids, \array_map( 'intval', $checked ) );
	}

	/**
	 * Import every usable reply in a hydrated Bluesky thread.
	 *
	 * @param string $post_uri Root Bluesky post AT-URI.
	 * @param array  $thread   Hydrated threadViewPost response node.
	 * The hydrated response is already a tree, so the pre-order traversal from
	 * {@see self::collect_thread_replies()} is also a parent-before-child order.
	 * Do not sort by record.createdAt: it is supplied by the remote author and
	 * may be skewed, which can otherwise put a child before its parent and make
	 * the child look unresolved.
	 *
	 * @return array{found: int, imported: int, existing: int, skipped: int, pending: int}|\WP_Error Result counts or an error.
	 */
	private static function import_thread_replies( string $post_uri, array $thread ): array|\WP_Error {
		$posts = array();
		self::collect_thread_replies( $thread, $posts );

		if ( self::MAX_THREAD_REPLIES <= \count( $posts ) ) {
			debug_log(
				\sprintf(
					'thread backfill for %s hit the %d-reply cap; remaining replies were skipped',
					$post_uri,
					self::MAX_THREAD_REPLIES
				)
			);
		}

		$existing_uris = self::find_existing_source_ids( \array_column( $posts, 'uri' ) );

		$found    = 0;
		$imported = 0;
		$existing = 0;
		$pending  = 0;

		foreach ( $posts as $post ) {
			if ( ! self::refresh_lock() ) {
				return new \WP_Error(
					'atmosphere_reaction_sync_lock_lost',
					\__( 'The reply backfill lock expired while comments were being imported. Run the command again.', 'atmosphere' )
				);
			}

			$record   = $post['record'] ?? array();
			$root_uri = \is_array( $record ) ? (string) ( $record['reply']['root']['uri'] ?? '' ) : '';

			if ( ! \is_array( $record ) || $post_uri !== $root_uri ) {
				continue;
			}

			$reply_uri = (string) ( $post['uri'] ?? '' );

			// A malformed/placeholder node without a URI is not a countable
			// reply — skip it before it inflates `found` and rides through
			// a doomed dedup + process_reply() pass.
			if ( '' === $reply_uri ) {
				continue;
			}

			++$found;

			if ( isset( $existing_uris[ $reply_uri ] ) ) {
				++$existing;
				continue;
			}

			$result = self::process_reply(
				array(
					'uri'    => $reply_uri,
					'cid'    => $post['cid'] ?? '',
					'author' => \is_array( $post['author'] ?? null ) ? $post['author'] : array(),
					'record' => $record,
				)
			);

			if ( \is_int( $result ) ) {
				++$imported;

				$comment = \get_comment( $result );

				if ( $comment && '1' !== (string) $comment->comment_approved ) {
					++$pending;
				}
			}
		}

		return array(
			'found'    => $found,
			'imported' => $imported,
			'existing' => $existing,
			'skipped'  => $found - $imported - $existing,
			'pending'  => $pending,
		);
	}

	/**
	 * Flatten hydrated reply nodes into post views.
	 *
	 * Blocked and not-found placeholders are not importable, but their reply
	 * arrays are still traversed in case the appview exposes visible children.
	 *
	 * @param array $thread Hydrated thread node.
	 * @param array $posts  Collected post views, passed by reference.
	 */
	private static function collect_thread_replies( array $thread, array &$posts ): void {
		$replies = $thread['replies'] ?? array();

		if ( ! \is_array( $replies ) ) {
			return;
		}

		foreach ( $replies as $reply ) {
			if ( self::MAX_THREAD_REPLIES <= \count( $posts ) ) {
				return;
			}

			if ( ! \is_array( $reply ) ) {
				continue;
			}

			if ( 'app.bsky.feed.defs#threadViewPost' === ( $reply['$type'] ?? '' )
				&& \is_array( $reply['post'] ?? null )
			) {
				$posts[] = $reply['post'];
			}

			self::collect_thread_replies( $reply, $posts );
		}
	}

	/**
	 * Walk one collection of the authenticated repo via listRecords.
	 *
	 * @param string $collection   AT Protocol collection NSID.
	 * @param string $comment_type Target WP comment_type (like/repost/comment).
	 */
	private static function sync_own_collection( string $collection, string $comment_type ): void {
		$parts      = \explode( '.', $collection );
		$option_key = self::OPTION_LAST_SEEN_OWN_PREFIX . \end( $parts );

		self::paginate(
			static fn( ?string $cursor ) => API::list_records( $collection, 50, $cursor ),
			'records',
			$option_key,
			static fn( array $item ) => self::process_own_record( $item, $comment_type )
		);
	}

	/**
	 * Paginate a Bluesky listing until the previous run's watermark is
	 * reached (plus a grace window), MAX_PAGES is hit, or the cursor
	 * runs out.
	 *
	 * Stores the first item's URI as the new watermark so the next run
	 * can short-circuit instead of re-walking already-processed history.
	 * When the watermark URI is hit, paginate keeps walking for up to
	 * WATERMARK_GRACE more items before stopping, so transient drops
	 * from the prior run get a second chance.
	 *
	 * @param callable $fetch      Receives ?string $cursor, returns array|WP_Error.
	 * @param string   $items_key  Key inside the response holding the items array.
	 * @param string   $option_key Option name for the watermark.
	 * @param callable $process    Receives one item array.
	 */
	private static function paginate( callable $fetch, string $items_key, string $option_key, callable $process ): void {
		$state     = self::get_pagination_state( $option_key );
		$resuming  = ! empty( $state );
		$last_seen = $resuming ? $state['last_seen'] : (string) \get_option( $option_key, '' );
		$newest    = $resuming && '' !== $state['newest'] ? $state['newest'] : null;
		$cursor    = $resuming && '' !== $state['cursor'] ? $state['cursor'] : null;
		$phase     = $resuming ? $state['phase'] : 1;
		$pages     = 0;
		$rewalk    = null;
		$collected = array();
		$complete  = false;

		while ( $pages < self::MAX_PAGES ) {
			if ( ! self::refresh_lock() ) {
				debug_log( \sprintf( 'reaction sync (%s) stopped after losing its coordination lock', $option_key ) );
				return;
			}

			$response = $fetch( $cursor );

			if ( \is_wp_error( $response ) ) {
				/*
				 * Leave a breadcrumb so operators can diagnose why sync
				 * stopped making progress (rate limit, OAuth refresh
				 * failure, transient 5xx all surface here identically).
				 */
				debug_log(
					\sprintf(
						'reaction sync (%s) fetch failed: %s — %s',
						$option_key,
						$response->get_error_code(),
						$response->get_error_message()
					)
				);

				if ( $resuming ) {
					$failures = $state['failures'] + 1;

					if ( self::MAX_PAGINATION_FAILURES <= $failures ) {
						self::delete_pagination_state( $option_key );
						debug_log(
							\sprintf(
								'reaction sync (%s) discarded its saved cursor after %d consecutive failures; the next run will restart from the newest page',
								$option_key,
								$failures
							)
						);
					} else {
						$state['failures'] = $failures;
						self::save_pagination_state( $option_key, $state );
					}
				}

				return;
			}

			$items = $response[ $items_key ] ?? array();

			if ( empty( $items ) ) {
				$complete = true;
				break;
			}

			foreach ( $items as $item ) {
				$uri = $item['uri'] ?? '';

				if ( null === $newest && $uri ) {
					$newest = $uri;
				}

				if ( null === $rewalk && $last_seen && $uri === $last_seen ) {
					/*
					 * +1 because the watermark item itself is collected
					 * (and dedup-skipped) before this counter decrements;
					 * initialising to GRACE + 1 means we re-walk exactly
					 * GRACE items strictly past the watermark.
					 */
					$rewalk = self::WATERMARK_GRACE + 1;
				}

				if ( 0 === $rewalk ) {
					$complete = true;
					break 2;
				}

				$collected[] = $item;

				if ( null !== $rewalk ) {
					--$rewalk;
				}
			}

			$cursor = $response['cursor'] ?? null;
			++$pages;

			if ( ! $cursor ) {
				$complete = true;
				break;
			}
		}

		/*
		 * An armed grace counter means the watermark was reached, even when
		 * the page budget ran out before the window was fully re-walked.
		 * Saving a continuation here would resume *past* the watermark —
		 * every older item can never match it, so the walk would continue
		 * through the account's entire remaining history. Forfeit the
		 * unwalked tail of the best-effort grace window instead.
		 */
		if ( null !== $rewalk ) {
			$complete = true;
		}

		/*
		 * Process oldest-first. Bluesky streams newest-first, but a reply's
		 * parent (a post record or an earlier reply) is always older than the
		 * reply itself. Processing in stream order therefore reaches a child
		 * reply before its parent exists as a local comment, and
		 * process_reply() drops any reply whose parent it can't resolve. By
		 * reversing to oldest-first, a parent is synced before any reply that
		 * targets it, so an entire thread that arrives within one run resolves
		 * in that run — instead of relying on the next run's WATERMARK_GRACE
		 * re-walk, which only reaches GRACE items and loses deeper or bursty
		 * threads. Dedup via source_id keeps the reprocessing idempotent, and
		 * the watermark below is still the newest URI regardless of order.
		 */
		foreach ( \array_reverse( $collected ) as $item ) {
			$process( $item );

			if ( ! self::refresh_lock() ) {
				debug_log( \sprintf( 'reaction sync (%s) stopped after losing its coordination lock', $option_key ) );
				return;
			}
		}

		/*
		 * A stored watermark means this is an incremental sync, not the
		 * intentionally bounded first import. If five pages were not enough
		 * to reach that watermark, persist the response cursor and continue
		 * from it next time. Advancing the watermark here used to strand every
		 * item beyond page five permanently.
		 */
		if ( ! $complete && $cursor && $last_seen ) {
			self::save_pagination_state(
				$option_key,
				array(
					'cursor'    => $cursor,
					'newest'    => (string) $newest,
					'last_seen' => $last_seen,
					'phase'     => $phase,
					'failures'  => 0,
				)
			);
			return;
		}

		/*
		 * The first overflow pass is processed in bounded chunks. A newer
		 * nested reply can therefore appear in an earlier chunk than its
		 * older parent and be skipped before that parent is imported. Replay
		 * the completed range once; dedup makes successful items cheap, while
		 * previously-unresolved children now find their parents.
		 */
		if ( $resuming && 1 === $phase ) {
			self::save_pagination_state(
				$option_key,
				array(
					'cursor'    => '',
					'newest'    => '',
					'last_seen' => $last_seen,
					'phase'     => 2,
					'failures'  => 0,
				)
			);
			return;
		}

		self::delete_pagination_state( $option_key );

		if ( $newest ) {
			\update_option( $option_key, $newest, false );
		}
	}

	/**
	 * Read and validate one stream's saved pagination state.
	 *
	 * @param string $option_key Stream watermark option name.
	 * @return array{cursor: string, newest: string, last_seen: string, phase: int, failures: int}|array{}
	 */
	private static function get_pagination_state( string $option_key ): array {
		$states = \get_option( self::OPTION_PAGINATION_STATE, array() );

		if ( ! \is_array( $states ) ) {
			\delete_option( self::OPTION_PAGINATION_STATE );
			return array();
		}

		if ( ! isset( $states[ $option_key ] ) ) {
			return array();
		}

		/*
		 * A non-array entry has no valid `cursor` and fails the field
		 * validation below, taking the same delete-and-return path.
		 */
		$state    = \is_array( $states[ $option_key ] ) ? $states[ $option_key ] : array();
		$phase    = (int) ( $state['phase'] ?? 0 );
		$failures = (int) ( $state['failures'] ?? 0 );

		if ( ! \is_string( $state['cursor'] ?? null )
			|| ! \is_string( $state['newest'] ?? null )
			|| ! \is_string( $state['last_seen'] ?? null )
			|| ! \in_array( $phase, array( 1, 2 ), true )
			|| 0 > $failures
			|| get_did() !== (string) ( $state['did'] ?? '' )
		) {
			self::delete_pagination_state( $option_key );
			return array();
		}

		return array(
			'cursor'    => $state['cursor'],
			'newest'    => $state['newest'],
			'last_seen' => $state['last_seen'],
			'phase'     => $phase,
			'failures'  => $failures,
		);
	}

	/**
	 * Persist one stream's continuation without disturbing other streams.
	 *
	 * @param string $option_key Stream watermark option name.
	 * @param array  $state      Validated continuation state.
	 */
	private static function save_pagination_state( string $option_key, array $state ): void {
		$states = \get_option( self::OPTION_PAGINATION_STATE, array() );

		if ( ! \is_array( $states ) ) {
			$states = array();
		}

		$state['did'] = get_did();

		$states[ $option_key ] = $state;
		\update_option( self::OPTION_PAGINATION_STATE, $states, false );
	}

	/**
	 * Remove a completed stream's continuation state.
	 *
	 * @param string $option_key Stream watermark option name.
	 */
	private static function delete_pagination_state( string $option_key ): void {
		$states = \get_option( self::OPTION_PAGINATION_STATE, array() );

		if ( ! \is_array( $states ) || ! isset( $states[ $option_key ] ) ) {
			return;
		}

		unset( $states[ $option_key ] );

		if ( empty( $states ) ) {
			\delete_option( self::OPTION_PAGINATION_STATE );
			return;
		}

		\update_option( self::OPTION_PAGINATION_STATE, $states, false );
	}

	/**
	 * Reset watermarks when reaction sync is used with a different account.
	 *
	 * Watermark URIs and saved cursors are repository-specific. Reusing them
	 * after the site reconnects to another DID can make the new account walk
	 * an unrelated history or resume a cursor issued for the previous repo.
	 */
	private static function prepare_account_state(): void {
		$did        = get_did();
		$stored_did = (string) \get_option( self::OPTION_SYNC_DID, '' );

		if ( '' === $did ) {
			return;
		}

		if ( '' !== $stored_did && $did !== $stored_did ) {
			\delete_option( self::OPTION_LAST_SEEN_NOTIFICATION );
			\delete_option( self::OPTION_LAST_SEEN_OWN_PREFIX . 'like' );
			\delete_option( self::OPTION_LAST_SEEN_OWN_PREFIX . 'repost' );
			\delete_option( self::OPTION_LAST_SEEN_OWN_PREFIX . 'post' );
			\delete_option( self::OPTION_PAGINATION_STATE );
		}

		if ( $did !== $stored_did ) {
			\update_option( self::OPTION_SYNC_DID, $did, false );
		}
	}

	/**
	 * The lease lock guarding reaction sync and targeted reply backfills.
	 *
	 * @return Lock
	 */
	private static function get_lock(): Lock {
		if ( null === self::$lock ) {
			self::$lock = new Lock( self::LOCK_OPTION, self::LOCK_TTL );
		}

		return self::$lock;
	}

	/**
	 * Acquire the cross-process reaction-sync lock.
	 *
	 * @return bool Whether this request owns the lock.
	 */
	private static function lock(): bool {
		return self::get_lock()->acquire();
	}

	/**
	 * Renew the lock lease owned by this request.
	 *
	 * Calls outside a locked sync (notably focused private-method tests) are a
	 * no-op. A failed renewal means another worker reclaimed the lock and the
	 * current worker must stop writing.
	 *
	 * @return bool Whether this request still owns the lock.
	 */
	private static function refresh_lock(): bool {
		return self::get_lock()->renew();
	}

	/**
	 * Release this request's lock without deleting a successor's lock.
	 */
	private static function unlock(): void {
		self::get_lock()->release();
	}

	/**
	 * Fetch a page of notifications from the PDS.
	 *
	 * No server-side reason filter — the XRPC array-query encoding
	 * produced by http_build_query is incompatible with Bluesky's
	 * repeated-key convention. process_notification's default case
	 * skips non-reaction reasons cheaply.
	 *
	 * @param string|null $cursor Pagination cursor.
	 * @return array|\WP_Error
	 */
	private static function fetch_notifications( ?string $cursor = null ): array|\WP_Error {
		$params = array( 'limit' => 50 );

		if ( null !== $cursor ) {
			$params['cursor'] = $cursor;
		}

		return API::get( '/xrpc/app.bsky.notification.listNotifications', $params );
	}

	/**
	 * Dispatch a listNotifications notification by reason.
	 *
	 * @param array $notification Notification from listNotifications.
	 * @return int|false Comment ID if inserted, false if skipped.
	 */
	private static function process_notification( array $notification ): int|false {
		switch ( $notification['reason'] ?? '' ) {
			case 'reply':
				return self::process_reply( $notification );
			case 'like':
				return self::process_subject_reaction( $notification, 'like' );
			case 'repost':
				return self::process_subject_reaction( $notification, 'repost' );
			default:
				return false;
		}
	}

	/**
	 * Dispatch one of our own records (like/repost/post) as a reaction.
	 *
	 * Records that target something other than a local WP post are
	 * silently skipped — reactions to other people's content don't
	 * belong on our site.
	 *
	 * @param array  $record       listRecords entry (uri, cid, value).
	 * @param string $comment_type Target WP comment_type (like/repost/comment).
	 * @return int|false Comment ID or false.
	 */
	private static function process_own_record( array $record, string $comment_type ): int|false {
		$value = $record['value'] ?? array();

		// Original (non-reply) posts are not reactions.
		if ( 'comment' === $comment_type && empty( $value['reply'] ) ) {
			return false;
		}

		$did = get_did();

		/*
		 * getProfile can fail transiently, and a call that succeeds can
		 * still hand back an empty handle. Our own handle is stored
		 * locally, so fall back to it in both cases and the comment
		 * author link never degrades to a broken profile URL.
		 */
		$handle = self::resolve_author( $did )['handle'] ?? '';

		if ( '' === $handle ) {
			$handle = get_identity()['handle'] ?? '';
		}

		$notification = array(
			'uri'    => $record['uri'] ?? '',
			'cid'    => $record['cid'] ?? '',
			'author' => array(
				'did'    => $did,
				'handle' => $handle,
			),
			'record' => $value,
		);

		return 'comment' === $comment_type
			? self::process_reply( $notification )
			: self::process_subject_reaction( $notification, $comment_type );
	}

	/**
	 * Process a reply notification into a WordPress comment.
	 *
	 * @param array $notification Notification or synthesized own-record.
	 * @return int|false Comment ID or false.
	 */
	private static function process_reply( array $notification ): int|false {
		if ( ! self::replies_enabled() ) {
			return false;
		}

		$reply_uri = $notification['uri'] ?? '';
		$record    = $notification['record'] ?? array();
		$author    = $notification['author'] ?? array();

		if ( empty( $reply_uri ) || empty( $record ) ) {
			return false;
		}

		if ( self::find_comment_by_source_id( $reply_uri ) ) {
			return false;
		}

		$parent_uri = $record['reply']['parent']['uri'] ?? '';

		if ( empty( $parent_uri ) ) {
			return false;
		}

		$comment_parent = 0;
		$post_id        = self::find_post_by_bsky_uri( $parent_uri );

		if ( ! $post_id ) {
			// Nested reply: parent is an existing synced comment. Only
			// approved/pending parents resolve — a moderated-away parent
			// drops the reply as an orphan below.
			$parent_comment_id = self::find_comment_by_source_id( $parent_uri, self::PARENT_COMMENT_STATUSES );

			if ( $parent_comment_id ) {
				$parent_comment = \get_comment( $parent_comment_id );

				if ( $parent_comment ) {
					$post_id        = (int) $parent_comment->comment_post_ID;
					$comment_parent = $parent_comment_id;
				}
			}
		}

		/*
		 * Drop replies whose parent we can't resolve. A parent that
		 * doesn't match a local WP post or a previously-synced WP
		 * comment is either:
		 *
		 *   - Orphaned: the parent was deleted on Bluesky (e.g. blocked
		 *     user, account deletion), or the admin moderated it away
		 *     locally (spam, trash, hard delete — see
		 *     PARENT_COMMENT_STATUSES). Falling back to the root post
		 *     would resurface a suppressed conversation and re-attach
		 *     every subsequent re-walk as a top-level orphan, looping
		 *     the moderation queue indefinitely until the watermark
		 *     advances past it.
		 *
		 *   - Out-of-order: a deep reply seen before its parent in the
		 *     same `listNotifications`/`listRecords` page. Dropping
		 *     here is recoverable: the parent gets synced in the same
		 *     run, the child re-enters via the WATERMARK_GRACE window
		 *     on the next run, and parent resolution succeeds.
		 *
		 * Direct replies (parent_uri is one of our WP posts) and
		 * resolved nested replies are unaffected.
		 */
		if ( ! $post_id ) {
			return false;
		}

		$text = $record['text'] ?? '';

		/*
		 * Bluesky stores `text` as the display string — long URLs are
		 * truncated to e.g. `bsky.app/profile/jere...` and the real
		 * target lives only in `facets`. Resolve those byte ranges back
		 * into anchors before the text becomes comment content; otherwise
		 * imported replies keep the lossy, unclickable display string.
		 *
		 * `facets` is untrusted PDS JSON: guard the type here so a
		 * present-but-non-array value can't fatal the typed `apply()` call
		 * and silently kill this notification's cron sync.
		 */
		$facets = $record['facets'] ?? array();
		$text   = Facet::apply( $text, \is_array( $facets ) ? $facets : array() );

		/*
		 * A reply that quotes another post carries the quoted post's
		 * AT-URI in `embed` (app.bsky.embed.record), not in `text`.
		 * Surface it as a linked blockquote so the quote isn't dropped on
		 * import; append after the reply text, mirroring how Bluesky
		 * renders the quote card below the reply.
		 */
		$quote = self::build_quote_html( $record );

		if ( '' !== $quote ) {
			$text = '' === $text ? $quote : $text . "\n\n" . $quote;
		}

		/*
		 * Drop replies that carry neither text nor a resolvable quote —
		 * the gate is here, after both are resolved, so a quote-only
		 * reply (empty `text`, populated `embed`) still imports.
		 */
		if ( '' === $text ) {
			return false;
		}

		/**
		 * Filters whether a reply should be synced as a WordPress comment.
		 *
		 * Fires after the reply's target post and parent comment have been
		 * resolved, immediately before any work that depends on the reply
		 * being kept (author profile resolution, comment row insert,
		 * comment-meta writes). Return false to skip the insert.
		 *
		 * Use case: consumers publishing multi-record threads from their
		 * own DID may want to skip the round-tripped self-replies that
		 * `Reaction_Sync` would otherwise ingest as comments. The filter
		 * is intentionally policy-free upstream so consumers can express
		 * whatever discriminator fits their publishing strategy.
		 *
		 * @param bool  $should         Whether to sync this reply. Default true.
		 * @param array $notification   Notification or synthesized own-record.
		 * @param int   $post_id        Resolved local WP post the reply targets.
		 * @param int   $comment_parent Resolved local parent comment ID, 0 if top-level.
		 */
		$should_sync = (bool) \apply_filters(
			'atmosphere_should_sync_reply',
			true,
			$notification,
			$post_id,
			$comment_parent
		);

		if ( ! $should_sync ) {
			return false;
		}

		$profile = self::resolve_author( $author['did'] ?? '' );

		return self::insert_reaction( $post_id, 'comment', $text, $comment_parent, $notification, $profile );
	}

	/**
	 * Process a like/repost reaction whose target is at record.subject.uri.
	 *
	 * @param array  $notification Notification or synthesized own-record.
	 * @param string $comment_type 'like' or 'repost'.
	 * @return int|false Comment ID or false.
	 */
	private static function process_subject_reaction( array $notification, string $comment_type ): int|false {
		if ( ! self::reactions_enabled() ) {
			return false;
		}

		$uri    = $notification['uri'] ?? '';
		$record = $notification['record'] ?? array();
		$author = $notification['author'] ?? array();

		if ( empty( $uri ) || empty( $record ) ) {
			return false;
		}

		if ( self::find_comment_by_source_id( $uri ) ) {
			return false;
		}

		$subject_uri = $record['subject']['uri'] ?? '';
		$post_id     = $subject_uri ? self::find_post_by_bsky_uri( $subject_uri ) : false;

		if ( ! $post_id ) {
			return false;
		}

		$profile = self::resolve_author( $author['did'] ?? '' );

		return self::insert_reaction( $post_id, $comment_type, '', 0, $notification, $profile );
	}

	/**
	 * Insert a reaction as a WordPress comment and persist its meta.
	 *
	 * Callers are responsible for target-post resolution, dedup, and
	 * the comment_type; this method just writes the row and meta.
	 *
	 * @param int    $post_id        WP post the reaction attaches to.
	 * @param string $comment_type   One of 'comment', 'like', 'repost'.
	 * @param string $content        Comment body (reply text, or '' for like/repost).
	 * @param int    $comment_parent Parent comment ID, 0 for top-level.
	 * @param array  $notification   Raw notification or synthesized own-record.
	 * @param array  $profile        Resolved author profile (name, handle, avatar).
	 * @return int|false Comment ID or false.
	 */
	private static function insert_reaction(
		int $post_id,
		string $comment_type,
		string $content,
		int $comment_parent,
		array $notification,
		array $profile
	): int|false {
		/*
		 * Deliberately NOT gating on `comments_open( $post_id )` here.
		 * `paginate()` advances the per-collection watermark to the
		 * newest URI on each page regardless of whether the per-item
		 * callback accepted the item; if we dropped reactions on
		 * closed-comments posts at this gate, the watermark would
		 * still move past them and a subsequent reopen of comments
		 * could not recover the missed imports — the WATERMARK_GRACE
		 * window is only ten items.
		 *
		 * Federated reactions are an audit record of activity that
		 * happened on Bluesky, not a comment-form submission. Insert
		 * the row, let the moderation pipeline below decide the
		 * approval state, and accept that imported reactions remain
		 * in `wp_comments` even on closed-comments posts.
		 *
		 * Caveat: WordPress's stock `comments_template()` hides
		 * comments when `comments_open()` is false, but themes that
		 * render comments without going through `comments_template()`,
		 * and the REST `/wp/v2/comments` endpoint, will still surface
		 * these rows on closed-comments posts. Sites that need
		 * stricter rendering control should filter `the_comments` /
		 * `rest_prepare_comment` themselves; we don't register
		 * display-side filters here because the existence of the
		 * comment row is the federation history record this method
		 * is responsible for preserving.
		 */

		$uri    = $notification['uri'] ?? '';
		$cid    = $notification['cid'] ?? '';
		$author = $notification['author'] ?? array();
		$record = $notification['record'] ?? array();

		// Same sanitizer as resolve_author(), for the reason documented there.
		$author_handle = \sanitize_text_field( $profile['handle'] ?? '' );

		if ( '' === $author_handle ) {
			$author_handle = \sanitize_text_field( $author['handle'] ?? '' );
		}

		$author_name = \sanitize_text_field( $profile['name'] ?? '' );

		if ( '' === $author_name ) {
			$author_name = $author_handle;
		}

		$timestamp = \strtotime( $record['createdAt'] ?? '' );
		$gm_date   = \gmdate( 'Y-m-d H:i:s', false === $timestamp ? 0 : $timestamp );

		if ( '' === $content ) {
			$content = self::default_reaction_excerpt( $comment_type );
		}

		/*
		 * Encoded, not just stripped: core's comment pipeline stores the
		 * author name HTML-encoded, and every reader of the column relies
		 * on that, the XML feed templates included.
		 *
		 * `_wp_specialchars()` is marked `@access private` in core, so
		 * there is no backward compatibility promise on it. It is the only
		 * way to match what `pre_comment_author_name` produces byte for
		 * byte, which is the whole point here — if it ever goes away,
		 * inline its `htmlspecialchars()` call rather than reaching for a
		 * different encoder.
		 */
		$comment_author = \_wp_specialchars( $author_name );

		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_parent'       => $comment_parent,
			'comment_author'       => $comment_author,
			'comment_author_url'   => \esc_url_raw(
				appview_url(
					'profile/' . \rawurlencode( $author_handle ),
					array(
						'type'   => 'profile',
						'handle' => $author_handle,
					)
				)
			),
			'comment_author_email' => '',
			'comment_author_IP'    => '',
			'comment_content'      => \wp_kses_post( $content ),
			'comment_date'         => \get_date_from_gmt( $gm_date ),
			'comment_date_gmt'     => $gm_date,
			'comment_type'         => $comment_type,
			'comment_agent'        => 'ATmosphere/' . ATMOSPHERE_VERSION,
			'user_id'              => 0,
		);

		/*
		 * Run the full WordPress moderation pipeline rather than just
		 * gating on `comment_moderation`. `wp_allow_comment()` evaluates
		 * the same chain WordPress applies to native comment submissions:
		 *
		 *   - `comment_moderation` ("hold all comments")
		 *   - `comment_whitelist` (previously-approved-author bypass)
		 *   - `comment_max_links` threshold
		 *   - `disallowed_keys` blacklist (returns WP_Error)
		 *   - `moderation_keys` (returns approved=0)
		 *   - the `pre_comment_approved` filter chain, which is where
		 *     Akismet stamps spam verdicts and where any third-party
		 *     anti-spam plugin hooks in.
		 *
		 * Without this call, importing Bluesky reactions silently
		 * bypassed every one of those checks; only `comment_moderation`
		 * was honoured. A `WP_Error` return means the comment was
		 * hard-rejected (e.g. disallowed-keys hit) — drop the import.
		 *
		 * `wp_is_comment_flood` is short-circuited to false for this
		 * one call: federated reactions are server-to-server traffic
		 * without an IP, so WordPress's IP/email-based 15-second flood
		 * heuristic doesn't model them correctly — rate-limiting for
		 * inbound reactions happens upstream at Bluesky's relay. The
		 * filter is removed immediately after the call so it cannot
		 * affect any subsequent user-submitted comment in the same
		 * request.
		 *
		 * The `ATmosphere/` `comment_agent` stamp keeps the outbound
		 * comment-publish cron (`atmosphere_publish_comment`) from
		 * picking the row back up regardless of approval state, so a
		 * held reaction can be approved in wp-admin later without
		 * being written back to the Bluesky PDS.
		 */
		\add_filter( 'wp_is_comment_flood', '__return_false', 99 );
		$approved = \wp_allow_comment( $comment_data, true );
		\remove_filter( 'wp_is_comment_flood', '__return_false', 99 );

		if ( \is_wp_error( $approved ) ) {
			return false;
		}

		$comment_data['comment_approved'] = $approved;

		$comment_id = \wp_insert_comment( $comment_data );

		if ( ! $comment_id ) {
			return false;
		}

		\update_comment_meta( $comment_id, self::META_PROTOCOL, 'atproto' );
		\update_comment_meta( $comment_id, self::META_SOURCE_ID, $uri );

		/*
		 * source_url points at the bsky.app page for the reaction itself
		 * and is intentionally empty for likes and reposts, which have no
		 * bsky.app landing page. For those, comment_author_url (the
		 * author profile) is what links out to Bluesky.
		 */
		\update_comment_meta( $comment_id, self::META_SOURCE_URL, self::build_bsky_web_url( $uri, $author_handle ) );
		\update_comment_meta( $comment_id, self::META_BSKY_CID, $cid );
		\update_comment_meta( $comment_id, self::META_AUTHOR_DID, $author['did'] ?? '' );
		$author_avatar = $profile['avatar'] ?? '';
		\update_comment_meta( $comment_id, self::META_AUTHOR_AVATAR, \is_string( $author_avatar ) ? \esc_url_raw( $author_avatar ) : '' );

		/**
		 * Fires after a Bluesky reaction is synced as a WordPress comment.
		 *
		 * @param int    $comment_id   The new comment ID.
		 * @param array  $notification The raw notification or own-record.
		 * @param int    $post_id      The WordPress post ID.
		 * @param string $comment_type One of 'comment', 'like', 'repost'.
		 */
		\do_action( 'atmosphere_reaction_synced', $comment_id, $notification, $post_id, $comment_type );

		return $comment_id;
	}

	/**
	 * Default comment body for content-less reactions (likes and reposts).
	 *
	 * Mirrors the wording the wordpress-activitypub plugin uses for its
	 * own like/repost rows, so themes that render activity feeds get a
	 * consistent reading experience across protocols. The leading
	 * ellipsis is intentional: most themes render the author name
	 * immediately before the comment body, so the result reads as
	 * "Jane Doe … liked this!".
	 *
	 * @param string $comment_type One of 'like', 'repost'.
	 * @return string Translated excerpt, or empty string for unknown types.
	 */
	private static function default_reaction_excerpt( string $comment_type ): string {
		switch ( $comment_type ) {
			case 'like':
				return \__( '… liked this!', 'atmosphere' );
			case 'repost':
				return \__( '… reposted this!', 'atmosphere' );
			default:
				return '';
		}
	}

	/**
	 * Resolve a Bluesky actor profile via getProfile, transient-cached.
	 *
	 * @param string $did Author DID.
	 * @return array{name?: string, handle?: string, avatar?: string}
	 */
	private static function resolve_author( string $did ): array {
		if ( empty( $did ) ) {
			return array();
		}

		$cache_key = 'atmosphere_profile_' . \md5( $did );
		$cached    = \get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = API::get( '/xrpc/app.bsky.actor.getProfile', array( 'actor' => $did ) );

		if ( \is_wp_error( $result ) ) {
			/*
			 * Deliberately not cached: a transient blip must not poison
			 * the profile cache for an hour. The reaction still imports
			 * with a degraded profile (payload handle, no avatar) — leave
			 * a breadcrumb, since dedup means it is never revisited.
			 */
			debug_log(
				\sprintf(
					'getProfile failed for %s: %s — %s',
					$did,
					$result->get_error_code(),
					$result->get_error_message()
				)
			);

			return array();
		}

		/*
		 * `sanitize_text_field()`, not `Atmosphere\sanitize_text()`. The
		 * helper decodes entities before it strips tags, which is right
		 * for a value we are about to publish into an AT Protocol record
		 * — see its use for `displayName` in Transformer\Document.
		 *
		 * This value goes the other way, into `comment_author`, where the
		 * only safe shape is the one core itself would have stored. Core
		 * runs `sanitize_text_field()` on `pre_comment_author_name` and
		 * nothing downstream decodes the column before output, so
		 * decoding here would break that parity for no gain.
		 */
		$handle = \sanitize_text_field( $result['handle'] ?? '' );
		$name   = \sanitize_text_field( $result['displayName'] ?? '' );
		$avatar = $result['avatar'] ?? '';

		$profile = array(
			'name'   => '' !== $name ? $name : $handle,
			'handle' => $handle,
			'avatar' => \is_string( $avatar ) ? \esc_url_raw( $avatar ) : '',
		);

		\set_transient( $cache_key, $profile, HOUR_IN_SECONDS );

		return $profile;
	}

	/**
	 * Build the appview web URL for a given AT-URI + handle.
	 *
	 * Only app.bsky.feed.post records have a corresponding appview web
	 * page; like and repost rkeys don't, so those return ''. The host
	 * defaults to `bsky.app` and is filterable via `atmosphere_appview_host`.
	 *
	 * @param string $at_uri AT-URI.
	 * @param string $handle Bluesky handle.
	 * @return string
	 */
	private static function build_bsky_web_url( string $at_uri, string $handle ): string {
		if ( empty( $at_uri ) || empty( $handle ) ) {
			return '';
		}

		$parts = \explode( '/', $at_uri );
		$rkey  = \end( $parts );

		if ( empty( $rkey ) || 'app.bsky.feed.post' !== \prev( $parts ) ) {
			return '';
		}

		return \esc_url_raw(
			appview_url(
				'profile/' . \rawurlencode( $handle ) . '/post/' . $rkey,
				array(
					'type'   => 'post',
					'handle' => $handle,
					'rkey'   => $rkey,
				)
			)
		);
	}

	/**
	 * Build a blockquote linking to a reply's quoted post, if any.
	 *
	 * Bluesky quote-posts attach the quoted record to the reply's `embed`
	 * (`app.bsky.embed.record`, or `recordWithMedia` when the quote also
	 * carries media), pointing at the quoted post's AT-URI — the reply's
	 * `text` says nothing about it. Without this, the quote is dropped on
	 * import.
	 *
	 * Returns an HTML fragment safe to pass through the caller's
	 * `wp_kses_post()` gate, or '' when there's no quoted `app.bsky.feed.post`
	 * to link to. The quoted post's own text is intentionally not resolved:
	 * it usually lives in another actor's repo, which would require a
	 * separate network round-trip per quote — a link to the quote is enough
	 * to stop the drop.
	 *
	 * @param array $record The reply record (notification record or own value).
	 * @return string Blockquote HTML, or '' when there's no linkable quote.
	 */
	private static function build_quote_html( array $record ): string {
		$embed = $record['embed'] ?? null;

		if ( ! \is_array( $embed ) ) {
			return '';
		}

		$uri = self::quoted_post_uri( $embed );
		$url = '' === $uri ? '' : self::quoted_post_web_url( $uri );

		if ( '' === $url ) {
			return '';
		}

		$href = \esc_url( $url );

		if ( '' === $href ) {
			return '';
		}

		return '<blockquote class="atmosphere-bsky-quote"><p><a href="' . $href . '">'
			. \esc_html__( 'Quoted post on Bluesky', 'atmosphere' )
			. '</a></p></blockquote>';
	}

	/**
	 * Read the quoted post's AT-URI out of a reply's `embed`.
	 *
	 * Handles `app.bsky.embed.record` (URI at `record.uri`) and
	 * `app.bsky.embed.recordWithMedia` (one level deeper at
	 * `record.record.uri`), along with their hydrated `#view` forms, which
	 * share the same paths. Every level is `is_array()`-guarded because the
	 * embed is untrusted PDS JSON; an unrecognised or malformed shape
	 * returns '' rather than fataling the cron sync.
	 *
	 * @param array $embed The record's `embed` value.
	 * @return string The quoted post's AT-URI, or '' when absent/malformed.
	 */
	private static function quoted_post_uri( array $embed ): string {
		$type = $embed['$type'] ?? '';

		if ( ! \is_string( $type ) || ! \str_starts_with( $type, 'app.bsky.embed.record' ) ) {
			return '';
		}

		$record = $embed['record'] ?? null;

		if ( ! \is_array( $record ) ) {
			return '';
		}

		// recordWithMedia nests the quoted record one level deeper.
		if ( ! isset( $record['uri'] ) && \is_array( $record['record'] ?? null ) ) {
			$record = $record['record'];
		}

		$uri = $record['uri'] ?? '';

		return \is_string( $uri ) ? $uri : '';
	}

	/**
	 * Convert a quoted post's AT-URI to its appview web URL.
	 *
	 * Builds the DID form (`profile/<did>/post/<rkey>`), which resolves
	 * without a handle lookup. The host defaults to `bsky.app` and is
	 * filterable via `atmosphere_appview_host`. Only `app.bsky.feed.post`
	 * records have an appview post page, so any other collection — or a
	 * malformed URI — returns ''.
	 *
	 * @param string $at_uri Quoted post AT-URI.
	 * @return string The appview URL, or '' when not a linkable post.
	 */
	private static function quoted_post_web_url( string $at_uri ): string {
		$parts = parse_at_uri( $at_uri );

		if ( false === $parts ) {
			return '';
		}

		if ( 'app.bsky.feed.post' !== $parts['collection'] || '' === $parts['did'] || '' === $parts['rkey'] ) {
			return '';
		}

		// The DID is passed raw, matching the other profile/<did>/post/<rkey>
		// builders (Atmosphere::* and Facet); its colons are valid path chars
		// the appview expects unencoded. Handles, by contrast, are rawurlencode()d.
		return appview_url(
			'profile/' . $parts['did'] . '/post/' . $parts['rkey'],
			array(
				'type' => 'post',
				'did'  => $parts['did'],
				'rkey' => $parts['rkey'],
			)
		);
	}

	/**
	 * Find a WordPress post by its Bluesky AT-URI.
	 *
	 * Checks the single-record meta key first (fast, unique per post,
	 * covers every non-thread post) and falls back to the thread-URI
	 * index that Publisher populates for every record — root and
	 * every reply — under the `teaser-thread` strategy. Without the
	 * fallback, a like/repost targeting a reply post would silently
	 * fail to resolve back to the originating WordPress post.
	 *
	 * Both lookups are scoped to `get_supported_post_types()` so the
	 * resolver covers exactly the types the Publisher federates — see
	 * {@see self::find_post_by_uri_meta()} for why the explicit scope
	 * matters. When no post types are supported there is nothing to
	 * resolve, so bail before running any query.
	 *
	 * @param string $uri AT-URI.
	 * @return int|false
	 */
	private static function find_post_by_bsky_uri( string $uri ): int|false {
		if ( empty( $uri ) ) {
			return false;
		}

		$post_types = get_supported_post_types();

		/*
		 * An empty supported-types list means nothing is federated, so
		 * nothing should resolve. Bail before get_posts(), whose empty
		 * `post_type` array silently falls back to WordPress's `post`
		 * default and would reintroduce the exact miss this scoping fixes.
		 */
		if ( empty( $post_types ) ) {
			return false;
		}

		$post_id = self::find_post_by_uri_meta( BskyPost::META_URI, $uri, $post_types );

		if ( false !== $post_id ) {
			return $post_id;
		}

		return self::find_post_by_uri_meta( BskyPost::META_URI_INDEX, $uri, $post_types );
	}

	/**
	 * Resolve a published, federated post from a URI-valued meta key.
	 *
	 * Scoped to the supported post types rather than left to
	 * `get_posts()`'s `post_type => 'post'` default. Without the explicit
	 * scope, reactions targeting a custom post type we publish (an
	 * `aside`/`status`-style CPT, a note type, etc.) resolve to nothing
	 * and are dropped silently — no comment row, no error. Passing the
	 * types explicitly also picks up supported CPTs registered with
	 * `exclude_from_search`, which the `'any'` shortcut would miss.
	 *
	 * @param string   $meta_key   Meta key to match ({@see BskyPost::META_URI} or META_URI_INDEX).
	 * @param string   $uri        AT-URI to match.
	 * @param string[] $post_types Supported post types to scope the query to.
	 * @return int|false
	 */
	private static function find_post_by_uri_meta( string $meta_key, string $uri, array $post_types ): int|false {
		$posts = \get_posts(
			array(
				'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $uri, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'post_type'      => $post_types,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'has_password'   => false,
				'fields'         => 'ids',
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : false;
	}

	/**
	 * Find a WordPress comment by its source_id meta (AT-URI).
	 *
	 * @param string   $uri      AT-URI.
	 * @param string[] $statuses Comment statuses the lookup may match.
	 *                           Defaults to the wide dedup set; parent
	 *                           resolution passes the narrower
	 *                           {@see self::PARENT_COMMENT_STATUSES}.
	 * @return int|false
	 */
	private static function find_comment_by_source_id( string $uri, array $statuses = self::DEDUP_COMMENT_STATUSES ): int|false {
		if ( empty( $uri ) ) {
			return false;
		}

		$comments = \get_comments(
			array(
				'meta_key'   => self::META_SOURCE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $uri, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ids',
				'status'     => $statuses,
			)
		);

		return ! empty( $comments ) ? (int) $comments[0] : false;
	}

	/**
	 * Find which of the given source AT-URIs already exist as comments.
	 *
	 * One batched lookup replaces a per-reply dedup query when importing a
	 * whole thread.
	 *
	 * @param array $uris Candidate source AT-URIs.
	 * @return array<string, true> Set of URIs that already have a comment.
	 */
	private static function find_existing_source_ids( array $uris ): array {
		$uris = \array_values(
			\array_unique(
				\array_filter( $uris, static fn( $uri ) => \is_string( $uri ) && '' !== $uri )
			)
		);

		if ( empty( $uris ) ) {
			return array();
		}

		$comments = \get_comments(
			array(
				'status'     => self::DEDUP_COMMENT_STATUSES,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_SOURCE_ID,
						'value'   => $uris,
						'compare' => 'IN',
					),
				),
			)
		);

		$existing = array();

		foreach ( $comments as $comment ) {
			$uri = (string) \get_comment_meta( (int) $comment->comment_ID, self::META_SOURCE_ID, true );

			if ( '' !== $uri ) {
				$existing[ $uri ] = true;
			}
		}

		return $existing;
	}
}
