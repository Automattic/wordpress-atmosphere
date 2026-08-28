<?php
/**
 * Posts-list Bluesky column and manual share action.
 *
 * @package Atmosphere
 */

namespace Atmosphere\WP_Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Atmosphere;
use Atmosphere\Transformer\Post;
use function Atmosphere\get_supported_post_types;
use function Atmosphere\is_auto_publish_enabled;
use function Atmosphere\is_bluesky_post_enabled;
use function Atmosphere\is_post_publishable;

/**
 * Adds a Bluesky column and a share action to the posts list tables.
 *
 * Everything here reads meta the publish path already writes. The
 * column exists so an author can see, without opening each post, which
 * posts made it to Bluesky and which failed. The share action re-queues
 * `atmosphere_publish_post`, which already decides between a first
 * publish and an update, so a post that was published while the site
 * was disconnected can be pushed after reconnecting. That was
 * previously only possible over WP-CLI, which sites on managed hosting
 * do not have.
 */
class Post_List {

	/**
	 * Column key in the list table.
	 *
	 * @var string
	 */
	public const COLUMN = 'atmosphere_bluesky';

	/**
	 * Row action key, and the `admin_post_` suffix backing it.
	 *
	 * @var string
	 */
	public const ACTION = 'atmosphere_share_now';

	/**
	 * Query arg carrying the result back to the list screen.
	 *
	 * @var string
	 */
	private const NOTICE_ARG = 'atmosphere_shared';

	/**
	 * Boot the list-table hooks.
	 *
	 * Gated on `is_auto_publish_enabled()`: this is cross-posting UI, so
	 * on a site that shares nothing it should be absent rather than
	 * present and inert. That also removes it in connection-only mode,
	 * where a host plugin owns the sharing surfaces.
	 */
	public static function register(): void {
		if ( ! is_auto_publish_enabled() ) {
			return;
		}

		\add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_share' ) );

		foreach ( get_supported_post_types() as $post_type ) {
			\add_filter( "manage_{$post_type}_posts_columns", array( self::class, 'add_column' ) );
			\add_action( "manage_{$post_type}_posts_custom_column", array( self::class, 'render_column' ), 10, 2 );
		}

		\add_filter( 'post_row_actions', array( self::class, 'add_row_action' ), 10, 2 );
		\add_filter( 'page_row_actions', array( self::class, 'add_row_action' ), 10, 2 );
		\add_action( 'admin_notices', array( self::class, 'maybe_render_share_notice' ) );
	}

	/**
	 * Append the Bluesky column.
	 *
	 * Registering it as a real column means Screen Options can hide it
	 * without any extra setting of ours.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_column( array $columns ): array {
		$columns[ self::COLUMN ] = \__( 'Bluesky', 'atmosphere' );

		return $columns;
	}

	/**
	 * Render one cell.
	 *
	 * @param string $column  Column key being rendered.
	 * @param int    $post_id Post ID for the row.
	 */
	public static function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$url = self::shared_url( $post );
		if ( '' !== $url ) {
			\printf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				\esc_url( $url ),
				\esc_html__( 'View post', 'atmosphere' )
			);

			return;
		}

		$error = Atmosphere::get_publish_error( $post_id );
		if ( null !== $error && '' !== $error['message'] ) {
			\printf(
				'<span class="atmosphere-share-failed">%s</span>',
				\esc_html( $error['message'] )
			);

			return;
		}

		/*
		 * Nothing to say. A site can carry years of posts from before
		 * the plugin was installed, and a column of "not shared" on
		 * every one of them is noise, not information.
		 */
		echo '<span aria-hidden="true">&mdash;</span>';
	}

	/**
	 * Offer "Share to Bluesky" on rows the user can act on.
	 *
	 * The label names the destination on purpose: several plugins add
	 * sharing actions to this list, and a bare "Share now" would not say
	 * where the post is going.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    Post for the row.
	 * @return array
	 */
	public static function add_row_action( array $actions, \WP_Post $post ): array {
		if ( ! self::can_share( $post ) ) {
			return $actions;
		}

		$actions[ self::ACTION ] = \sprintf(
			'<a href="%1$s">%2$s</a>',
			\esc_url( self::share_url( $post ) ),
			\esc_html__( 'Share to Bluesky', 'atmosphere' )
		);

		return $actions;
	}

	/**
	 * Whether the current user can share this post to Bluesky right now.
	 *
	 * `is_bluesky_post_enabled()` is checked per post rather than once at
	 * registration because it is a pure filter with no site-wide option
	 * behind it: a site can publish documents only for some posts and
	 * keep the Bluesky companion for others.
	 *
	 * @param \WP_Post $post Post to test.
	 * @return bool
	 */
	public static function can_share( \WP_Post $post ): bool {
		return \current_user_can( 'edit_post', $post->ID )
			&& is_post_publishable( $post )
			&& is_bluesky_post_enabled( $post );
	}

	/**
	 * Queue a share for one post.
	 *
	 * Re-uses `atmosphere_publish_post` rather than calling the Publisher
	 * directly: that worker already re-checks visibility, picks between a
	 * first publish and an update, logs failures, and schedules retries.
	 *
	 * @param \WP_Post $post Post to share.
	 * @return bool True when a worker was queued, false when refused or already queued.
	 */
	public static function share_post( \WP_Post $post ): bool {
		if ( ! self::can_share( $post ) ) {
			return false;
		}

		$args = array( $post->ID );
		if ( \wp_next_scheduled( 'atmosphere_publish_post', $args ) ) {
			return false;
		}

		return false !== \wp_schedule_single_event( \time(), 'atmosphere_publish_post', $args );
	}

	/**
	 * Handle the row-action request.
	 */
	public static function handle_share(): void {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$post    = \get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! \current_user_can( 'edit_post', $post_id ) ) {
			\wp_die( \esc_html__( 'Unauthorized.', 'atmosphere' ) );
		}

		\check_admin_referer( self::ACTION . '_' . $post_id, 'atmosphere_nonce' );

		$queued = self::share_post( $post );

		\wp_safe_redirect(
			\add_query_arg(
				self::NOTICE_ARG,
				$queued ? 'queued' : 'skipped',
				self::list_url( $post )
			)
		);
		exit;
	}

	/**
	 * Tell the user what happened after a share request.
	 */
	public static function maybe_render_share_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice keyed off a redirect we issued.
		$result = isset( $_GET[ self::NOTICE_ARG ] ) ? \sanitize_key( \wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';

		if ( 'queued' !== $result && 'skipped' !== $result ) {
			return;
		}

		$message = 'queued' === $result
			? \__( 'Sharing to Bluesky in the background. Reload in a moment to see the result.', 'atmosphere' )
			: \__( 'This post is already queued for sharing.', 'atmosphere' );

		\printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			\esc_html( $message )
		);
	}

	/**
	 * The appview URL for a post's Bluesky record, or '' when unshared.
	 *
	 * @param \WP_Post $post Post to look up.
	 * @return string
	 */
	private static function shared_url( \WP_Post $post ): string {
		$uri = (string) \get_post_meta( $post->ID, Post::META_URI, true );

		return '' === $uri ? '' : Atmosphere::bsky_web_url_from_uri( $uri );
	}

	/**
	 * Build the nonced share URL for a post.
	 *
	 * @param \WP_Post $post Post to share.
	 * @return string
	 */
	private static function share_url( \WP_Post $post ): string {
		return \wp_nonce_url(
			\add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post->ID,
				),
				\admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $post->ID,
			'atmosphere_nonce'
		);
	}

	/**
	 * The list screen a post belongs to, for the post-share redirect.
	 *
	 * @param \WP_Post $post Post that was shared.
	 * @return string
	 */
	private static function list_url( \WP_Post $post ): string {
		return \add_query_arg(
			'post_type',
			$post->post_type,
			\admin_url( 'edit.php' )
		);
	}
}
