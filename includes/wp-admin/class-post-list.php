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
 *
 * Both surfaces are per-post gated on `edit_post`. The posts list shows
 * every author their colleagues' rows, so a column without that check
 * would hand a contributor the PDS failure text stored on someone
 * else's post.
 *
 * @since unreleased
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
	 * A share worker was queued.
	 *
	 * @var string
	 */
	public const RESULT_QUEUED = 'queued';

	/**
	 * An attempt was already pending, so nothing new was queued.
	 *
	 * @var string
	 */
	public const RESULT_DUPLICATE = 'duplicate';

	/**
	 * The post may not be shared (capability, visibility, or the
	 * Bluesky companion being switched off for it).
	 *
	 * @var string
	 */
	public const RESULT_REFUSED = 'refused';

	/**
	 * Query arg carrying the result back to the list screen.
	 *
	 * @var string
	 */
	private const NOTICE_ARG = 'atmosphere_shared';

	/**
	 * Boot the list-table hooks.
	 *
	 * Registered on `admin_init` rather than `init`: every hook below is
	 * admin-only, and `get_supported_post_types()` must be read after
	 * post types have finished registering, otherwise a custom post type
	 * that opts in on the default `init` priority never gets a column.
	 *
	 * Gated on `is_auto_publish_enabled()`: this is cross-posting UI, so
	 * on a site that shares nothing it should be absent rather than
	 * present and inert. That also removes it in connection-only mode,
	 * where a host plugin owns the sharing surfaces.
	 *
	 * @since unreleased
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
	 * @since unreleased
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
	 * @since unreleased
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

		/*
		 * The list table shows other authors' rows to anyone who can
		 * edit posts, so the per-post check that guards the row action
		 * has to guard the cell too. The em dash is rendered either way
		 * so the column never becomes an oracle for "this post has
		 * Bluesky state".
		 */
		if ( \current_user_can( 'edit_post', $post_id ) ) {
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
	 * @since unreleased
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
	 * `is_auto_publish_enabled()` is re-checked here and not only at
	 * registration so the gate is enforced where the side effect is: a
	 * filter added later than the registration hook would otherwise be
	 * honoured by every other lane but not by this one.
	 *
	 * `is_bluesky_post_enabled()` is checked per post rather than once at
	 * registration because it is a pure filter with no site-wide option
	 * behind it: a site can publish documents only for some posts and
	 * keep the Bluesky companion for others.
	 *
	 * @since unreleased
	 *
	 * @param \WP_Post $post Post to test.
	 * @return bool
	 */
	public static function can_share( \WP_Post $post ): bool {
		return is_auto_publish_enabled()
			&& \current_user_can( 'edit_post', $post->ID )
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
	 * The `wp_next_scheduled()` check is load-bearing beyond the
	 * duplicate protection core does for identical events within ten
	 * minutes: a failed attempt is retried on a ladder that reaches
	 * fifteen minutes and beyond, and a second worker must not be queued
	 * alongside a retry that is still pending.
	 *
	 * @since unreleased
	 *
	 * @param \WP_Post $post Post to share.
	 * @return string One of the `RESULT_*` constants.
	 */
	public static function share_post( \WP_Post $post ): string {
		if ( ! self::can_share( $post ) ) {
			return self::RESULT_REFUSED;
		}

		$args = array( $post->ID );
		if ( \wp_next_scheduled( 'atmosphere_publish_post', $args ) ) {
			return self::RESULT_DUPLICATE;
		}

		return false === \wp_schedule_single_event( \time(), 'atmosphere_publish_post', $args )
			? self::RESULT_DUPLICATE
			: self::RESULT_QUEUED;
	}

	/**
	 * Handle the row-action request.
	 *
	 * @since unreleased
	 */
	public static function handle_share(): void {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$post    = \get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! \current_user_can( 'edit_post', $post_id ) ) {
			\wp_die(
				\esc_html__( 'Unauthorized.', 'atmosphere' ),
				\esc_html__( 'Unauthorized.', 'atmosphere' ),
				array( 'response' => 403 )
			);
		}

		\check_admin_referer( self::ACTION . '_' . $post_id, 'atmosphere_nonce' );

		\wp_safe_redirect(
			\add_query_arg(
				self::NOTICE_ARG,
				self::share_post( $post ),
				self::list_url( $post )
			)
		);
		exit;
	}

	/**
	 * Tell the user what happened after a share request.
	 *
	 * @since unreleased
	 */
	public static function maybe_render_share_notice(): void {
		$screen = \function_exists( '\get_current_screen' ) ? \get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice keyed off a redirect we issued; nothing from the request is rendered.
		$result = isset( $_GET[ self::NOTICE_ARG ] ) ? \sanitize_key( \wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';

		$messages = array(
			self::RESULT_QUEUED    => \__( 'Sharing to Bluesky in the background. Reload in a moment to see the result.', 'atmosphere' ),
			self::RESULT_DUPLICATE => \__( 'This post is already queued for sharing.', 'atmosphere' ),
			self::RESULT_REFUSED   => \__( 'This post cannot be shared to Bluesky right now.', 'atmosphere' ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		\printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			\esc_html( $messages[ $result ] )
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
