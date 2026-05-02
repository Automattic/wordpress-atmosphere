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
use Atmosphere\Transformer\Comment;
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
	 * A fresh post is created each call: WP_UnitTestCase rolls back
	 * DB state between tests, so reusing an ID across tests via a
	 * static cache would leave later tests pointing at a row that no
	 * longer exists.
	 *
	 * @param array $overrides Comment field overrides.
	 * @return \WP_Comment
	 */
	private function make_eligible_comment( array $overrides = array() ): \WP_Comment {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/abc' );
		\update_post_meta( $post_id, Post::META_CID, 'bafyroot' );

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

	/**
	 * Eligibility requires the root post to have both META_URI and
	 * META_CID — both are needed to build a valid reply strongRef.
	 */
	public function test_comment_on_post_without_cid_is_skipped() {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/nocid' );
		// No META_CID on purpose.

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'user_id'          => self::factory()->user->create(),
			)
		);

		$this->assertFalse( Atmosphere::should_publish_comment( \get_comment( $comment_id ) ) );
	}

	/**
	 * Approving → unapprove transitions must not schedule a delete
	 * when the plugin is disconnected — otherwise we'd enqueue a cron
	 * event that has no credentials to execute and only orphans the
	 * remote record.
	 */
	public function test_disconnected_state_does_not_schedule_comment_delete() {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/abc' );
		\update_post_meta( $post_id, Post::META_CID, 'bafyroot' );

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'user_id'          => self::factory()->user->create(),
			)
		);
		\update_comment_meta( $comment_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/reply' );

		\delete_option( 'atmosphere_connection' );

		$this->atmosphere->on_comment_status_change( 'unapproved', 'approved', \get_comment( $comment_id ) );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_comment', array( $comment_id ) ),
			'Disconnected state must not schedule a comment delete.'
		);
	}

	/**
	 * Hard-delete hook must not double-schedule the TID-only delete
	 * cron when it fires more than once for the same TID.
	 */
	public function test_comment_before_delete_does_not_double_schedule() {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/abc' );

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'user_id'         => self::factory()->user->create(),
			)
		);
		\update_comment_meta( $comment_id, Comment::META_TID, 'deadbeef' );
		\update_comment_meta( $comment_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/deadbeef' );

		$this->atmosphere->on_comment_before_delete( $comment_id );
		$this->atmosphere->on_comment_before_delete( $comment_id );

		$cron      = \_get_cron_array();
		$scheduled = 0;
		foreach ( $cron as $events ) {
			foreach ( $events['atmosphere_delete_comment_record'] ?? array() as $event ) {
				if ( isset( $event['args'][0] ) && 'deadbeef' === $event['args'][0] ) {
					++$scheduled;
				}
			}
		}

		$this->assertSame( 1, $scheduled, 'Expected exactly one delete_comment_record cron event.' );

		\wp_clear_scheduled_hook( 'atmosphere_delete_comment_record', array( 'deadbeef' ) );
	}

	/**
	 * The publish cron handler re-checks eligibility at fire time.
	 * A comment unapproved between schedule and execution must not
	 * publish; without this guard, the async event would send the
	 * record even though the gate now says no.
	 */
	public function test_publish_comment_cron_rechecks_eligibility() {

		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;

		// Flip the comment to unapproved after "scheduling".
		\wp_set_comment_status( $comment_id, 'hold' );

		$captured = false;
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured ) {
				if ( false !== \strpos( $url, 'applyWrites' ) ) {
					$captured = true;
				}
				return $response;
			},
			5,
			3
		);

		\do_action( 'atmosphere_publish_comment', $comment_id );
		\remove_all_filters( 'pre_http_request' );

		$this->assertFalse( $captured, 'applyWrites must not be called for a no-longer-eligible comment.' );
	}

	/**
	 * The delete cron handler must not fire when the comment has
	 * become eligible again between schedule and execution (e.g.
	 * admin unapproved then re-approved before cron ran).
	 */
	public function test_delete_comment_cron_skips_when_eligible_again() {

		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;
		// Simulate a prior successful publish.
		\update_comment_meta( $comment_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/prev' );
		\update_comment_meta( $comment_id, Comment::META_TID, 'prev' );

		$captured = false;
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured ) {
				if ( false !== \strpos( $url, 'applyWrites' ) ) {
					$captured = true;
				}
				return $response;
			},
			5,
			3
		);

		\do_action( 'atmosphere_delete_comment', $comment_id );
		\remove_all_filters( 'pre_http_request' );

		$this->assertFalse( $captured, 'applyWrites#delete must not be called for a re-approved comment.' );
	}

	/**
	 * When a parent comment is eligible but has not yet published,
	 * the child's cron handler reschedules itself and does not call
	 * the PDS. This prevents a batch approval from publishing the
	 * child flat as a top-level reply before the parent exists.
	 */
	public function test_publish_comment_defers_when_parent_pending() {

		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/root' );
		\update_post_meta( $post_id, Post::META_CID, 'bafyroot' );

		$user_id = self::factory()->user->create();

		$parent_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);
		// Parent is eligible but not yet published — no META_URI.

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_parent'   => $parent_id,
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);

		$captured = false;
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured ) {
				if ( false !== \strpos( $url, 'applyWrites' ) ) {
					$captured = true;
				}
				return $response;
			},
			5,
			3
		);

		\do_action( 'atmosphere_publish_comment', $child_id );
		\remove_all_filters( 'pre_http_request' );

		$this->assertFalse( $captured, 'Child must not publish while parent is pending.' );
		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_publish_comment', array( $child_id ) ),
			'Child must be rescheduled when parent is pending.'
		);
		$this->assertSame(
			'1',
			\get_comment_meta( $child_id, '_atmosphere_publish_attempts', true ),
			'Deferral counter must be incremented on each hop.'
		);

		\wp_clear_scheduled_hook( 'atmosphere_publish_comment', array( $child_id ) );
	}

	/**
	 * Approve transition schedules a publish.
	 */
	public function test_status_change_unapproved_to_approved_schedules_publish() {
		$comment = $this->make_eligible_comment();

		$this->atmosphere->on_comment_status_change( 'approved', 'unapproved', $comment );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_publish_comment', array( (int) $comment->comment_ID ) ),
			'Approve transition must schedule atmosphere_publish_comment.'
		);

		\wp_clear_scheduled_hook( 'atmosphere_publish_comment', array( (int) $comment->comment_ID ) );
	}

	/**
	 * Unapprove transition on a published comment schedules a delete.
	 */
	public function test_status_change_approved_to_unapproved_schedules_delete() {
		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;
		\update_comment_meta( $comment_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/existing' );

		$this->atmosphere->on_comment_status_change( 'unapproved', 'approved', $comment );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_delete_comment', array( $comment_id ) ),
			'Unapprove transition on a published comment must schedule atmosphere_delete_comment.'
		);

		\wp_clear_scheduled_hook( 'atmosphere_delete_comment', array( $comment_id ) );
	}

	/**
	 * Comment inserted already-approved schedules a publish.
	 */
	public function test_insert_approved_schedules_publish() {
		$comment = $this->make_eligible_comment();

		$this->atmosphere->on_comment_insert( (int) $comment->comment_ID, 1 );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_publish_comment', array( (int) $comment->comment_ID ) ),
			'Already-approved insert must schedule atmosphere_publish_comment.'
		);

		\wp_clear_scheduled_hook( 'atmosphere_publish_comment', array( (int) $comment->comment_ID ) );
	}

	/**
	 * Comment inserted unapproved (moderation queue) does not schedule.
	 */
	public function test_insert_unapproved_does_not_schedule() {
		$comment = $this->make_eligible_comment();

		$this->atmosphere->on_comment_insert( (int) $comment->comment_ID, 0 );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_comment', array( (int) $comment->comment_ID ) ),
			'Pending comment must not schedule a publish.'
		);
	}

	/**
	 * Spam comment never schedules.
	 */
	public function test_insert_spam_does_not_schedule() {
		$comment = $this->make_eligible_comment();

		$this->atmosphere->on_comment_insert( (int) $comment->comment_ID, 'spam' );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_comment', array( (int) $comment->comment_ID ) ),
			'Spam insert must not schedule a publish.'
		);
	}

	/**
	 * Editing an already-published comment schedules an update.
	 */
	public function test_edit_with_uri_schedules_update() {
		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;
		\update_comment_meta( $comment_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/existing' );

		$this->atmosphere->on_comment_edit( $comment_id );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_update_comment', array( $comment_id ) ),
			'Editing a published comment must schedule atmosphere_update_comment.'
		);
		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_comment', array( $comment_id ) ),
			'Editing a published comment must not schedule a publish.'
		);

		\wp_clear_scheduled_hook( 'atmosphere_update_comment', array( $comment_id ) );
	}

	/**
	 * Editing an approved-but-never-published comment schedules a publish.
	 * Covers the failed-initial-publish recovery path: the edit catches
	 * the comment up, rather than silently leaving it at TID-only meta.
	 */
	public function test_edit_without_uri_schedules_publish() {
		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;

		$this->atmosphere->on_comment_edit( $comment_id );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_publish_comment', array( $comment_id ) ),
			'Editing an unpublished-but-eligible comment must schedule a publish.'
		);

		\wp_clear_scheduled_hook( 'atmosphere_publish_comment', array( $comment_id ) );
	}

	/**
	 * Editing an unapproved comment does not schedule anything — the
	 * eligibility gate rejects it before the handler decides publish
	 * vs. update.
	 */
	public function test_edit_unapproved_does_not_schedule() {
		$comment    = $this->make_eligible_comment( array( 'comment_approved' => '0' ) );
		$comment_id = (int) $comment->comment_ID;

		$this->atmosphere->on_comment_edit( $comment_id );

		$this->assertFalse( \wp_next_scheduled( 'atmosphere_publish_comment', array( $comment_id ) ) );
		$this->assertFalse( \wp_next_scheduled( 'atmosphere_update_comment', array( $comment_id ) ) );
	}

	/**
	 * Hard-delete of a comment with a TID but no URI (failed earlier
	 * publish) must not schedule the TID-only delete cron — no record
	 * exists on the PDS to remove.
	 */
	public function test_before_delete_with_tid_but_no_uri_does_not_schedule() {
		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;
		\update_comment_meta( $comment_id, Comment::META_TID, 'staletid' );

		$this->atmosphere->on_comment_before_delete( $comment_id );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_comment_record', array( 'staletid' ) ),
			'TID without URI (failed earlier publish) must not schedule a delete.'
		);
	}

	/**
	 * After the deferral cap the child publishes anyway so a stuck
	 * parent cannot block it forever; the root-fallback branch of
	 * Transformer\Comment::resolve_parent_ref takes over.
	 */
	public function test_publish_comment_proceeds_after_parent_defer_cap() {

		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/root' );
		\update_post_meta( $post_id, Post::META_CID, 'bafyroot' );

		$user_id = self::factory()->user->create();

		$parent_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_parent'   => $parent_id,
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);
		// Already at the cap — next fire must proceed rather than defer.
		\update_comment_meta( $child_id, '_atmosphere_publish_attempts', 3 );

		\do_action( 'atmosphere_publish_comment', $child_id );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_comment', array( $child_id ) ),
			'After the cap the handler must not re-enqueue the child.'
		);
		$this->assertSame(
			'',
			\get_comment_meta( $child_id, '_atmosphere_publish_attempts', true ),
			'Counter must be cleared once the child proceeds.'
		);
	}

	/**
	 * Permanent delete must cascade to outbound comment replies.
	 *
	 * `before_delete_post` fires before WP iterates child comments, so
	 * `on_before_delete` is the only point at which we can read those
	 * comments' TIDs. The scheduled `atmosphere_delete_records` event
	 * must include them so a single batch removes the post, document,
	 * and every reply record.
	 */
	public function test_on_before_delete_includes_published_comment_tids() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, 'bsky-tid-root' );
		\update_post_meta( $post_id, Document::META_TID, 'doc-tid-root' );

		// Two published comment replies.
		$comment_a = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
			)
		);
		\update_comment_meta( $comment_a, Comment::META_TID, 'bsky-tid-a' );
		\update_comment_meta( $comment_a, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/bsky-tid-a' );

		$comment_b = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
			)
		);
		\update_comment_meta( $comment_b, Comment::META_TID, 'bsky-tid-b' );
		\update_comment_meta( $comment_b, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/bsky-tid-b' );

		// One reply with a TID but no URI — never reached the PDS, must be excluded.
		$comment_unpublished = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
			)
		);
		\update_comment_meta( $comment_unpublished, Comment::META_TID, 'bsky-tid-orphan' );

		$this->atmosphere->on_before_delete( $post_id );

		$expected_args = array(
			array( 'bsky-tid-root' ),
			'doc-tid-root',
			array( 'bsky-tid-a', 'bsky-tid-b' ),
		);

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_delete_records', $expected_args ),
			'Expected atmosphere_delete_records to be scheduled with the published comment TIDs.'
		);
	}

	/**
	 * Posts with no published comment replies still schedule the
	 * existing post + document delete pair — backward compatible.
	 */
	public function test_on_before_delete_without_comments_schedules_post_only() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, 'bsky-tid-root' );
		\update_post_meta( $post_id, Document::META_TID, 'doc-tid-root' );

		$this->atmosphere->on_before_delete( $post_id );

		$this->assertNotFalse(
			\wp_next_scheduled(
				'atmosphere_delete_records',
				array( array( 'bsky-tid-root' ), 'doc-tid-root', array() )
			),
			'Expected atmosphere_delete_records with empty comment list when the post has no replies.'
		);
	}

	/**
	 * Unpublish of a previously-synced post with a post type no longer in
	 * the syncable allowlist must still schedule remote cleanup. Without
	 * this, narrowing the allowlist after publishing orphans the remote
	 * records.
	 */
	public function test_unpublish_of_previously_synced_non_syncable_post_schedules_delete() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status' => 'draft',
				'post_type'   => 'page',
			)
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'draft', 'publish', $post );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Unpublish must clean up remote records even when the post type is no longer in the syncable allowlist.'
		);
	}

	/**
	 * Permanent delete of a previously-synced post with a post type no
	 * longer in the syncable allowlist must still capture TIDs and
	 * schedule remote cleanup. Same rationale as the unpublish test
	 * above: the allowlist governs new-publish eligibility, not cleanup.
	 */
	public function test_before_delete_of_previously_synced_non_syncable_post_schedules_delete_records() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		$this->atmosphere->on_before_delete( $post->ID );

		$this->assertNotFalse(
			\wp_next_scheduled(
				'atmosphere_delete_records',
				array( array( 'bsky-tid-123' ), 'doc-tid-456', array() )
			),
			'Permanent delete must schedule remote cleanup even when the post type is no longer in the syncable allowlist.'
		);
	}

	/**
	 * Regression guard for the split gate: narrowing the allowlist via
	 * the `atmosphere_syncable_post_types` filter must still block a
	 * new-publish of a post type the filter excludes. Only cleanup
	 * paths are meant to bypass the allowlist.
	 */
	public function test_new_publish_respects_allowlist_even_when_filter_narrows() {
		$narrow = static function () {
			return array( 'page' );
		};
		\add_filter( 'atmosphere_syncable_post_types', $narrow );

		try {
			$post = self::factory()->post->create_and_get(
				array(
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			$this->reset_publishing_action();
			$this->atmosphere->on_status_change( 'publish', 'draft', $post );

			$this->assertFalse(
				\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
				'New publish of a post type outside the allowlist must not be scheduled.'
			);
		} finally {
			\remove_filter( 'atmosphere_syncable_post_types', $narrow );
		}
	}

	/**
	 * `Atmosphere\deactivate` clears every plugin-owned cron hook so a
	 * deactivate→reactivate cycle (or deactivate→reconnect→reactivate)
	 * cannot fire stale events against the new connection's repo.
	 */
	public function test_deactivate_clears_all_cron_hooks() {
		$hooks = \Atmosphere\get_cron_hooks();

		foreach ( $hooks as $hook ) {
			\wp_schedule_single_event( \time() + 60, $hook, array() );
		}
		foreach ( $hooks as $hook ) {
			$this->assertNotFalse( \wp_next_scheduled( $hook ), "Setup: {$hook} must be scheduled." );
		}

		\Atmosphere\deactivate();

		foreach ( $hooks as $hook ) {
			$this->assertFalse(
				\wp_next_scheduled( $hook ),
				"deactivate() must clear scheduled hook: {$hook}"
			);
		}
	}

	/**
	 * `Client::disconnect` clears the same crons as `deactivate()`.
	 *
	 * A disconnect→reconnect-to-different-account cycle would otherwise
	 * fire `atmosphere_delete_records` /
	 * `atmosphere_delete_comment_record` against the new account's
	 * repo, since neither cron handler re-checks the connection's DID
	 * before issuing the delete.
	 */
	public function test_disconnect_clears_all_cron_hooks() {
		$hooks = \Atmosphere\get_cron_hooks();

		foreach ( $hooks as $hook ) {
			\wp_schedule_single_event( \time() + 60, $hook, array() );
		}

		\Atmosphere\OAuth\Client::disconnect();

		foreach ( $hooks as $hook ) {
			$this->assertFalse(
				\wp_next_scheduled( $hook ),
				"Client::disconnect must clear scheduled hook: {$hook}"
			);
		}
	}

	/**
	 * Race: a moderator unapproves the comment while applyWrites is in
	 * flight. `Comment::get_rkey` writes META_TID before the API call,
	 * but META_URI is only written after the call returns. The status
	 * transition's cleanup hook requires META_URI, so it silently
	 * short-circuits — and once the in-flight publish lands, the
	 * record is live on Bluesky with no scheduled cleanup.
	 *
	 * After publish, `reconcile_comment_after_publish` re-fetches the
	 * comment; if it is no longer eligible the meta we just wrote is
	 * cleared and the TID-only delete cron used by the permanent-delete
	 * path is scheduled.
	 */
	public function test_reconcile_after_publish_schedules_delete_when_comment_unapproved_mid_publish() {
		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;

		$captured_tid = '';
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function ( $short, $writes ) use ( $comment_id, &$captured_tid ) {
				$captured_tid = $writes[0]['rkey'] ?? '';

				/*
				 * Simulate the moderator unapproving the comment during
				 * the in-flight applyWrites. The status transition
				 * fires on_comment_status_change which would normally
				 * schedule a delete, but META_URI is empty during the
				 * race window so it short-circuits.
				 */
				\wp_set_comment_status( $comment_id, 'hold' );

				return array(
					'results' => array(
						array(
							'uri' => 'at://did:plc:test123/app.bsky.feed.post/' . $captured_tid,
							'cid' => 'bafyreibraced',
						),
					),
				);
			},
			10,
			2
		);

		\do_action( 'atmosphere_publish_comment', $comment_id );

		$this->assertNotEmpty( $captured_tid, 'applyWrites filter must have fired.' );

		$this->assertEmpty(
			\get_comment_meta( $comment_id, Comment::META_TID, true ),
			'Reconcile must clear the orphan TID meta.'
		);
		$this->assertEmpty(
			\get_comment_meta( $comment_id, Comment::META_URI, true ),
			'Reconcile must clear the orphan URI meta.'
		);

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_delete_comment_record', array( $captured_tid ) ),
			'Reconcile must schedule delete-by-TID for the orphan record.'
		);

		\remove_all_filters( 'atmosphere_pre_apply_writes' );
		\wp_clear_scheduled_hook( 'atmosphere_delete_comment_record' );
	}

	/**
	 * If the comment is still eligible after publish (the normal case),
	 * reconcile is a no-op: meta survives and no delete is scheduled.
	 */
	public function test_reconcile_after_publish_is_noop_for_still_eligible_comment() {
		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;

		\add_filter(
			'atmosphere_pre_apply_writes',
			static function ( $short, $writes ) {
				$results = array();
				foreach ( $writes as $write ) {
					$rkey      = $write['rkey'] ?? 'tid';
					$results[] = array(
						'uri' => 'at://did:plc:test123/app.bsky.feed.post/' . $rkey,
						'cid' => 'bafyreibtest',
					);
				}
				return array( 'results' => $results );
			},
			10,
			2
		);

		\do_action( 'atmosphere_publish_comment', $comment_id );

		$this->assertNotEmpty(
			\get_comment_meta( $comment_id, Comment::META_URI, true ),
			'Eligible comment must keep its URI meta.'
		);
		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_comment_record' ),
			'No delete should be scheduled when the comment is still eligible.'
		);

		\remove_all_filters( 'atmosphere_pre_apply_writes' );
	}
}
