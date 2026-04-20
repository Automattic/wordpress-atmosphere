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
	 * Option key for the notification cursor.
	 *
	 * @var string
	 */
	public const OPTION_CURSOR = 'atmosphere_reactions_cursor';

	/**
	 * Comment meta key for the protocol identifier.
	 *
	 * @var string
	 */
	public const META_PROTOCOL = 'protocol';

	/**
	 * Comment meta key for the Bluesky AT-URI.
	 *
	 * @var string
	 */
	public const META_BSKY_URI = '_atmosphere_bsky_uri';

	/**
	 * Comment meta key for the Bluesky CID.
	 *
	 * @var string
	 */
	public const META_BSKY_CID = '_atmosphere_bsky_cid';

	/**
	 * Comment meta key for the author DID.
	 *
	 * @var string
	 */
	public const META_AUTHOR_DID = '_atmosphere_author_did';

	/**
	 * Maximum pages to process per cron run.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 5;

	/**
	 * Run the sync. Called by WP-Cron.
	 */
	public static function sync(): void {
		if ( ! is_connected() ) {
			return;
		}

		$cursor = \get_option( self::OPTION_CURSOR, '' );
		$pages  = 0;

		do {
			$response = self::fetch_notifications( $cursor ?: null );

			if ( \is_wp_error( $response ) ) {
				return;
			}

			$notifications = $response['notifications'] ?? array();

			foreach ( $notifications as $notification ) {
				if ( 'reply' !== ( $notification['reason'] ?? '' ) ) {
					continue;
				}

				self::process_reply( $notification );
			}

			$cursor = $response['cursor'] ?? null;

			if ( $cursor ) {
				\update_option( self::OPTION_CURSOR, $cursor, false );
			}

			++$pages;
		} while ( $cursor && ! empty( $notifications ) && $pages < self::MAX_PAGES );
	}

	/**
	 * Fetch a page of reply notifications from the PDS.
	 *
	 * @param string|null $cursor Pagination cursor.
	 * @return array|\WP_Error
	 */
	private static function fetch_notifications( ?string $cursor = null ): array|\WP_Error {
		$params = array(
			'reasons' => 'reply',
			'limit'   => 50,
		);

		if ( null !== $cursor ) {
			$params['cursor'] = $cursor;
		}

		return API::get( '/xrpc/app.bsky.notification.listNotifications', $params );
	}

	/**
	 * Process a single reply notification into a WordPress comment.
	 *
	 * @param array $notification Notification from listNotifications.
	 * @return int|false Comment ID or false if skipped.
	 */
	private static function process_reply( array $notification ): int|false {
		$reply_uri = $notification['uri'] ?? '';
		$reply_cid = $notification['cid'] ?? '';
		$record    = $notification['record'] ?? array();
		$author    = $notification['author'] ?? array();

		if ( empty( $reply_uri ) || empty( $record ) ) {
			return false;
		}

		// Dedup: skip if already imported.
		if ( self::find_comment_by_bsky_uri( $reply_uri ) ) {
			return false;
		}

		$parent_uri = $record['reply']['parent']['uri'] ?? '';
		$root_uri   = $record['reply']['root']['uri'] ?? '';

		if ( empty( $parent_uri ) ) {
			return false;
		}

		// Try to match the parent to a local post or comment.
		$post_id        = 0;
		$comment_parent = 0;

		// Direct reply to one of our posts.
		$post_id = self::find_post_by_bsky_uri( $parent_uri );

		if ( ! $post_id ) {
			// Nested reply: parent is an existing comment.
			$parent_comment_id = self::find_comment_by_bsky_uri( $parent_uri );

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

		// Resolve author profile.
		$profile = self::resolve_author( $author['did'] ?? '' );

		$comment_text = $record['text'] ?? '';

		if ( empty( $comment_text ) ) {
			return false;
		}

		$author_handle = $profile['handle'] ?? ( $author['handle'] ?? '' );
		$author_name   = $profile['name'] ?? $author_handle;

		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_parent'       => $comment_parent,
			'comment_author'       => $author_name,
			'comment_author_url'   => 'https://bsky.app/profile/' . $author_handle,
			'comment_author_email' => '',
			'comment_content'      => \wp_kses_post( $comment_text ),
			'comment_date_gmt'     => \get_gmt_from_date( $record['createdAt'] ?? '' ),
			'comment_type'         => 'comment',
			'comment_approved'     => 1,
			'comment_agent'        => 'ATmosphere/' . ATMOSPHERE_VERSION,
		);

		// Disable flood control for programmatic insertion.
		\remove_action( 'check_comment_flood', 'check_comment_flood_db' );

		$comment_id = \wp_insert_comment( $comment_data );

		\add_action( 'check_comment_flood', 'check_comment_flood_db', 10, 4 );

		if ( ! $comment_id ) {
			return false;
		}

		// Store AT Protocol metadata.
		\update_comment_meta( $comment_id, self::META_PROTOCOL, 'atproto' );
		\update_comment_meta( $comment_id, self::META_BSKY_URI, $reply_uri );
		\update_comment_meta( $comment_id, self::META_BSKY_CID, $reply_cid );
		\update_comment_meta( $comment_id, self::META_AUTHOR_DID, $author['did'] ?? '' );

		/**
		 * Fires after a Bluesky reply is synced as a WordPress comment.
		 *
		 * @param int   $comment_id   The new comment ID.
		 * @param array $notification The raw notification data.
		 * @param int   $post_id      The WordPress post ID.
		 */
		\do_action( 'atmosphere_reaction_synced', $comment_id, $notification, $post_id );

		return $comment_id;
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
	 * Find a WordPress comment by its Bluesky AT-URI.
	 *
	 * @param string $uri AT-URI.
	 * @return int|false Comment ID or false.
	 */
	private static function find_comment_by_bsky_uri( string $uri ): int|false {
		if ( empty( $uri ) ) {
			return false;
		}

		$comments = \get_comments(
			array(
				'meta_key'   => self::META_BSKY_URI, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $uri, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ids',
			)
		);

		return ! empty( $comments ) ? (int) $comments[0] : false;
	}
}
