<?php
/**
 * Tests for the Atmosphere class.
 *
 * Covers post status transitions that schedule the async publish,
 * update, and delete hooks, and the eligibility gate for outbound
 * comment publishing.
 *
 * @package Atmosphere
 * @group atmosphere
 */

namespace Atmosphere\Tests;

use WP_UnitTestCase;
use Atmosphere\Atmosphere;
use Atmosphere\Reaction_Sync;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;

/**
 * Atmosphere tests.
 */
class Test_Atmosphere extends WP_UnitTestCase {

	/**
	 * Atmosphere instance.
	 *
	 * @var Atmosphere
	 */
	private Atmosphere $atmosphere;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->atmosphere = new Atmosphere();

		\update_option(
			'atmosphere_connection',
			array(
				'access_token' => 'encrypted-token',
				'did'          => 'did:plc:test123',
				'pds_endpoint' => 'https://pds.example.com',
			)
		);
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );

		\wp_clear_scheduled_hook( 'atmosphere_publish_post' );
		\wp_clear_scheduled_hook( 'atmosphere_update_post' );
		\wp_clear_scheduled_hook( 'atmosphere_delete_post' );
		\wp_clear_scheduled_hook( 'atmosphere_delete_records' );

		\remove_all_filters( 'atmosphere_should_publish_comment' );

		parent::tear_down();
	}

	/**
	 * Reset the atmosphere_publishing action counter.
	 *
	 * The plugin's own transition_post_status hook fires when the
	 * factory creates a test post, incrementing the counter. Reset
	 * it before calling on_status_change() directly.
	 */
	private function reset_publishing_action(): void {
		global $wp_actions;
		unset( $wp_actions['atmosphere_publishing'] );
	}

	/**
	 * Build a WP_Comment on a published post for comment eligibility tests.
	 *
	 * @param array $overrides Comment field overrides.
	 * @return \WP_Comment
	 */
	private function make_eligible_comment( array $overrides = array() ): \WP_Comment {
		static $post_id = null;

		if ( null === $post_id ) {
			$post_id = self::factory()->post->create();
			\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/abc' );
		}

		$defaults = array(
			'comment_post_ID'  => $post_id,
			'comment_approved' => '1',
			'comment_type'     => 'comment',
			'user_id'          => self::factory()->user->create(),
			'comment_content'  => 'Hello.',
		);

		$comment_id = self::factory()->comment->create( \array_merge( $defaults, $overrides ) );

		return \get_comment( $comment_id );
	}

	/**
	 * Test that draft → publish schedules a publish event.
	 */
	public function test_draft_to_publish_schedules_publish() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'draft', $post );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'Expected atmosphere_publish_post to be scheduled.'
		);
	}

	/**
	 * Test that publish → publish schedules an update event.
	 */
	public function test_publish_to_publish_schedules_update() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'publish', $post );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) ),
			'Expected atmosphere_update_post to be scheduled.'
		);
	}

	/**
	 * Test that publish → draft schedules a delete event.
	 */
	public function test_publish_to_draft_schedules_delete() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'draft' )
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'draft', 'publish', $post );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Expected atmosphere_delete_post to be scheduled.'
		);
	}

	/**
	 * Test that publish → trash schedules a delete event.
	 */
	public function test_publish_to_trash_schedules_delete() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'trash' )
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'trash', 'publish', $post );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Expected atmosphere_delete_post to be scheduled.'
		);
	}

	/**
	 * Test that draft → draft does NOT schedule a delete event.
	 *
	 * This is the key regression test: previously, any non-publish
	 * new_status would schedule a delete if TIDs existed.
	 */
	public function test_draft_to_draft_does_not_schedule_delete() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'draft' )
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'draft', 'draft', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Draft → draft must NOT schedule a delete.'
		);
	}

	/**
	 * Test that pending → pending does NOT schedule a delete event.
	 */
	public function test_pending_to_pending_does_not_schedule_delete() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'pending' )
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'pending', 'pending', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Pending → pending must NOT schedule a delete.'
		);
	}

	/**
	 * Test that draft → pending does NOT schedule a delete event.
	 */
	public function test_draft_to_pending_does_not_schedule_delete() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'pending' )
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'pending', 'draft', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Draft → pending must NOT schedule a delete.'
		);
	}

	/**
	 * Test that publish → draft without TIDs does NOT schedule a delete.
	 */
	public function test_unpublish_without_tids_does_not_schedule_delete() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'draft' )
		);

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'draft', 'publish', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Unpublish without TIDs must NOT schedule a delete.'
		);
	}

	/**
	 * Test that trash → publish (restore) schedules a publish event.
	 */
	public function test_restore_from_trash_schedules_publish() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'trash', $post );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'Expected atmosphere_publish_post to be scheduled on restore.'
		);
	}

	/**
	 * Test that non-syncable post types are ignored.
	 */
	public function test_non_syncable_post_type_ignored() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'draft', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'Non-syncable post types must be ignored.'
		);
	}

	/**
	 * Test that disconnected state prevents scheduling.
	 */
	public function test_disconnected_state_prevents_scheduling() {
		\delete_option( 'atmosphere_connection' );

		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'draft', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'Disconnected state must prevent scheduling.'
		);
	}

	/**
	 * Baseline: approved comment from a registered user on a published
	 * post is publishable.
	 */
	public function test_eligible_registered_user_approved_comment_publishes() {
		$comment = $this->make_eligible_comment();

		$this->assertTrue( Atmosphere::should_publish_comment( $comment ) );
	}

	/**
	 * Anonymous commenters (user_id === 0) are skipped.
	 */
	public function test_anonymous_comment_is_skipped() {
		$comment = $this->make_eligible_comment( array( 'user_id' => 0 ) );

		$this->assertFalse( Atmosphere::should_publish_comment( $comment ) );
	}

	/**
	 * Trackbacks are skipped regardless of author.
	 */
	public function test_trackback_is_skipped() {
		$comment = $this->make_eligible_comment( array( 'comment_type' => 'trackback' ) );

		$this->assertFalse( Atmosphere::should_publish_comment( $comment ) );
	}

	/**
	 * Pingbacks are skipped regardless of author.
	 */
	public function test_pingback_is_skipped() {
		$comment = $this->make_eligible_comment( array( 'comment_type' => 'pingback' ) );

		$this->assertFalse( Atmosphere::should_publish_comment( $comment ) );
	}

	/**
	 * Unapproved comments are skipped.
	 */
	public function test_unapproved_comment_is_skipped() {
		$comment = $this->make_eligible_comment( array( 'comment_approved' => '0' ) );

		$this->assertFalse( Atmosphere::should_publish_comment( $comment ) );
	}

	/**
	 * Comments ingested from Bluesky (protocol=atproto meta) are
	 * skipped to prevent a publish loop.
	 */
	public function test_federated_comment_is_skipped() {
		$comment = $this->make_eligible_comment();
		\update_comment_meta( (int) $comment->comment_ID, Reaction_Sync::META_PROTOCOL, 'atproto' );

		$this->assertFalse( Atmosphere::should_publish_comment( $comment ) );
	}

	/**
	 * Comments on posts that have not yet been published to AT are
	 * skipped — there is no root ref to thread a reply against.
	 */
	public function test_comment_on_unpublished_post_is_skipped() {
		$other_post = self::factory()->post->create();
		$comment    = $this->make_eligible_comment( array( 'comment_post_ID' => $other_post ) );

		$this->assertFalse( Atmosphere::should_publish_comment( $comment ) );
	}

	/**
	 * When the plugin is not connected, comments do not publish.
	 */
	public function test_disconnected_state_skips_comment_publish() {
		\delete_option( 'atmosphere_connection' );

		$comment = $this->make_eligible_comment();

		$this->assertFalse( Atmosphere::should_publish_comment( $comment ) );
	}

	/**
	 * Third parties can veto publication via filter.
	 */
	public function test_comment_filter_can_veto_publish() {
		$comment = $this->make_eligible_comment();

		\add_filter( 'atmosphere_should_publish_comment', '__return_false' );

		$this->assertFalse( Atmosphere::should_publish_comment( $comment ) );
	}

	/**
	 * Third parties can force-allow publication via filter (e.g.
	 * overriding the anonymous-only guard for a specific integration).
	 */
	public function test_comment_filter_can_force_publish() {
		$comment = $this->make_eligible_comment( array( 'user_id' => 0 ) );

		\add_filter( 'atmosphere_should_publish_comment', '__return_true' );

		$this->assertTrue( Atmosphere::should_publish_comment( $comment ) );
	}

	/**
	 * Comments stamped with the plugin's own agent string are skipped,
	 * even if META_PROTOCOL has not yet been written. Guards against a
	 * publish loop if the Reaction_Sync insert path ever fires
	 * comment_post before its meta writes complete.
	 */
	public function test_comment_with_atmosphere_agent_is_skipped() {
		$comment = $this->make_eligible_comment(
			array( 'comment_agent' => 'ATmosphere/0.0.0-unreleased' )
		);

		$this->assertFalse( Atmosphere::should_publish_comment( $comment ) );
	}
}
