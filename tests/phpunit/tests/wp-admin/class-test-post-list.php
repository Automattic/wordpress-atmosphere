<?php
/**
 * Tests for the posts-list Bluesky column and its manual share action.
 *
 * The column reads meta the publish path already writes, so the tests
 * seed that meta directly rather than running a publish. Three states
 * matter: a shared post links to the appview, a failed post surfaces the
 * stored reason, and everything else stays an em dash so a site with
 * years of pre-plugin history is not filled with "not shared" noise.
 *
 * Authorization is the part worth pinning hardest. The posts list shows
 * every author their colleagues' rows, so both the column and the row
 * action gate on `edit_post` for that specific post, not on a site-wide
 * capability. The multi-author tests below exist to catch a regression
 * from the per-post check to a blanket one, which a role-level fixture
 * (administrator versus subscriber) cannot see.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\Atmosphere;
use Atmosphere\Transformer\Post;
use Atmosphere\WP_Admin\Post_List;

/**
 * Posts-list column and share-action tests.
 */
class Test_Post_List extends \WP_UnitTestCase {

	/**
	 * Reset user and filter state between tests.
	 */
	public function tear_down(): void {
		\wp_set_current_user( 0 );
		\remove_all_filters( 'atmosphere_should_publish_bluesky_post' );
		\remove_all_filters( 'atmosphere_should_auto_publish' );
		\remove_all_filters( 'atmosphere_appview_host' );
		\remove_post_type_support( 'page', 'atmosphere' );
		\delete_option( 'atmosphere_auto_publish' );

		parent::tear_down();
	}

	/**
	 * Create a published post, optionally owned by someone else.
	 *
	 * @param array $args Optional post args.
	 * @return \WP_Post
	 */
	private function published_post( array $args = array() ): \WP_Post {
		return self::factory()->post->create_and_get(
			\array_merge( array( 'post_status' => 'publish' ), $args )
		);
	}

	/**
	 * Become an administrator, who can edit any post.
	 */
	private function become_admin(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Capture the output of one column cell.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $column  Column key to render, defaults to ours.
	 * @return string
	 */
	private function render( int $post_id, string $column = Post_List::COLUMN ): string {
		\ob_start();
		Post_List::render_column( $column, $post_id );

		return (string) \ob_get_clean();
	}

	/**
	 * Seed a stored publish failure.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Failure message.
	 * @param string $code    Failure code.
	 */
	private function store_error( int $post_id, string $message, string $code = 'http_500' ): void {
		\update_post_meta(
			$post_id,
			'_atmosphere_last_publish_error',
			array(
				'code'    => $code,
				'message' => $message,
				'time'    => 1000,
			)
		);
	}

	/**
	 * On a site that shares nothing, the surfaces are absent rather than
	 * present and inert, and the share handler is not registered at all
	 * so there is no craftable path to share.
	 */
	public function test_register_skips_surfaces_when_auto_publish_is_off() {
		\remove_all_filters( 'post_row_actions' );
		\remove_all_filters( 'page_row_actions' );
		\remove_all_filters( 'manage_post_posts_columns' );
		\remove_all_actions( 'admin_post_' . Post_List::ACTION );
		\add_filter( 'atmosphere_should_auto_publish', '__return_false' );

		Post_List::register();

		$this->assertFalse( \has_filter( 'post_row_actions', array( Post_List::class, 'add_row_action' ) ) );
		$this->assertFalse( \has_filter( 'page_row_actions', array( Post_List::class, 'add_row_action' ) ) );
		$this->assertFalse( \has_filter( 'manage_post_posts_columns', array( Post_List::class, 'add_column' ) ) );
		$this->assertFalse( \has_action( 'admin_post_' . Post_List::ACTION, array( Post_List::class, 'handle_share' ) ) );
	}

	/**
	 * With sharing on, every surface is wired: both row-action filters
	 * (the Pages screen uses `page_row_actions` exclusively), the column
	 * registration itself, and the handler.
	 */
	public function test_register_wires_every_surface_when_auto_publish_is_on() {
		\remove_all_filters( 'post_row_actions' );
		\remove_all_filters( 'page_row_actions' );
		\remove_all_filters( 'manage_post_posts_columns' );
		\remove_all_actions( 'manage_post_posts_custom_column' );
		\remove_all_actions( 'admin_post_' . Post_List::ACTION );

		Post_List::register();

		$this->assertNotFalse( \has_filter( 'post_row_actions', array( Post_List::class, 'add_row_action' ) ) );
		$this->assertNotFalse( \has_filter( 'page_row_actions', array( Post_List::class, 'add_row_action' ) ), 'Pages use page_row_actions exclusively.' );
		$this->assertNotFalse( \has_filter( 'manage_post_posts_columns', array( Post_List::class, 'add_column' ) ), 'The column must actually be registered.' );
		$this->assertNotFalse( \has_action( 'manage_post_posts_custom_column', array( Post_List::class, 'render_column' ) ) );
		$this->assertNotFalse( \has_action( 'admin_post_' . Post_List::ACTION, array( Post_List::class, 'handle_share' ) ) );
	}

	/**
	 * The stored option, not just the filter, switches the surface off.
	 */
	public function test_register_respects_the_stored_auto_publish_option() {
		\remove_all_filters( 'post_row_actions' );
		\update_option( 'atmosphere_auto_publish', '0' );

		Post_List::register();

		$this->assertFalse( \has_filter( 'post_row_actions', array( Post_List::class, 'add_row_action' ) ) );
	}

	/**
	 * The column is appended to the supported post type's column set.
	 */
	public function test_add_column_appends_bluesky_column() {
		$columns = Post_List::add_column( array( 'title' => 'Title' ) );

		$this->assertArrayHasKey( Post_List::COLUMN, $columns );
		$this->assertArrayHasKey( 'title', $columns, 'Existing columns survive.' );
	}

	/**
	 * Rendering only claims our own column, never someone else's cell.
	 */
	public function test_render_column_ignores_other_columns() {
		$this->become_admin();
		$post = $this->published_post();
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:abc123/app.bsky.feed.post/rkey789' );

		$this->assertSame( '', $this->render( $post->ID, 'title' ) );
	}

	/**
	 * A shared post links to the record on the appview.
	 */
	public function test_render_column_links_shared_post() {
		$this->become_admin();
		$post = $this->published_post();
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:abc123/app.bsky.feed.post/rkey789' );

		$output = $this->render( $post->ID );

		$this->assertStringContainsString( 'bsky.app/profile/did:plc:abc123/post/rkey789', $output );
		$this->assertStringContainsString( '<a ', $output );
	}

	/**
	 * The appview host filter retargets the column link too, so a site
	 * pointed at another appview does not get a bsky.app link here.
	 */
	public function test_render_column_honours_appview_host_filter() {
		$this->become_admin();
		$post = $this->published_post();
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:abc123/app.bsky.feed.post/rkey789' );

		\add_filter( 'atmosphere_appview_host', static fn () => 'deer.social' );

		$output = $this->render( $post->ID );

		$this->assertStringContainsString( 'deer.social/profile/did:plc:abc123/post/rkey789', $output );
		$this->assertStringNotContainsString( 'bsky.app', $output );
	}

	/**
	 * A URI that is not one of our Bluesky post records yields no link,
	 * rather than a half-built one.
	 */
	public function test_render_column_ignores_malformed_uri() {
		$this->become_admin();
		$post = $this->published_post();
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:abc123/site.standard.document/rkey789' );

		$output = $this->render( $post->ID );

		$this->assertStringNotContainsString( '<a ', $output );
		$this->assertStringContainsString( '&mdash;', $output );
	}

	/**
	 * A post that was never shared stays quiet.
	 */
	public function test_render_column_is_blank_for_never_shared_post() {
		$this->become_admin();

		$output = $this->render( $this->published_post()->ID );

		$this->assertStringNotContainsString( '<a ', $output );
		$this->assertStringContainsString( '&mdash;', $output );
	}

	/**
	 * A failed share surfaces the stored reason instead of an em dash.
	 */
	public function test_render_column_shows_failure_reason() {
		$this->become_admin();
		$post = $this->published_post();
		$this->store_error( $post->ID, 'The PDS said no.' );

		$output = $this->render( $post->ID );

		$this->assertStringContainsString( 'The PDS said no.', $output );
		$this->assertStringNotContainsString( '<a ', $output );
	}

	/**
	 * The failure text comes from the PDS, so it must be escaped before
	 * it reaches an admin screen.
	 */
	public function test_render_column_escapes_failure_reason() {
		$this->become_admin();
		$post = $this->published_post();
		$this->store_error( $post->ID, '<script>alert(1)</script>' );

		$output = $this->render( $post->ID );

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	/**
	 * An error with no message to show falls through to the em dash
	 * rather than rendering an empty box. `get_publish_error()` blanks
	 * the message of a reconnect-class failure once the site is
	 * connected again, so this is the path a recovered site takes.
	 */
	public function test_render_column_is_blank_when_error_has_no_message() {
		$this->become_admin();
		$post = $this->published_post();
		$this->store_error( $post->ID, '' );

		$output = $this->render( $post->ID );

		$this->assertStringNotContainsString( '<span class="atmosphere-share-failed">', $output );
		$this->assertStringContainsString( '&mdash;', $output );
	}

	/**
	 * A shared record wins over a stale stored failure.
	 */
	public function test_render_column_prefers_the_link_over_a_stale_error() {
		$this->become_admin();
		$post = $this->published_post();
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:abc123/app.bsky.feed.post/rkey789' );
		$this->store_error( $post->ID, 'The PDS said no.' );

		$output = $this->render( $post->ID );

		$this->assertStringContainsString( '<a ', $output );
		$this->assertStringNotContainsString( 'The PDS said no.', $output );
	}

	/**
	 * An author must not read the failure text stored on a colleague's
	 * post. The list table shows them the row either way, so the cell
	 * has to gate on `edit_post` for that specific post.
	 */
	public function test_render_column_hides_state_from_other_authors() {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$post  = $this->published_post( array( 'post_author' => $owner ) );
		$this->store_error( $post->ID, 'The PDS said no.' );

		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$output = $this->render( $post->ID );

		$this->assertStringNotContainsString( 'The PDS said no.', $output );
		$this->assertStringContainsString( '&mdash;', $output, 'The cell still renders, so it is not an oracle.' );
	}

	/**
	 * The row action is offered for a post the user can edit.
	 */
	public function test_row_action_offered_to_editor() {
		$this->become_admin();
		$post = $this->published_post();

		$actions = Post_List::add_row_action( array(), $post );

		$this->assertArrayHasKey( Post_List::ACTION, $actions );
		$this->assertStringContainsString( 'Share to Bluesky', $actions[ Post_List::ACTION ] );
	}

	/**
	 * The generated link carries a nonce bound to that post.
	 */
	public function test_row_action_link_is_nonced_per_post() {
		$this->become_admin();
		$post = $this->published_post();

		$actions = Post_List::add_row_action( array(), $post );
		$href    = \html_entity_decode( $actions[ Post_List::ACTION ] );

		\preg_match( '/atmosphere_nonce=([^"&]+)/', $href, $matches );

		$this->assertNotEmpty( $matches, 'The share link must carry a nonce.' );
		$this->assertNotFalse(
			\wp_verify_nonce( $matches[1], Post_List::ACTION . '_' . $post->ID ),
			'The nonce must validate against this post.'
		);
	}

	/**
	 * An author must not be offered the action on someone else's post.
	 * This is the check a site-wide capability test cannot see.
	 */
	public function test_row_action_hidden_on_another_authors_post() {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$post  = $this->published_post( array( 'post_author' => $owner ) );

		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertArrayNotHasKey( Post_List::ACTION, Post_List::add_row_action( array(), $post ) );
	}

	/**
	 * A user without edit rights on the post never sees the action.
	 */
	public function test_row_action_hidden_without_edit_capability() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post = $this->published_post();

		$this->assertArrayNotHasKey( Post_List::ACTION, Post_List::add_row_action( array(), $post ) );
	}

	/**
	 * A document-only post has no Bluesky companion, so there is
	 * nothing to share to Bluesky and no action is offered.
	 */
	public function test_row_action_hidden_when_bluesky_companion_is_off() {
		$this->become_admin();
		$post = $this->published_post();

		\add_filter( 'atmosphere_should_publish_bluesky_post', '__return_false' );

		$this->assertArrayNotHasKey( Post_List::ACTION, Post_List::add_row_action( array(), $post ) );
	}

	/**
	 * A draft cannot be shared, so it gets no action.
	 */
	public function test_row_action_hidden_for_unpublished_post() {
		$this->become_admin();
		$post = $this->published_post( array( 'post_status' => 'draft' ) );

		$this->assertArrayNotHasKey( Post_List::ACTION, Post_List::add_row_action( array(), $post ) );
	}

	/**
	 * Sharing queues the existing publish worker, which already decides
	 * between a first publish and an update.
	 */
	public function test_share_post_schedules_the_publish_worker() {
		$this->become_admin();
		$post = $this->published_post();

		$this->assertSame( Post_List::RESULT_QUEUED, Post_List::share_post( $post ) );
		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
	}

	/**
	 * A pending retry is not joined by a second worker.
	 *
	 * The retry ladder reaches fifteen minutes, which is outside the ten
	 * minute window core dedupes on, so this is the case our own
	 * `wp_next_scheduled()` guard exists for.
	 */
	public function test_share_post_does_not_queue_alongside_a_pending_retry() {
		$this->become_admin();
		$post = $this->published_post();

		\wp_schedule_single_event( \time() + HOUR_IN_SECONDS, 'atmosphere_publish_post', array( $post->ID ) );

		$this->assertSame( Post_List::RESULT_DUPLICATE, Post_List::share_post( $post ) );
	}

	/**
	 * Sharing refuses a post the current user may not edit, and says so
	 * rather than claiming the post was already queued.
	 */
	public function test_share_post_refuses_without_capability() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post = $this->published_post();

		$this->assertSame( Post_List::RESULT_REFUSED, Post_List::share_post( $post ) );
		$this->assertFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
	}

	/**
	 * The gate is enforced where the side effect is, not only where the
	 * hooks are wired, so a late filter still switches sharing off.
	 */
	public function test_share_post_refuses_when_auto_publish_is_off() {
		$this->become_admin();
		$post = $this->published_post();

		\add_filter( 'atmosphere_should_auto_publish', '__return_false' );

		$this->assertSame( Post_List::RESULT_REFUSED, Post_List::share_post( $post ) );
		$this->assertFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
	}

	/**
	 * The handler refuses a request for a post the user cannot edit,
	 * before any nonce is even considered.
	 */
	public function test_handle_share_dies_without_capability() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post = $this->published_post();

		$_GET['post'] = $post->ID;

		try {
			Post_List::handle_share();
			$this->fail( 'Expected wp_die() for an unauthorized share.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
		} finally {
			unset( $_GET['post'] );
		}

		$this->assertFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
	}

	/**
	 * A missing post is refused rather than acted on.
	 */
	public function test_handle_share_dies_for_unknown_post() {
		$this->become_admin();

		$_GET['post'] = 99999999;

		try {
			Post_List::handle_share();
			$this->fail( 'Expected wp_die() for an unknown post.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'Unauthorized', $e->getMessage() );
		} finally {
			unset( $_GET['post'] );
		}
	}

	/**
	 * A request without a valid nonce never reaches the scheduler.
	 */
	public function test_handle_share_requires_a_valid_nonce() {
		$this->become_admin();
		$post = $this->published_post();

		$_GET['post']                 = $post->ID;
		$_REQUEST['atmosphere_nonce'] = 'not-a-real-nonce';

		try {
			Post_List::handle_share();
			$this->fail( 'Expected the nonce check to stop the request.' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		} finally {
			unset( $_GET['post'], $_REQUEST['atmosphere_nonce'] );
		}

		$this->assertFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
	}

	/**
	 * A valid request queues the worker and redirects back to the list
	 * screen for that post type, carrying the outcome.
	 */
	public function test_handle_share_queues_and_redirects_to_the_right_list() {
		$this->become_admin();
		\add_post_type_support( 'page', 'atmosphere' );
		$post = $this->published_post( array( 'post_type' => 'page' ) );

		$_GET['post']                 = $post->ID;
		$_REQUEST['atmosphere_nonce'] = \wp_create_nonce( Post_List::ACTION . '_' . $post->ID );

		$captured = null;
		\add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = $location;
				throw new \WPDieException( 'redirect_intercepted' );
			}
		);

		try {
			Post_List::handle_share();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'redirect_intercepted', $e->getMessage() );
		} finally {
			unset( $_GET['post'], $_REQUEST['atmosphere_nonce'] );
		}

		$this->assertIsString( $captured );
		$this->assertStringContainsString( 'post_type=page', $captured, 'Pages redirect to the Pages screen.' );
		$this->assertStringContainsString( 'atmosphere_shared=' . Post_List::RESULT_QUEUED, $captured );
		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
	}
}
