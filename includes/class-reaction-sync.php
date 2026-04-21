<?php
/**
 * Polls app.bsky.notification.listNotifications and inserts
 * replies, likes, and reposts as WordPress comments with
 * AT Protocol metadata stored in comment meta.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

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
	 * Atproto-specific.
	 *
	 * @var string
	 */
	public const META_BSKY_CID = '_atmosphere_bsky_cid';

	/**
	 * Comment meta key for the reaction author's DID.
	 *
	 * Atproto-specific.
	 *
	 * @var string
	 */
	public const META_AUTHOR_DID = '_atmosphere_author_did';

	/**
	 * Comment meta key for the reaction author's avatar URL.
	 *
	 * Atproto-specific. Populated at insert time from the Bluesky
	 * profile so get_avatar() does not fall through to gravatar.
	 *
	 * @var string
	 */
	public const META_AUTHOR_AVATAR = '_atmosphere_author_avatar';

	/**
	 * Register display-side hooks.
	 */
	public static function register(): void {
		\add_filter( 'get_avatar_comment_types', array( self::class, 'avatar_comment_types' ) );
		\add_filter( 'pre_get_avatar_data', array( self::class, 'filter_avatar_data' ), 10, 2 );
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
	 * Maximum pages to process per cron run.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 5;

	/**
	 * Run the sync. Called by WP-Cron.
	 *
	 * Pagination is per-run only. Each run starts from the newest
	 * notification and walks backwards up to MAX_PAGES. Duplicates are
	 * handled at insert time via find_comment_by_source_id.
	 */
	public static function sync(): void {
		if ( ! is_connected() ) {
			return;
		}

		$cursor = null;
		$pages  = 0;

		do {
			$response = self::fetch_notifications( $cursor );

			if ( \is_wp_error( $response ) ) {
				return;
			}

			$notifications = $response['notifications'] ?? array();

			foreach ( $notifications as $notification ) {
				self::process_notification( $notification );
			}

			$cursor = $response['cursor'] ?? null;
			++$pages;
		} while ( $cursor && ! empty( $notifications ) && $pages < self::MAX_PAGES );
	}

	/**
	 * Dispatch a single notification to its reason-specific handler.
	 *
	 * Unknown reasons are silently skipped.
	 *
	 * @param array $notification Notification from listNotifications.
	 * @return int|false Comment ID if inserted, false if skipped.
	 */
	private static function process_notification( array $notification ): int|false {
		switch ( $notification['reason'] ?? '' ) {
			case 'reply':
				return self::process_reply( $notification );
			case 'like':
				return self::process_like( $notification );
			case 'repost':
				return self::process_repost( $notification );
			default:
				return false;
		}
	}

	/**
	 * Fetch a page of notifications from the PDS.
	 *
	 * No server-side reason filter — the XRPC array-query encoding
	 * produced by http_build_query is incompatible with Bluesky's
	 * repeated-key convention. Client-side dispatch in
	 * process_notification skips non-reaction reasons cheaply.
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
	 * Insert a reaction notification as a WordPress comment and persist its meta.
	 *
	 * Caller is responsible for target-post resolution, dedup, and comment_type.
	 * This method handles the wp_insert_comment call and writes the standard
	 * reaction meta (protocol, source_id, source_url, CID, author DID).
	 *
	 * @param int    $post_id        WP post the reaction attaches to.
	 * @param string $comment_type   One of 'comment', 'like', 'repost'.
	 * @param string $content        Comment body (reply text, or '' for like/repost).
	 * @param int    $comment_parent Parent comment ID, 0 for top-level.
	 * @param array  $notification   Raw notification from listNotifications.
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
		$uri    = $notification['uri'] ?? '';
		$cid    = $notification['cid'] ?? '';
		$author = $notification['author'] ?? array();
		$record = $notification['record'] ?? array();

		$author_handle = $profile['handle'] ?? ( $author['handle'] ?? '' );
		$author_name   = $profile['name'] ?? $author_handle;

		$gm_date = \get_gmt_from_date( $record['createdAt'] ?? '' );

		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_parent'       => $comment_parent,
			'comment_author'       => $author_name,
			'comment_author_url'   => \esc_url_raw( 'https://bsky.app/profile/' . $author_handle ),
			'comment_author_email' => '',
			'comment_content'      => \wp_kses_post( $content ),
			'comment_date'         => \get_date_from_gmt( $gm_date ),
			'comment_date_gmt'     => $gm_date,
			'comment_type'         => $comment_type,
			'comment_approved'     => 1,
			'comment_agent'        => 'ATmosphere/' . ATMOSPHERE_VERSION,
		);

		\remove_action( 'check_comment_flood', 'check_comment_flood_db' );
		$comment_id = \wp_insert_comment( $comment_data );
		\add_action( 'check_comment_flood', 'check_comment_flood_db', 10, 4 );

		if ( ! $comment_id ) {
			return false;
		}

		\update_comment_meta( $comment_id, self::META_PROTOCOL, 'atproto' );
		\update_comment_meta( $comment_id, self::META_SOURCE_ID, $uri );
		\update_comment_meta(
			$comment_id,
			self::META_SOURCE_URL,
			self::build_bsky_web_url( $uri, $author_handle )
		);
		\update_comment_meta( $comment_id, self::META_BSKY_CID, $cid );
		\update_comment_meta( $comment_id, self::META_AUTHOR_DID, $author['did'] ?? '' );
		\update_comment_meta( $comment_id, self::META_AUTHOR_AVATAR, $profile['avatar'] ?? '' );

		/**
		 * Fires after a Bluesky reaction is synced as a WordPress comment.
		 *
		 * @param int    $comment_id   The new comment ID.
		 * @param array  $notification The raw notification data.
		 * @param int    $post_id      The WordPress post ID.
		 * @param string $comment_type One of 'comment', 'like', 'repost'.
		 */
		\do_action( 'atmosphere_reaction_synced', $comment_id, $notification, $post_id, $comment_type );

		return $comment_id;
	}

	/**
	 * Shared handler for reactions whose target is in record.subject.uri.
	 *
	 * Used by likes and reposts — both point at a single subject post
	 * and are stored at top level with empty content.
	 *
	 * @param array  $notification Notification from listNotifications.
	 * @param string $comment_type 'like' or 'repost'.
	 * @return int|false Comment ID or false.
	 */
	private static function process_subject_reaction( array $notification, string $comment_type ): int|false {
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

		if ( empty( $subject_uri ) ) {
			return false;
		}

		$post_id = self::find_post_by_bsky_uri( $subject_uri );

		if ( ! $post_id ) {
			return false;
		}

		$profile = self::resolve_author( $author['did'] ?? '' );

		return self::insert_reaction(
			$post_id,
			$comment_type,
			'',
			0,
			$notification,
			$profile
		);
	}

	/**
	 * Process a like notification into a WordPress like comment.
	 *
	 * @param array $notification Notification from listNotifications.
	 * @return int|false Comment ID or false.
	 */
	private static function process_like( array $notification ): int|false {
		return self::process_subject_reaction( $notification, 'like' );
	}

	/**
	 * Process a repost notification into a WordPress repost comment.
	 *
	 * @param array $notification Notification from listNotifications.
	 * @return int|false Comment ID or false.
	 */
	private static function process_repost( array $notification ): int|false {
		return self::process_subject_reaction( $notification, 'repost' );
	}

	/**
	 * Process a reply notification into a WordPress comment.
	 *
	 * @param array $notification Notification from listNotifications.
	 * @return int|false Comment ID or false.
	 */
	private static function process_reply( array $notification ): int|false {
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
		$root_uri   = $record['reply']['root']['uri'] ?? '';

		if ( empty( $parent_uri ) ) {
			return false;
		}

		$post_id        = 0;
		$comment_parent = 0;

		// Direct reply to one of our posts.
		$post_id = self::find_post_by_bsky_uri( $parent_uri );

		if ( ! $post_id ) {
			// Nested reply: parent is an existing comment.
			$parent_comment_id = self::find_comment_by_source_id( $parent_uri );

			if ( $parent_comment_id ) {
				$parent_comment = \get_comment( $parent_comment_id );
				$post_id        = (int) $parent_comment->comment_post_ID;
				$comment_parent = $parent_comment_id;
			}
		}

		if ( ! $post_id ) {
			// Fallback: reply somewhere in a thread rooted at our post.
			$post_id = self::find_post_by_bsky_uri( $root_uri );
		}

		if ( ! $post_id ) {
			return false;
		}

		$text = $record['text'] ?? '';

		if ( '' === $text ) {
			return false;
		}

		$profile = self::resolve_author( $author['did'] ?? '' );

		return self::insert_reaction(
			$post_id,
			'comment',
			$text,
			$comment_parent,
			$notification,
			$profile
		);
	}

	/**
	 * Resolve a Bluesky actor profile.
	 *
	 * @param string $did Author DID.
	 * @return array{name: string, handle: string, avatar: string}
	 */
	private static function resolve_author( string $did ): array {
		if ( empty( $did ) ) {
			return array();
		}

		// Check transient cache.
		$cache_key = 'atmosphere_profile_' . \md5( $did );
		$cached    = \get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = API::get(
			'/xrpc/app.bsky.actor.getProfile',
			array( 'actor' => $did )
		);

		if ( \is_wp_error( $result ) ) {
			return array();
		}

		$profile = array(
			'name'   => $result['displayName'] ?? ( $result['handle'] ?? '' ),
			'handle' => $result['handle'] ?? '',
			'avatar' => $result['avatar'] ?? '',
		);

		\set_transient( $cache_key, $profile, HOUR_IN_SECONDS );

		return $profile;
	}

	/**
	 * Build the https://bsky.app/... web URL for a given AT-URI and handle.
	 *
	 * @param string $at_uri AT-URI (at://did:plc:.../app.bsky.feed.post/<rkey>).
	 * @param string $handle Bluesky handle (e.g. replier.bsky.social).
	 * @return string Web URL, or empty string if URI cannot be parsed.
	 */
	private static function build_bsky_web_url( string $at_uri, string $handle ): string {
		if ( empty( $at_uri ) || empty( $handle ) ) {
			return '';
		}

		$parts = \explode( '/', $at_uri );
		$rkey  = \end( $parts );

		if ( empty( $rkey ) ) {
			return '';
		}

		// Only app.bsky.feed.post records have a bsky.app web URL.
		$collection = \prev( $parts );

		if ( 'app.bsky.feed.post' !== $collection ) {
			return '';
		}

		return \esc_url_raw( 'https://bsky.app/profile/' . $handle . '/post/' . $rkey );
	}

	/**
	 * Find a WordPress post by its Bluesky AT-URI.
	 *
	 * @param string $uri AT-URI.
	 * @return int|false Post ID or false.
	 */
	private static function find_post_by_bsky_uri( string $uri ): int|false {
		if ( empty( $uri ) ) {
			return false;
		}

		$posts = \get_posts(
			array(
				'meta_key'       => BskyPost::META_URI, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $uri, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : false;
	}

	/**
	 * Find a WordPress comment by its source_id meta (AT-URI).
	 *
	 * @param string $uri AT-URI.
	 * @return int|false Comment ID or false.
	 */
	private static function find_comment_by_source_id( string $uri ): int|false {
		if ( empty( $uri ) ) {
			return false;
		}

		$comments = \get_comments(
			array(
				'meta_key'   => self::META_SOURCE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $uri, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ids',
			)
		);

		return ! empty( $comments ) ? (int) $comments[0] : false;
	}
}
