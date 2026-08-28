<?php
/**
 * Tests for the posts-list Bluesky column and its manual share action.
 *
 * The column reads meta the publish path already writes, so the tests
 * seed that meta directly rather than running a publish. Three states
 * matter: a shared post (`Post::META_URI` set) links to the appview, a
 * failed post surfaces the stored reason, and everything else stays an
 * em dash so a site with years of pre-plugin history is not filled with
 * "not shared" noise.
 *
 * The share action is gated per post rather than site-wide: an author
 * who can edit the post can re-share it, and a post whose Bluesky
 * companion is filtered off never offers the action at all.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

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
		\delete_option( 'atmosphere_auto_publish' );

		parent::tear_down();
	}

	/**
	 * Create a published post owned by the current user.
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
	 * Capture the column output for one post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function render( int $post_id ): string {
		\ob_start();
		Post_List::render_column( Post_List::COLUMN, $post_id );

		return (string) \ob_get_clean();
	}

	/**
	 * On a site that shares nothing, the list surfaces are absent rather
	 * than present and inert, and the share handler is not registered at
	 * all so there is no craftable path to share.
	 */
	public function test_register_skips_surfaces_when_auto_publish_is_off() {
		\remove_all_filters( 'post_row_actions' );
		\remove_all_actions( 'admin_post_' . Post_List::ACTION );
		\add_filter( 'atmosphere_should_auto_publish', '__return_false' );

		Post_List::register();

		$this->assertFalse( \has_filter( 'post_row_actions', array( Post_List::class, 'add_row_action' ) ) );
		$this->assertFalse( \has_action( 'admin_post_' . Post_List::ACTION, array( Post_List::class, 'handle_share' ) ) );
	}

	/**
	 * With sharing on, the row action and the handler are both wired.
	 */
	public function test_register_wires_surfaces_when_auto_publish_is_on() {
		\remove_all_filters( 'post_row_actions' );
		\remove_all_actions( 'admin_post_' . Post_List::ACTION );
		\add_filter( 'atmosphere_should_auto_publish', '__return_true' );

		Post_List::register();

		$this->assertNotFalse( \has_filter( 'post_row_actions', array( Post_List::class, 'add_row_action' ) ) );
		$this->assertNotFalse( \has_action( 'admin_post_' . Post_List::ACTION, array( Post_List::class, 'handle_share' ) ) );
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
	 * A shared post links to the record on the appview.
	 */
	public function test_render_column_links_shared_post() {
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
		$post = $this->published_post();
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:abc123/app.bsky.feed.post/rkey789' );

		\add_filter( 'atmosphere_appview_host', static fn () => 'deer.social' );
		$output = $this->render( $post->ID );
		\remove_all_filters( 'atmosphere_appview_host' );

		$this->assertStringContainsString( 'deer.social/profile/did:plc:abc123/post/rkey789', $output );
		$this->assertStringNotContainsString( 'bsky.app', $output );
	}

	/**
	 * A post that was never shared stays quiet.
	 */
	public function test_render_column_is_blank_for_never_shared_post() {
		$output = $this->render( $this->published_post()->ID );

		$this->assertStringNotContainsString( '<a ', $output );
		$this->assertStringContainsString( '&mdash;', $output );
	}

	/**
	 * A failed share surfaces the stored reason instead of an em dash.
	 */
	public function test_render_column_shows_failure_reason() {
		$post = $this->published_post();
		\update_post_meta(
			$post->ID,
			'_atmosphere_last_publish_error',
			array(
				'code'    => 'http_500',
				'message' => 'The PDS said no.',
				'time'    => 1000,
			)
		);

		$output = $this->render( $post->ID );

		$this->assertStringContainsString( 'The PDS said no.', $output );
		$this->assertStringNotContainsString( '<a ', $output );
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

		$this->assertTrue( Post_List::share_post( $post ) );
		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
	}

	/**
	 * A second click while an attempt is already queued does not queue a
	 * duplicate worker.
	 */
	public function test_share_post_does_not_queue_twice() {
		$this->become_admin();
		$post = $this->published_post();

		Post_List::share_post( $post );
		$this->assertFalse( Post_List::share_post( $post ), 'Second call reports nothing new was queued.' );
	}

	/**
	 * Sharing refuses a post the current user may not edit.
	 */
	public function test_share_post_refuses_without_capability() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post = $this->published_post();

		$this->assertFalse( Post_List::share_post( $post ) );
		$this->assertFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
	}
}
