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
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_publication_tid' );
		\delete_option( 'atmosphere_publish_reactions' );
		\delete_option( \Atmosphere\OAuth\Client::DISCONNECTED_OPTION );

		/*
		 * wp_unschedule_hook(), not wp_clear_scheduled_hook(): the events
		 * these tests queue carry per-post args, which the argless clear
		 * would silently skip.
		 */
		\wp_unschedule_hook( 'atmosphere_publish_post' );
		\wp_unschedule_hook( 'atmosphere_update_post' );
		\wp_unschedule_hook( 'atmosphere_delete_post' );
		\wp_unschedule_hook( 'atmosphere_delete_records' );
		\wp_unschedule_hook( 'atmosphere_publish_comment' );
		\wp_unschedule_hook( 'atmosphere_update_comment' );
		\wp_unschedule_hook( 'atmosphere_delete_comment' );
		\wp_unschedule_hook( 'atmosphere_delete_comment_record' );

		\remove_all_filters( 'atmosphere_should_publish_comment' );
		\remove_all_filters( 'atmosphere_pre_apply_writes' );
		\delete_option( 'atmosphere_visibility_cleanup_migrated' );

		parent::tear_down();
	}

	/**
	 * Reset the legacy atmosphere_publishing action counter.
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
	 * Auto-publish turned off via the checkbox must not schedule a publish.
	 *
	 * An unchecked checkbox submits no value, so a saved "off" state is
	 * stored as an empty string rather than '0'. The gate publishes only on
	 * an explicit '1', so the empty string must be treated as off.
	 */
	public function test_auto_publish_off_empty_string_does_not_schedule_publish() {
		\update_option( 'atmosphere_auto_publish', '' );

		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'draft', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'Auto-publish stored as an empty string must be treated as off.'
		);
	}

	/**
	 * Auto-publish explicitly set to '0' must not schedule a publish.
	 */
	public function test_auto_publish_off_zero_does_not_schedule_publish() {
		\update_option( 'atmosphere_auto_publish', '0' );

		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'draft', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'Auto-publish stored as "0" must be treated as off.'
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
	 * Password-protected publishes are not public federation output.
	 */
	public function test_password_protected_publish_does_not_schedule_publish() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'draft', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'Password-protected publish must not schedule a publish.'
		);
		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Password-protected post with no remote records has nothing to delete.'
		);
	}

	/**
	 * Applying a password to a previously-synced post schedules cleanup,
	 * not an update carrying protected content.
	 */
	public function test_password_protected_update_schedules_delete_not_update() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'publish', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) ),
			'Password-protected update must not schedule a record update.'
		);
		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Password-protected update for a synced post must schedule remote cleanup.'
		);
	}

	/**
	 * Changing the share toggle schedules a reconcile directly off the
	 * committed meta write — the real REST order, where the meta lands after
	 * any status transition (and a meta-only save fires no transition at all).
	 */
	public function test_share_toggle_change_schedules_reconcile() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		\wp_clear_scheduled_hook( 'atmosphere_update_post', array( $post->ID ) );

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, ATMOSPHERE_META_DISABLED, '1' );

		$this->atmosphere->on_share_meta_changed( 0, $post->ID, ATMOSPHERE_META_DISABLED );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) ),
			'Changing the share toggle must schedule a reconcile.'
		);
	}

	/**
	 * Changing the custom Bluesky text schedules a reconcile too, so an
	 * already-shared post's Bluesky record is updated to the new text.
	 */
	public function test_custom_text_change_schedules_reconcile() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		\wp_clear_scheduled_hook( 'atmosphere_update_post', array( $post->ID ) );

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'My new Bluesky words.' );

		$this->atmosphere->on_share_meta_changed( 0, $post->ID, ATMOSPHERE_META_CUSTOM_TEXT );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) ),
			'Changing the custom Bluesky text must schedule a reconcile.'
		);
	}

	/**
	 * An unrelated meta key change does not schedule a reconcile.
	 */
	public function test_unrelated_meta_change_does_not_schedule_reconcile() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		\wp_clear_scheduled_hook( 'atmosphere_update_post', array( $post->ID ) );

		$this->atmosphere->on_share_meta_changed( 0, $post->ID, 'some_other_meta' );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) )
		);
	}

	/**
	 * When the reconcile runs for a post whose sharing has been switched off,
	 * the remote records are deleted — i.e. toggling sharing off removes the
	 * post from Bluesky, not just "stops future publishes".
	 */
	public function test_reconcile_removes_records_when_share_disabled() {
		\add_filter(
			'atmosphere_pre_apply_writes',
			static fn( $short, $writes ) => array( 'results' => \array_fill( 0, \count( $writes ), array() ) ),
			10,
			2
		);

		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );
		\update_post_meta( $post->ID, ATMOSPHERE_META_DISABLED, '1' );

		\do_action( 'atmosphere_update_post', $post->ID );

		\remove_all_filters( 'atmosphere_pre_apply_writes' );

		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_TID, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Document::META_TID, true ) );
	}

	/**
	 * Multiple visibility transitions in one request each schedule cleanup.
	 */
	public function test_bulk_password_protected_updates_schedule_cleanup_for_each_post() {
		$posts = array(
			self::factory()->post->create_and_get(
				array(
					'post_status'   => 'publish',
					'post_password' => 'secret',
				)
			),
			self::factory()->post->create_and_get(
				array(
					'post_status'   => 'publish',
					'post_password' => 'secret',
				)
			),
		);

		foreach ( $posts as $post ) {
			\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-' . $post->ID );
			\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-' . $post->ID );
		}

		$this->atmosphere->on_status_change( 'publish', 'publish', $posts[0] );
		$this->atmosphere->on_status_change( 'publish', 'publish', $posts[1] );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $posts[0]->ID ) ),
			'First protected post must schedule cleanup.'
		);
		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $posts[1]->ID ) ),
			'Second protected post must also schedule cleanup.'
		);
	}

	/**
	 * Historical leaks are scheduled once for cleanup on admin requests.
	 */
	public function test_historical_visibility_cleanup_schedules_existing_non_public_records() {
		\delete_option( 'atmosphere_visibility_cleanup_migrated' );
		\delete_option( 'atmosphere_visibility_cleanup_last_id' );

		$protected = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		\update_post_meta( $protected->ID, Post::META_TID, 'protected-bsky-tid' );
		\update_post_meta( $protected->ID, Document::META_TID, 'protected-doc-tid' );

		$public = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		\update_post_meta( $public->ID, Post::META_TID, 'public-bsky-tid' );
		\update_post_meta( $public->ID, Document::META_TID, 'public-doc-tid' );

		// First batch returns both posts; flips the cursor and schedules cleanups.
		$this->atmosphere->run_historical_visibility_cleanup();

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $protected->ID ) ),
			'Existing protected records must be scheduled for cleanup.'
		);
		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $public->ID ) ),
			'Publishable records must not be scheduled by the historical cleanup.'
		);

		// Migration is only marked complete on the terminal empty batch —
		// keyset paging cannot distinguish "walk exhausted" from "transient
		// empty" without a confirmed empty fetch.
		$this->assertFalse( \get_option( 'atmosphere_visibility_cleanup_migrated' ) );

		// Second invocation hits an empty result set (cursor is past the
		// max ID), which flips the migrated flag and clears the cursor.
		$this->atmosphere->run_historical_visibility_cleanup();

		$this->assertSame( '1', \get_option( 'atmosphere_visibility_cleanup_migrated' ) );
		$this->assertFalse( \get_option( 'atmosphere_visibility_cleanup_last_id' ) );
	}

	/**
	 * Removing a password after cleanup deleted remote records must
	 * publish fresh records, not route through update's unsynced skip.
	 */
	public function test_password_removed_after_cleanup_schedules_publish() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-tid-456' );

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'publish', $post );

		\delete_post_meta( $post->ID, Post::META_TID );
		\delete_post_meta( $post->ID, Post::META_URI );
		\delete_post_meta( $post->ID, Document::META_TID );
		\delete_post_meta( $post->ID, Document::META_URI );
		\wp_clear_scheduled_hook( 'atmosphere_delete_post', array( $post->ID ) );

		$post->post_password = '';

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'publish', $post );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'Password removal after cleanup must schedule a fresh publish.'
		);
		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) ),
			'Password removal after cleanup must not schedule an update for missing records.'
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
	 * Narrowing the supported post-type allowlist after publication must
	 * clean up existing remote records instead of leaving them live.
	 */
	public function test_publish_update_of_previously_synced_unsupported_post_schedules_delete() {
		$narrow = static function () {
			return array();
		};
		\add_filter( 'atmosphere_syncable_post_types', $narrow );

		try {
			$post = self::factory()->post->create_and_get(
				array(
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
			\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

			$this->reset_publishing_action();
			$this->atmosphere->on_status_change( 'publish', 'publish', $post );

			$this->assertFalse(
				\wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) ),
				'Unsupported post-type update must not schedule an update.'
			);
			$this->assertNotFalse(
				\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
				'Previously-synced unsupported post must schedule remote cleanup.'
			);
		} finally {
			\remove_filter( 'atmosphere_syncable_post_types', $narrow );
		}
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
	 * Restoring before the queued cleanup runs must update existing
	 * records. Publishing would attempt applyWrites#create for rkeys
	 * that are still live on the PDS.
	 */
	public function test_restore_before_cleanup_schedules_update_for_existing_records() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-tid-456' );
		\wp_schedule_single_event( \time(), 'atmosphere_delete_post', array( $post->ID ) );

		$this->reset_publishing_action();
		$this->atmosphere->on_status_change( 'publish', 'draft', $post );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'Restore with existing records must not schedule a fresh publish.'
		);
		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) ),
			'Restore with existing records must schedule an update.'
		);
		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Restore should clear stale queued cleanup.'
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
	 * Comments on non-public parent posts are skipped even when stale
	 * root URI/CID meta still exists.
	 */
	public function test_comment_on_password_protected_post_is_skipped() {
		$comment = $this->make_eligible_comment();
		\wp_update_post(
			array(
				'ID'            => (int) $comment->comment_post_ID,
				'post_password' => 'secret',
			)
		);

		$this->assertFalse( Atmosphere::should_publish_comment( \get_comment( $comment->comment_ID ) ) );
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
	 * Turning off outgoing reactions blocks every comment lifecycle
	 * scheduler, and the hard boundary cannot be bypassed by the legacy
	 * eligibility filter.
	 */
	public function test_disabled_outgoing_reactions_do_not_schedule_comment_writes() {
		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;
		\update_comment_meta( $comment_id, Comment::META_TID, 'reply-tid' );
		\update_comment_meta( $comment_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/reply-tid' );
		\update_option( 'atmosphere_publish_reactions', '' );
		\add_filter( 'atmosphere_should_publish_comment', '__return_true' );

		$this->assertFalse( Atmosphere::should_publish_comment( $comment ) );

		$this->atmosphere->on_comment_insert( $comment_id, 1 );
		$this->atmosphere->on_comment_edit( $comment_id );
		$this->atmosphere->on_comment_status_change( 'unapproved', 'approved', $comment );
		$this->atmosphere->on_comment_before_delete( $comment_id );

		$this->assertFalse( \wp_next_scheduled( 'atmosphere_publish_comment', array( $comment_id ) ) );
		$this->assertFalse( \wp_next_scheduled( 'atmosphere_update_comment', array( $comment_id ) ) );
		$this->assertFalse( \wp_next_scheduled( 'atmosphere_delete_comment', array( $comment_id ) ) );
		$this->assertFalse( \wp_next_scheduled( 'atmosphere_delete_comment_record', array( 'reply-tid' ) ) );
	}

	/**
	 * Events queued while the feature was enabled become no-ops when an
	 * administrator disables outgoing reactions before WP-Cron runs.
	 */
	public function test_queued_comment_cron_events_do_not_write_after_disable() {
		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;

		$this->atmosphere->on_comment_insert( $comment_id, 1 );
		\update_comment_meta( $comment_id, Comment::META_TID, 'reply-tid' );
		\update_comment_meta( $comment_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/reply-tid' );
		$this->atmosphere->on_comment_edit( $comment_id );
		$this->atmosphere->on_comment_status_change( 'unapproved', 'approved', $comment );
		$this->atmosphere->on_comment_before_delete( $comment_id );

		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_publish_comment', array( $comment_id ) ) );
		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_update_comment', array( $comment_id ) ) );
		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_delete_comment', array( $comment_id ) ) );
		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_delete_comment_record', array( 'reply-tid' ) ) );

		\update_option( 'atmosphere_publish_reactions', '' );

		$writes = 0;
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function () use ( &$writes ) {
				++$writes;
				return new \WP_Error( 'unexpected_write', 'Outgoing reaction reached the PDS write path.' );
			}
		);

		\do_action( 'atmosphere_publish_comment', $comment_id );
		\do_action( 'atmosphere_update_comment', $comment_id );
		\do_action( 'atmosphere_delete_comment', $comment_id );
		\do_action( 'atmosphere_delete_comment_record', 'reply-tid' );

		$this->assertSame( 0, $writes );
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
	 * The post update cron handler re-checks publishability at fire
	 * time. A post password-protected after scheduling must not update
	 * remote records with protected content; it schedules cleanup.
	 */
	public function test_update_post_cron_rechecks_password_protection() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		$captured_writes = array();
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function ( $short, $writes ) use ( &$captured_writes ) {
				$captured_writes = $writes;
				return array( 'results' => \array_fill( 0, \count( $writes ), array() ) );
			},
			10,
			2
		);

		\do_action( 'atmosphere_update_post', $post->ID );
		\remove_all_filters( 'atmosphere_pre_apply_writes' );

		$this->assertCount( 2, $captured_writes, 'Stale update cron must delete existing remote records immediately.' );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $captured_writes[0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $captured_writes[0]['collection'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $captured_writes[1]['$type'] );
		$this->assertSame( 'site.standard.document', $captured_writes[1]['collection'] );
		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_post', array( $post->ID ) ),
			'Stale update cron performs cleanup directly instead of deferring to another cron tick.'
		);
	}

	/**
	 * If cleanup succeeded before a stale update event fires, a restored
	 * post with the visibility-cleanup marker must publish fresh records.
	 */
	public function test_update_post_cron_publishes_when_cleanup_removed_records() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		Atmosphere::mark_visibility_cleanup( $post );

		$captured_writes = array();
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function ( $short, $writes ) use ( &$captured_writes ) {
				$captured_writes[] = $writes;

				return array(
					'results' => \array_map(
						static fn ( $write ) => array(
							'uri' => 'at://did:plc:test123/' . $write['collection'] . '/' . $write['rkey'],
							'cid' => 'bafy' . $write['rkey'],
						),
						$writes
					),
				);
			},
			10,
			2
		);

		\do_action( 'atmosphere_update_post', $post->ID );
		\remove_all_filters( 'atmosphere_pre_apply_writes' );

		$this->assertNotEmpty( $captured_writes, 'Stale update cron must write fresh records.' );
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $captured_writes[0][0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $captured_writes[0][0]['collection'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $captured_writes[0][1]['$type'] );
		$this->assertSame( 'site.standard.document', $captured_writes[0][1]['collection'] );
	}

	/**
	 * If a stale publish event fires for a post that already has live
	 * records, it must update them instead of trying applyWrites#create
	 * with existing rkeys.
	 */
	public function test_publish_post_cron_updates_when_records_already_exist() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);
		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/bsky-tid-123' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafyroot' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-tid-456' );

		$captured_writes = array();
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function ( $short, $writes ) use ( &$captured_writes ) {
				$captured_writes = $writes;
				return array( 'results' => \array_fill( 0, \count( $writes ), array() ) );
			},
			10,
			2
		);

		\do_action( 'atmosphere_publish_post', $post->ID );
		\remove_all_filters( 'atmosphere_pre_apply_writes' );

		$this->assertCount( 2, $captured_writes, 'Stale publish cron must update existing bsky and document records.' );
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $captured_writes[0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $captured_writes[0]['collection'] );
		$this->assertSame( 'bsky-tid-123', $captured_writes[0]['rkey'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $captured_writes[1]['$type'] );
		$this->assertSame( 'site.standard.document', $captured_writes[1]['collection'] );
		$this->assertSame( 'doc-tid-456', $captured_writes[1]['rkey'] );
	}

	/**
	 * If a stale delete event fires after the post has become public
	 * again, it must update the still-live records instead of removing
	 * them or trying to recreate them.
	 */
	public function test_delete_post_cron_updates_when_post_publishable_again() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);
		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/bsky-tid-123' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafyroot' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-tid-456' );

		$captured_writes = array();
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function ( $short, $writes ) use ( &$captured_writes ) {
				$captured_writes = $writes;
				return array( 'results' => \array_fill( 0, \count( $writes ), array() ) );
			},
			10,
			2
		);

		\do_action( 'atmosphere_delete_post', $post->ID );
		\remove_all_filters( 'atmosphere_pre_apply_writes' );

		$this->assertCount( 2, $captured_writes, 'Stale delete cron must update the existing bsky and document records.' );
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $captured_writes[0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $captured_writes[0]['collection'] );
		$this->assertSame( 'bsky-tid-123', $captured_writes[0]['rkey'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $captured_writes[1]['$type'] );
		$this->assertSame( 'site.standard.document', $captured_writes[1]['collection'] );
		$this->assertSame( 'doc-tid-456', $captured_writes[1]['rkey'] );
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
	 * Permanent post cleanup still removes the post and document while the
	 * outgoing-reaction switch keeps existing comment replies unchanged.
	 */
	public function test_on_before_delete_omits_comment_tids_when_outgoing_reactions_disabled() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, 'bsky-tid-root' );
		\update_post_meta( $post_id, Document::META_TID, 'doc-tid-root' );

		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		\update_comment_meta( $comment_id, Comment::META_TID, 'reply-tid' );
		\update_comment_meta( $comment_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/reply-tid' );
		\update_option( 'atmosphere_publish_reactions', '' );

		$this->atmosphere->on_before_delete( $post_id );

		$this->assertNotFalse(
			\wp_next_scheduled(
				'atmosphere_delete_records',
				array( array( 'bsky-tid-root' ), 'doc-tid-root', array() )
			),
			'Post cleanup should be queued without the comment-reply TID.'
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
	 * `Client::disconnect` preserves `atmosphere_identity` so the
	 * bidirectional verification headers (`.well-known/atproto-did`,
	 * publication link tag) keep serving after the OAuth session is
	 * cleared. Sites that adopted a custom domain handle depend on the
	 * well-known route to resolve their handle back to a DID during
	 * reconnect; wiping identity here would 404 the route and lock the
	 * user out of reconnecting with their domain handle (their entered
	 * handle resolves to nothing on DNS TXT and HTTPS well-known).
	 */
	public function test_disconnect_preserves_identity_for_handle_resolution() {
		$identity = array(
			'did'          => 'did:plc:testidentity1234567890',
			'handle'       => 'example.com',
			'pds_endpoint' => 'https://pds.example.com',
		);
		\update_option( 'atmosphere_identity', $identity, true );

		\Atmosphere\OAuth\Client::disconnect();

		$this->assertSame(
			$identity,
			\get_option( 'atmosphere_identity' ),
			'Client::disconnect must preserve atmosphere_identity so .well-known/atproto-did keeps serving and the user can reconnect with a custom domain handle.'
		);
	}

	/**
	 * `Client::disconnect` stamps the operator-initiated disconnect
	 * marker so the admin reauth notice can swap its copy. Without
	 * this, an intentional click on Disconnect would surface the same
	 * "session has expired" warning that fires for a permanent refresh
	 * failure — misleading copy for a state the user just chose.
	 */
	public function test_disconnect_sets_explicit_disconnect_marker() {
		// Pre-clear so the assertion below cannot pass against a marker
		// left over from a prior test (the suite shares process state
		// inside a single transaction, and a stale recent timestamp
		// would satisfy the lower-bound check even on a no-op).
		\delete_option( \Atmosphere\OAuth\Client::DISCONNECTED_OPTION );

		$before = \time();

		\Atmosphere\OAuth\Client::disconnect();

		$after  = \time();
		$marker = \get_option( \Atmosphere\OAuth\Client::DISCONNECTED_OPTION );

		$this->assertIsInt(
			$marker,
			'Client::disconnect must stamp the explicit-disconnect marker.'
		);
		$this->assertGreaterThanOrEqual(
			$before,
			$marker,
			'Marker timestamp should be no earlier than the disconnect call.'
		);
		$this->assertLessThanOrEqual(
			$after,
			$marker,
			'Marker timestamp should be no later than the disconnect call.'
		);
	}

	/**
	 * `Client::disconnect` sweeps the stale `atmosphere_publication_uri`
	 * row that 1.0.0 used to write. Nothing in production consumes the
	 * option (the well-known endpoint and Document transformer derive
	 * the URI from `get_did()` + the publication TID), but a leftover
	 * row on disconnected installs would still be confusing to operators
	 * inspecting the options table.
	 */
	public function test_disconnect_sweeps_stale_publication_uri_option() {
		\update_option(
			'atmosphere_publication_uri',
			'at://did:plc:old-owner/site.standard.publication/3kpubtid000000'
		);

		\Atmosphere\OAuth\Client::disconnect();

		$this->assertFalse(
			\get_option( 'atmosphere_publication_uri' ),
			'Client::disconnect must sweep the stale atmosphere_publication_uri row from 1.0.0 installs.'
		);
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
	 * If the kill switch changes while applyWrites is already in flight,
	 * retain the successful result instead of scheduling a new outbound
	 * delete that the disabled state is explicitly meant to prevent.
	 */
	public function test_reconcile_keeps_inflight_result_when_outgoing_reactions_disabled_mid_publish() {
		$comment    = $this->make_eligible_comment();
		$comment_id = (int) $comment->comment_ID;

		\add_filter(
			'atmosphere_pre_apply_writes',
			static function ( $short, $writes ) {
				\update_option( 'atmosphere_publish_reactions', '' );
				$rkey = $writes[0]['rkey'] ?? 'tid';

				return array(
					'results' => array(
						array(
							'uri' => 'at://did:plc:test123/app.bsky.feed.post/' . $rkey,
							'cid' => 'bafyreibinflight',
						),
					),
				);
			},
			10,
			2
		);

		\do_action( 'atmosphere_publish_comment', $comment_id );

		$this->assertNotEmpty( \get_comment_meta( $comment_id, Comment::META_TID, true ) );
		$this->assertNotEmpty( \get_comment_meta( $comment_id, Comment::META_URI, true ) );
		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_delete_comment_record' ),
			'Disabling outgoing writes must not enqueue a compensating delete.'
		);

		\remove_all_filters( 'atmosphere_pre_apply_writes' );
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

	/**
	 * Capture what `output_publication_link()` prints to stdout.
	 *
	 * @return string Output (empty when the method bails before emit).
	 */
	private function capture_publication_link(): string {
		\ob_start();
		$this->atmosphere->output_publication_link();
		return (string) \ob_get_clean();
	}

	/**
	 * Drive the WP query into a state where `is_singular()` resolves to
	 * a real post object — `go_to()` is the supported way to drop the
	 * unit-test request into the singular branch of template_redirect.
	 *
	 * @param int $post_id Post ID.
	 */
	private function go_to_post( int $post_id ): void {
		$this->go_to( (string) \get_permalink( $post_id ) );
		// Belt-and-suspenders: `is_singular()` reads from $wp_query.
		global $wp_query;
		$wp_query->queried_object    = \get_post( $post_id );
		$wp_query->queried_object_id = $post_id;
	}

	/**
	 * Drive the WP query into the front-page state.
	 */
	private function go_to_front_page(): void {
		$this->go_to( \home_url( '/' ) );
	}

	/**
	 * Capture what `output_document_link()` prints to stdout.
	 *
	 * @return string Output (empty when the method bails before emit).
	 */
	private function capture_document_link(): string {
		\ob_start();
		$this->atmosphere->output_document_link();
		return (string) \ob_get_clean();
	}

	/**
	 * Front-end endpoint query vars are registered through WordPress.
	 */
	public function test_register_query_vars_adds_atproto_preview_var() {
		$vars = $this->atmosphere->register_query_vars( array( 'p' ) );

		$this->assertContains( 'atproto', $vars );
		$this->assertContains( 'atmosphere_wellknown', $vars );
	}

	/**
	 * Document link emits for a previously-published post (META_URI on
	 * file) even with no live OAuth session. The verification link is
	 * the bidirectional anchor required by standard.site; it MUST keep
	 * serving across a transient refresh failure or an explicit
	 * disconnect so consumers do not lose the page <-> record binding.
	 */
	public function test_output_document_link_emits_for_published_post_with_meta_uri() {
		\update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://pds.example.com',
			),
			true
		);
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta(
			$post_id,
			\Atmosphere\Transformer\Post::META_URI,
			'at://did:plc:test123/app.bsky.feed.post/3krealrecord00'
		);

		$this->go_to_post( $post_id );
		$output = $this->capture_document_link();

		$this->assertStringContainsString(
			'<link rel="site.standard.document" href="at://did:plc:test123/site.standard.document/',
			$output,
			'A previously-published post must continue advertising its document link even after disconnect.'
		);
	}

	/**
	 * Document link stays silent for a post with no `META_URI` — the
	 * Publisher never wrote a record to the PDS, so advertising an
	 * AT-URI would point federation/discovery consumers at a 404. Lazy-
	 * minting META_TID via `Document::get_rkey()` during page render is
	 * specifically avoided here so a disconnected site does not seed
	 * non-existent records into its post meta. Pins the gate added in
	 * response to a Codex finding where preserved identity across
	 * disconnect would silently emit document links for unpublished
	 * posts.
	 */
	public function test_output_document_link_silent_for_unpublished_post_without_meta_uri() {
		\update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://pds.example.com',
			),
			true
		);
		\delete_option( 'atmosphere_connection' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		// No META_URI on the post — Publisher never ran.

		$this->go_to_post( $post_id );
		$output = $this->capture_document_link();

		$this->assertSame(
			'',
			$output,
			'A post that has never been published must not advertise a document link.'
		);
		$this->assertSame(
			'',
			\get_post_meta( $post_id, \Atmosphere\Transformer\Document::META_TID, true ),
			'Frontend render must not lazy-mint META_TID for an unpublished post.'
		);
	}

	/**
	 * Publication link tag fires on a singular publishable post whenever
	 * the site has minted its publication TID — that's the URL a third-
	 * party resolver would land on after following a permalink from a
	 * federated post, so they can find the parent publication without
	 * fetching the document first.
	 */
	public function test_output_publication_link_emits_on_singular_publishable_post() {
		\update_option( 'atmosphere_publication_tid', '3kpubtid000000' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to_post( $post_id );

		$output = $this->capture_publication_link();

		$this->assertStringContainsString(
			'<link rel="site.standard.publication" href="at://did:plc:test123/site.standard.publication/3kpubtid000000" />',
			$output
		);
	}

	/**
	 * Publication link tag fires on the WordPress front page, which is
	 * the local page represented by the normalized publication URL. Lets
	 * a resolver verify the page-to-publication binding by matching
	 * AT-URIs instead of round-tripping through `.well-known`.
	 */
	public function test_output_publication_link_emits_on_front_page() {
		\update_option( 'atmosphere_publication_tid', '3kpubtid000000' );
		$this->go_to_front_page();

		$output = $this->capture_publication_link();

		$this->assertStringContainsString(
			'<link rel="site.standard.publication" href="at://did:plc:test123/site.standard.publication/3kpubtid000000" />',
			$output
		);
	}

	/**
	 * Publication link tag fires on a static front page too — the
	 * common "Settings → Reading → A static page" configuration.
	 *
	 * `is_front_page()` and `is_singular('page')` are BOTH true in
	 * that scenario; the publishability gate would otherwise reject
	 * the request because `page` is not in the default supported
	 * post type list. The tag must still emit because the static page
	 * is the site's front page and therefore represents the normalized
	 * publication URL.
	 */
	public function test_output_publication_link_emits_on_static_front_page() {
		\update_option( 'atmosphere_publication_tid', '3kpubtid000000' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Home',
			)
		);
		\update_option( 'show_on_front', 'page' );
		\update_option( 'page_on_front', $page_id );

		// `?page_id=N` is the plain-permalink form WordPress uses to
		// reach a singular page; with `page_on_front` set above,
		// `WP_Query::is_front_page()` will recognise the queried page
		// and flip both `is_singular()` and `is_front_page()` on.
		$this->go_to( '?page_id=' . $page_id );

		$this->assertTrue( \is_front_page(), 'Sanity check: static page must be the front page.' );
		$this->assertTrue( \is_singular(), 'Sanity check: the static front page is also singular.' );

		$output = $this->capture_publication_link();

		\delete_option( 'show_on_front' );
		\delete_option( 'page_on_front' );

		$this->assertStringContainsString(
			'<link rel="site.standard.publication" href="at://did:plc:test123/site.standard.publication/3kpubtid000000" />',
			$output
		);
	}

	/**
	 * No emission when the site has not yet minted a publication TID
	 * (fresh install, pre-sync). Without a TID there is no AT-URI to
	 * point a resolver at, so emitting an empty `href` would be worse
	 * than silence.
	 */
	public function test_output_publication_link_bails_without_publication_tid() {
		\delete_option( 'atmosphere_publication_tid' );
		$this->go_to_front_page();

		$output = $this->capture_publication_link();

		$this->assertSame( '', $output );
	}

	/**
	 * No emission when the plugin is disconnected (no persisted
	 * identity). The gate mirrors {@see Atmosphere::output_document_link()}
	 * so the two link tags appear and disappear together.
	 */
	public function test_output_publication_link_bails_without_identity() {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\update_option( 'atmosphere_publication_tid', '3kpubtid000000' );
		$this->go_to_front_page();

		$output = $this->capture_publication_link();

		$this->assertSame( '', $output );
	}

	/**
	 * No emission on archive / category / search / 404 pages — only the
	 * front page or a publishable singular qualifies.
	 */
	public function test_output_publication_link_bails_on_non_singular_non_front_page() {
		\update_option( 'atmosphere_publication_tid', '3kpubtid000000' );
		$this->go_to( \home_url( '/?s=anything' ) );

		$output = $this->capture_publication_link();

		$this->assertSame( '', $output );
	}

	/**
	 * Singular posts that fail the publishability gate (e.g.
	 * password-protected) get no publication tag either — it would
	 * advertise a record we have not published and would not publish.
	 */
	public function test_output_publication_link_bails_on_non_publishable_singular() {
		\update_option( 'atmosphere_publication_tid', '3kpubtid000000' );
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$this->go_to_post( $post_id );

		$output = $this->capture_publication_link();

		$this->assertSame( '', $output );
	}

	/**
	 * Every trigger that ought to schedule a publication re-sync is
	 * wired up by `Atmosphere::init()`.
	 *
	 * The publication record bakes in WordPress's site identity (name,
	 * description, icon, home URL) and the active theme's primary
	 * colours; changing any of those sources without a re-sync would
	 * leave the record on the PDS stale until the next unrelated
	 * event happened to re-publish it. This test pins the full
	 * trigger set so a future refactor can't silently drop one.
	 */
	public function test_init_wires_publication_sync_triggers() {
		$triggers = array(
			'update_option_blogname',
			'update_option_blogdescription',
			'update_option_site_icon',
			'update_option_home',
			'update_option_siteurl',
			'switch_theme',
			'save_post_wp_global_styles',
			'customize_save_after',
		);

		$this->atmosphere->init();

		try {
			foreach ( $triggers as $trigger ) {
				$this->assertNotFalse(
					\has_action( $trigger, array( $this->atmosphere, 'schedule_publication_sync' ) ),
					"{$trigger} must be wired to schedule_publication_sync."
				);
			}
		} finally {
			/*
			 * `init()` writes to global hook + cron state that the WP test
			 * framework does not roll back between tests. Roll back the
			 * pieces our action triggers so a later test changing
			 * `blogname` / `home` / etc. is not surprised by a spurious
			 * `atmosphere_sync_publication` schedule, and clear the two
			 * recurring crons `init()` queues so they do not survive
			 * either.
			 */
			foreach ( $triggers as $trigger ) {
				\remove_action( $trigger, array( $this->atmosphere, 'schedule_publication_sync' ) );
			}
			\wp_clear_scheduled_hook( 'atmosphere_sync_publication' );
			\wp_clear_scheduled_hook( 'atmosphere_refresh_token' );
			\wp_clear_scheduled_hook( 'atmosphere_sync_reactions' );
		}
	}

	/**
	 * Reply to a local-only parent comment (anonymous, never-published)
	 * must not be published to the PDS. The previous behaviour would
	 * silently demote the reply to a top-level post on the parent
	 * article's bsky record after the deferral cap, losing the WP
	 * thread context.
	 */
	public function test_publish_comment_skipped_when_parent_is_local_only() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/postroot' );
		\update_post_meta( $post_id, Post::META_CID, 'bafypostroot' );

		// Anonymous parent — `user_id = 0` makes the parent permanently
		// ineligible for outbound publish, so it will never gain a
		// bsky URI.
		$parent_id = self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post_id,
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
				'user_id'              => 0,
				'comment_author'       => 'Anon',
				'comment_author_email' => 'anon@example.com',
				'comment_content'      => 'Anonymous comment.',
			)
		);

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'comment_type'     => 'comment',
				'user_id'          => self::factory()->user->create(),
				'comment_content'  => 'Reply to anon.',
				'comment_parent'   => $parent_id,
			)
		);
		// Seed a non-empty deferral counter so the post-skip assertion
		// distinguishes "the cron handler cleared it" from "no value
		// was ever written" — comment meta defaults to '' otherwise
		// and the assertion would be trivially true.
		\update_comment_meta( $child_id, '_atmosphere_publish_attempts', 2 );

		$apply_writes_calls = 0;
		\add_filter(
			'atmosphere_pre_apply_writes',
			function () use ( &$apply_writes_calls ) {
				++$apply_writes_calls;
				return array(
					'results' => array(
						array(
							'uri' => 'at://x',
							'cid' => 'bafyx',
						),
					),
				);
			},
			10,
			2
		);

		\do_action( 'atmosphere_publish_comment', $child_id );

		$this->assertSame( 0, $apply_writes_calls, 'No PDS call should be attempted when parent has no bsky representation.' );
		$this->assertSame( '', (string) \get_comment_meta( $child_id, Comment::META_URI, true ), 'Child must not gain a bsky URI.' );
		$this->assertSame( '', (string) \get_comment_meta( $child_id, '_atmosphere_publish_attempts', true ), 'Deferral counter must be cleared on skip.' );
	}

	/**
	 * A reply whose parent comment was previously published to the PDS
	 * (carries `Comment::META_URI`) IS published — that's the happy
	 * thread-on-bsky path.
	 */
	public function test_publish_comment_proceeds_when_parent_has_bsky_uri() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/postroot' );
		\update_post_meta( $post_id, Post::META_CID, 'bafypostroot' );

		$parent_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'comment_type'     => 'comment',
				'user_id'          => self::factory()->user->create(),
				'comment_content'  => 'Already-published parent.',
			)
		);
		\update_comment_meta( $parent_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/parent' );
		\update_comment_meta( $parent_id, Comment::META_CID, 'bafyparent' );

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'comment_type'     => 'comment',
				'user_id'          => self::factory()->user->create(),
				'comment_content'  => 'Reply.',
				'comment_parent'   => $parent_id,
			)
		);

		$apply_writes_calls = 0;
		\add_filter(
			'atmosphere_pre_apply_writes',
			function ( $short, $writes ) use ( &$apply_writes_calls ) {
				++$apply_writes_calls;
				$results = array();
				foreach ( $writes as $write ) {
					$results[] = array(
						'uri' => 'at://did:plc:test123/' . ( $write['collection'] ?? 'app.bsky.feed.post' ) . '/' . ( $write['rkey'] ?? 'tid' ),
						'cid' => 'bafychild',
					);
				}
				return array( 'results' => $results );
			},
			10,
			2
		);

		\do_action( 'atmosphere_publish_comment', $child_id );

		$this->assertSame( 1, $apply_writes_calls, 'Publish should proceed when parent has a bsky URI.' );
	}

	/**
	 * A reply whose parent was imported from bsky (Reaction_Sync stamps
	 * `META_PROTOCOL = atproto`) IS published — its bsky strongRef is
	 * available even though the parent comment was never published
	 * outbound by this site.
	 */
	public function test_publish_comment_proceeds_when_parent_was_imported_from_bsky() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/postroot' );
		\update_post_meta( $post_id, Post::META_CID, 'bafypostroot' );

		// Imported-from-bsky parent: no user_id (federation imports
		// typically write `user_id = 0`), but `META_PROTOCOL = atproto`
		// signals "this row has a bsky URI we can thread under".
		$parent_id = self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post_id,
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
				'user_id'              => 0,
				'comment_author'       => 'Federated User',
				'comment_author_email' => 'fed@example.com',
				'comment_content'      => 'Imported reply.',
			)
		);
		\update_comment_meta( $parent_id, Reaction_Sync::META_PROTOCOL, 'atproto' );
		\update_comment_meta( $parent_id, Reaction_Sync::META_SOURCE_ID, 'at://did:plc:other/app.bsky.feed.post/fedparent' );
		\update_comment_meta( $parent_id, Reaction_Sync::META_BSKY_CID, 'bafyfedparent' );

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'comment_type'     => 'comment',
				'user_id'          => self::factory()->user->create(),
				'comment_content'  => 'Reply to federated.',
				'comment_parent'   => $parent_id,
			)
		);

		$apply_writes_calls = 0;
		\add_filter(
			'atmosphere_pre_apply_writes',
			function ( $short, $writes ) use ( &$apply_writes_calls ) {
				++$apply_writes_calls;
				$results = array();
				foreach ( $writes as $write ) {
					$results[] = array(
						'uri' => 'at://did:plc:test123/' . ( $write['collection'] ?? 'app.bsky.feed.post' ) . '/' . ( $write['rkey'] ?? 'tid' ),
						'cid' => 'bafychild',
					);
				}
				return array( 'results' => $results );
			},
			10,
			2
		);

		\do_action( 'atmosphere_publish_comment', $child_id );

		$this->assertSame( 1, $apply_writes_calls, 'Publish should proceed when parent was imported from bsky.' );
	}

	/**
	 * A top-level comment (no `comment_parent`) always proceeds —
	 * the post's own bsky record is the thread root, no per-comment
	 * parent check applies.
	 */
	public function test_publish_comment_proceeds_for_top_level_comment() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/postroot' );
		\update_post_meta( $post_id, Post::META_CID, 'bafypostroot' );

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'comment_type'     => 'comment',
				'user_id'          => self::factory()->user->create(),
				'comment_content'  => 'Top-level comment.',
			)
		);

		$apply_writes_calls = 0;
		\add_filter(
			'atmosphere_pre_apply_writes',
			function ( $short, $writes ) use ( &$apply_writes_calls ) {
				++$apply_writes_calls;
				$results = array();
				foreach ( $writes as $write ) {
					$results[] = array(
						'uri' => 'at://did:plc:test123/' . ( $write['collection'] ?? 'app.bsky.feed.post' ) . '/' . ( $write['rkey'] ?? 'tid' ),
						'cid' => 'bafytop',
					);
				}
				return array( 'results' => $results );
			},
			10,
			2
		);

		\do_action( 'atmosphere_publish_comment', $comment_id );

		$this->assertSame( 1, $apply_writes_calls, 'Top-level comment publish should proceed unconditionally.' );
	}

	/**
	 * Half-state guard: a parent that has `Comment::META_URI` but no
	 * matching `META_CID` must NOT count as having a bsky
	 * representation. `Comment::resolve_parent_ref()` requires both
	 * fields for the reply strongRef and would otherwise fall back to
	 * the post root — reintroducing the top-level-fallback bug the
	 * cron-handler gate is here to prevent. Symmetric check on the
	 * federated path (atproto protocol flag without `META_BSKY_CID`)
	 * is exercised by the existing tests via shared code paths.
	 */
	public function test_publish_comment_skipped_when_parent_has_uri_but_no_cid() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/postroot' );
		\update_post_meta( $post_id, Post::META_CID, 'bafypostroot' );

		$parent_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'comment_type'     => 'comment',
				'user_id'          => self::factory()->user->create(),
				'comment_content'  => 'Half-published parent.',
			)
		);
		// URI present but CID missing — a half-state row that would
		// previously slip past the parent_has_bsky_representation
		// gate yet still fall back to root in build_reply_ref().
		\update_comment_meta( $parent_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/parent' );

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'comment_type'     => 'comment',
				'user_id'          => self::factory()->user->create(),
				'comment_content'  => 'Reply to half-state.',
				'comment_parent'   => $parent_id,
			)
		);

		$apply_writes_calls = 0;
		\add_filter(
			'atmosphere_pre_apply_writes',
			function () use ( &$apply_writes_calls ) {
				++$apply_writes_calls;
				return array(
					'results' => array(
						array(
							'uri' => 'at://x',
							'cid' => 'bafyx',
						),
					),
				);
			},
			10,
			2
		);

		\do_action( 'atmosphere_publish_comment', $child_id );

		$this->assertSame( 0, $apply_writes_calls, 'Publish must be skipped when parent has URI but no CID.' );
	}

	/**
	 * The `atmosphere_url` REST field exposes the bsky.app web URL for a
	 * shared post (built from the stored AT-URI), and is empty before the
	 * post has been shared.
	 */
	public function test_atmosphere_url_rest_field() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( '', $this->rest_get_atmosphere_url( $post_id ) );

		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:abc123/app.bsky.feed.post/3kabc' );

		$this->assertSame(
			'https://bsky.app/profile/did:plc:abc123/post/3kabc',
			$this->rest_get_atmosphere_url( $post_id )
		);
	}

	/**
	 * Fetch a post's `atmosphere_url` REST field in the edit context.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function rest_get_atmosphere_url( int $post_id ): string {
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );

		return (string) ( \rest_do_request( $request )->get_data()['atmosphere_url'] ?? '' );
	}

	/**
	 * Short-circuit applyWrites with a transient (retryable) PDS error.
	 *
	 * @param int $status HTTP status carried in the error data.
	 */
	private function force_apply_writes_error( int $status ): void {
		\add_filter(
			'atmosphere_pre_apply_writes',
			static fn() => new \WP_Error(
				'atmosphere_pds',
				'PDS request failed.',
				array( 'status' => $status )
			)
		);
	}

	/**
	 * Short-circuit applyWrites with a plausible per-write success response.
	 */
	private function force_apply_writes_success(): void {
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function ( $short_circuit, array $writes ) {
				$results = array();
				foreach ( $writes as $write ) {
					$results[] = array(
						'uri' => 'at://did:plc:test123/' . ( $write['collection'] ?? 'app.bsky.feed.post' ) . '/' . ( $write['rkey'] ?? 'rk' ),
						'cid' => 'bafyreib' . \substr( \md5( (string) \wp_json_encode( $write ) ), 0, 20 ),
					);
				}

				return array( 'results' => $results );
			},
			10,
			2
		);
	}

	/**
	 * A transient PDS failure (5xx) in the publish worker schedules a
	 * delayed retry of the same hook and records the attempt.
	 */
	public function test_publish_worker_schedules_backoff_retry_on_transient_pds_error() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );
		\wp_clear_scheduled_hook( 'atmosphere_update_post', array( $post->ID ) );

		$this->force_apply_writes_error( 500 );

		\do_action( 'atmosphere_publish_post', $post->ID );

		$next = \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) );

		$this->assertNotFalse( $next, 'Expected a retry to be scheduled after a transient PDS error.' );
		$this->assertGreaterThanOrEqual( \time() + 45, $next, 'First retry should back off by about a minute.' );
		$this->assertLessThanOrEqual( \time() + 75, $next, 'First retry should back off by about a minute.' );
		$this->assertSame( '1', \get_post_meta( $post->ID, '_atmosphere_publish_retries', true ) );
	}

	/**
	 * The retry delay escalates with the attempt count.
	 */
	public function test_publish_worker_backoff_escalates_with_attempts() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post->ID, '_atmosphere_publish_retries', '1' );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );
		\wp_clear_scheduled_hook( 'atmosphere_update_post', array( $post->ID ) );

		$this->force_apply_writes_error( 500 );

		\do_action( 'atmosphere_publish_post', $post->ID );

		$next = \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) );

		$this->assertNotFalse( $next, 'Expected a second retry to be scheduled.' );
		$this->assertGreaterThanOrEqual( \time() + 4 * MINUTE_IN_SECONDS, $next, 'Second retry should back off by about five minutes.' );
		$this->assertSame( '2', \get_post_meta( $post->ID, '_atmosphere_publish_retries', true ) );
	}

	/**
	 * A permanent PDS rejection (plain 4xx) is not retried.
	 */
	public function test_publish_worker_does_not_retry_permanent_pds_error() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );
		\wp_clear_scheduled_hook( 'atmosphere_update_post', array( $post->ID ) );

		$this->force_apply_writes_error( 400 );

		\do_action( 'atmosphere_publish_post', $post->ID );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'A permanent PDS rejection must not schedule a retry.'
		);
		$this->assertSame( '', \get_post_meta( $post->ID, '_atmosphere_publish_retries', true ) );
	}

	/**
	 * Retries stop after the ladder is exhausted, and the counter resets
	 * so a later fresh save starts a new ladder.
	 */
	public function test_publish_worker_gives_up_after_max_retries() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post->ID, '_atmosphere_publish_retries', '3' );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );
		\wp_clear_scheduled_hook( 'atmosphere_update_post', array( $post->ID ) );

		$this->force_apply_writes_error( 500 );

		\do_action( 'atmosphere_publish_post', $post->ID );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'No retry may be scheduled once the ladder is exhausted.'
		);
		$this->assertSame( '', \get_post_meta( $post->ID, '_atmosphere_publish_retries', true ) );

		$error = \get_post_meta( $post->ID, '_atmosphere_last_publish_error', true );

		$this->assertIsArray( $error, 'Exhausting the ladder must persist the final error.' );
		$this->assertFalse( $error['retrying'], 'No further attempts are queued after exhaustion.' );
	}

	/**
	 * A successful publish clears a leftover retry counter.
	 */
	public function test_publish_worker_success_clears_retry_counter() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post->ID, '_atmosphere_publish_retries', '2' );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );
		\wp_clear_scheduled_hook( 'atmosphere_update_post', array( $post->ID ) );

		$this->force_apply_writes_success();

		\do_action( 'atmosphere_publish_post', $post->ID );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'A successful publish must not schedule a retry.'
		);
		$this->assertSame( '', \get_post_meta( $post->ID, '_atmosphere_publish_retries', true ) );
	}

	/**
	 * The update worker retries transient failures the same way,
	 * rescheduling its own hook.
	 */
	public function test_update_worker_schedules_backoff_retry_on_transient_pds_error() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta(
			$post->ID,
			Post::META_THREAD_RECORDS,
			array(
				array(
					'uri' => 'at://did:plc:test123/app.bsky.feed.post/bsky-tid-123',
					'cid' => 'bafyreiboldcid',
					'tid' => 'bsky-tid-123',
				),
			)
		);
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-tid-456' );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );
		\wp_clear_scheduled_hook( 'atmosphere_update_post', array( $post->ID ) );

		$this->force_apply_writes_error( 503 );

		\do_action( 'atmosphere_update_post', $post->ID );

		$next = \wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) );

		$this->assertNotFalse( $next, 'Expected the update worker to schedule a retry after a transient PDS error.' );
		$this->assertSame( '1', \get_post_meta( $post->ID, '_atmosphere_publish_retries', true ) );
	}

	/**
	 * Returning an empty ladder from the delays filter disables
	 * retries entirely.
	 */
	public function test_publish_retry_delays_filter_disables_retries() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );

		\add_filter( 'atmosphere_publish_retry_delays', '__return_empty_array' );
		$this->force_apply_writes_error( 500 );

		\do_action( 'atmosphere_publish_post', $post->ID );

		\remove_filter( 'atmosphere_publish_retry_delays', '__return_empty_array' );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'An empty delays ladder must disable retries.'
		);
		$this->assertSame( '', \get_post_meta( $post->ID, '_atmosphere_publish_retries', true ) );
	}

	/**
	 * A longer filtered ladder raises the retry budget: the ladder's
	 * length is the maximum attempt count.
	 */
	public function test_publish_retry_delays_filter_extends_ladder() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post->ID, '_atmosphere_publish_retries', '3' );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );

		$ladder = static fn() => array( 10, 20, 30, 40 );
		\add_filter( 'atmosphere_publish_retry_delays', $ladder );
		$this->force_apply_writes_error( 500 );

		\do_action( 'atmosphere_publish_post', $post->ID );

		\remove_filter( 'atmosphere_publish_retry_delays', $ladder );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'A fourth attempt must be scheduled when the filtered ladder has four rungs.'
		);
		$this->assertSame( '4', \get_post_meta( $post->ID, '_atmosphere_publish_retries', true ) );
	}

	/**
	 * A failed thread rollback leaves live orphan records on the PDS;
	 * an automatic retry would mint fresh TIDs and publish a duplicate
	 * copy next to them. That failure must never be retried.
	 */
	public function test_publish_worker_does_not_retry_after_failed_thread_rollback() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );

		\add_filter(
			'atmosphere_pre_apply_writes',
			static fn() => new \WP_Error(
				'atmosphere_thread_rollback_failed',
				'Thread publish failed and rollback also failed.',
				array( 'partial_records' => array() )
			)
		);

		\do_action( 'atmosphere_publish_post', $post->ID );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'A failed rollback must not be retried — live orphans plus a retry means duplicate posts.'
		);
		$this->assertSame( '', \get_post_meta( $post->ID, '_atmosphere_publish_retries', true ) );
	}

	/**
	 * A fresh user-intent status transition starts a new retry budget:
	 * a counter stranded by a dead retry event (disconnect, trash, lost
	 * cron) must not shrink the next ladder.
	 */
	public function test_status_transition_resets_retry_counter() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		\update_post_meta( $post->ID, '_atmosphere_publish_retries', '2' );
		\update_post_meta(
			$post->ID,
			'_atmosphere_last_publish_error',
			array(
				'code'     => 'atmosphere_pds',
				'message'  => 'boom',
				'retrying' => false,
				'time'     => \time(),
			)
		);

		\wp_publish_post( $post->ID );

		$this->assertSame(
			'',
			\get_post_meta( $post->ID, '_atmosphere_publish_retries', true ),
			'Publishing a post must reset any stranded retry counter.'
		);
		$this->assertSame(
			'',
			\get_post_meta( $post->ID, '_atmosphere_last_publish_error', true ),
			'A status transition is fresh intent — the stale error record must go too.'
		);
	}

	/**
	 * A failed publish attempt persists a per-post error record so the
	 * editor can surface it — with the retrying flag set while the
	 * backoff ladder is still running.
	 */
	public function test_publish_worker_records_error_meta_with_retrying_flag() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );

		$this->force_apply_writes_error( 500 );

		\do_action( 'atmosphere_publish_post', $post->ID );

		$error = \get_post_meta( $post->ID, '_atmosphere_last_publish_error', true );

		$this->assertIsArray( $error, 'A failed publish must persist a per-post error record.' );
		$this->assertSame( 'atmosphere_pds', $error['code'] );
		$this->assertNotSame( '', $error['message'] );
		$this->assertTrue( $error['retrying'], 'The ladder scheduled a retry, so the record must say so.' );
		$this->assertIsInt( $error['time'] );
	}

	/**
	 * A permanent rejection persists the error with retrying=false so
	 * the editor can tell the author a re-save is needed.
	 */
	public function test_publish_worker_records_error_meta_without_retry_on_permanent_error() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );

		$this->force_apply_writes_error( 400 );

		\do_action( 'atmosphere_publish_post', $post->ID );

		$error = \get_post_meta( $post->ID, '_atmosphere_last_publish_error', true );

		$this->assertIsArray( $error );
		$this->assertFalse( $error['retrying'], 'A permanent rejection is not retried.' );
	}

	/**
	 * A successful publish clears a leftover error record.
	 */
	public function test_publish_worker_success_clears_error_meta() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		\update_post_meta(
			$post->ID,
			'_atmosphere_last_publish_error',
			array(
				'code'     => 'atmosphere_pds',
				'message'  => 'boom',
				'retrying' => true,
				'time'     => \time(),
			)
		);

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );

		$this->force_apply_writes_success();

		\do_action( 'atmosphere_publish_post', $post->ID );

		$this->assertSame(
			'',
			\get_post_meta( $post->ID, '_atmosphere_last_publish_error', true ),
			'A successful publish must clear the error record.'
		);
	}

	/**
	 * The `atmosphere_publish_error` REST field exposes the stored error
	 * in the edit context, and null when there is none.
	 */
	public function test_atmosphere_publish_error_rest_field() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertNull( $this->rest_get_publish_error( $post_id ), 'No error stored — the field must be null.' );

		\update_post_meta(
			$post_id,
			'_atmosphere_last_publish_error',
			array(
				'code'     => 'atmosphere_pds',
				'message'  => 'Upstream Failure',
				'retrying' => true,
				'time'     => 1234567890,
			)
		);

		$error = $this->rest_get_publish_error( $post_id );

		$this->assertIsArray( $error );
		$this->assertSame( 'atmosphere_pds', $error['code'] );
		$this->assertSame( 'Upstream Failure', $error['message'] );
		$this->assertTrue( $error['retrying'] );
	}

	/**
	 * `needs_reconnect` reflects the live connection: a reconnect-class
	 * stored error raises it only while the site is still disconnected,
	 * so a stale per-post error cannot keep telling the author to
	 * reconnect after the operator already has.
	 */
	public function test_publish_error_needs_reconnect_drops_after_reconnect() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\update_post_meta(
			$post_id,
			'_atmosphere_last_publish_error',
			array(
				'code'     => 'atmosphere_needs_reauth',
				'message'  => 'Session expired',
				'retrying' => false,
				'time'     => 1234567890,
			)
		);

		/* The failure state: the connection is flagged for reauth. */
		$conn                 = \get_option( 'atmosphere_connection' );
		$conn['needs_reauth'] = true;
		\update_option( 'atmosphere_connection', $conn, false );

		$error = $this->rest_get_publish_error( $post_id );

		$this->assertTrue(
			$error['needs_reconnect'],
			'A reconnect-class error on a disconnected site must raise needs_reconnect.'
		);
		$this->assertSame(
			'Session expired',
			$error['message'],
			'While disconnected, the stored reconnect instruction is accurate and must surface.'
		);

		/* The operator reconnected: flag cleared, token present again. */
		$conn['needs_reauth'] = false;
		\update_option( 'atmosphere_connection', $conn, false );

		$error = $this->rest_get_publish_error( $post_id );

		$this->assertFalse(
			$error['needs_reconnect'],
			'Once the site is reconnected the stale error must not claim otherwise.'
		);
		$this->assertSame(
			'',
			$error['message'],
			'The stored reconnect instruction is stale prose once reconnected and must be suppressed.'
		);
	}

	/**
	 * The stored message is stripped of markup and truncated: PDS error
	 * strings are attacker-influenced and end up rendered in the editor.
	 */
	public function test_publish_error_message_is_sanitized_and_truncated() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );

		\add_filter(
			'atmosphere_pre_apply_writes',
			static fn() => new \WP_Error(
				'atmosphere_pds',
				'<script>alert(1)</script>' . \str_repeat( 'A', 400 ),
				array( 'status' => 500 )
			)
		);

		\do_action( 'atmosphere_publish_post', $post->ID );

		$error = \get_post_meta( $post->ID, '_atmosphere_last_publish_error', true );

		$this->assertIsArray( $error );
		$this->assertStringNotContainsString( '<', $error['message'], 'Markup must be stripped before storage.' );
		$this->assertLessThanOrEqual( 300, \mb_strlen( $error['message'] ), 'The stored message must be truncated.' );
	}

	/**
	 * The delete worker's reconcile-to-publishable branch republished the
	 * post successfully — the stale failure record must not survive it.
	 */
	public function test_delete_worker_reconcile_success_clears_error_meta() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta(
			$post->ID,
			Post::META_THREAD_RECORDS,
			array(
				array(
					'uri' => 'at://did:plc:test123/app.bsky.feed.post/bsky-tid-123',
					'cid' => 'bafyreiboldcid',
					'tid' => 'bsky-tid-123',
				),
			)
		);
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-tid-456' );
		\update_post_meta(
			$post->ID,
			'_atmosphere_last_publish_error',
			array(
				'code'     => 'atmosphere_pds',
				'message'  => 'boom',
				'retrying' => false,
				'time'     => \time(),
			)
		);

		/*
		 * WP-Cron unschedules an event before running its callback; mirror
		 * that so the creation-time event doesn't mask the retry.
		 */
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post->ID ) );

		$this->force_apply_writes_success();

		\do_action( 'atmosphere_delete_post', $post->ID );

		$this->assertSame(
			'',
			\get_post_meta( $post->ID, '_atmosphere_last_publish_error', true ),
			'A successful reconcile-republish must clear the stale failure record.'
		);
	}

	/**
	 * Decrypt failures are deterministic — the ciphertext can never be
	 * read until the user reconnects — so neither classification may
	 * enter the retry ladder.
	 */
	public function test_decrypt_failures_are_not_retried() {
		$reflection = new \ReflectionClass( Atmosphere::class );
		$method     = $reflection->getMethod( 'is_transient_publish_error' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( null, new \WP_Error( 'atmosphere_decrypt', 'nope' ) ) );
		$this->assertFalse( $method->invoke( null, new \WP_Error( 'atmosphere_key_changed', 'nope' ) ) );
	}

	/**
	 * Fetch a post's `atmosphere_publish_error` REST field in the edit context.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	private function rest_get_publish_error( int $post_id ): ?array {
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );

		$data = \rest_do_request( $request )->get_data();

		return $data['atmosphere_publish_error'] ?? null;
	}
}
