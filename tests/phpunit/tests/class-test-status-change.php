<?php
/**
 * Tests for status change handling in the Atmosphere class.
 *
 * Verifies that post status transitions schedule the correct
 * async hooks (publish, update, delete) and that non-publish
 * transitions do not accidentally delete AT Protocol records.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group status-change
 */

namespace Atmosphere\Tests;

use WP_UnitTestCase;
use Atmosphere\Atmosphere;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Document;

/**
 * Status change tests.
 */
class Test_Status_Change extends WP_UnitTestCase {

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

		// Simulate a connected state.
		\update_option(
			'atmosphere_connection',
			array(
				'access_token' => 'encrypted-token',
				'did'          => 'did:plc:test123',
			)
		);
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );

		// Clear any scheduled events.
		\wp_clear_scheduled_hook( 'atmosphere_publish_post' );
		\wp_clear_scheduled_hook( 'atmosphere_update_post' );
		\wp_clear_scheduled_hook( 'atmosphere_delete_post' );
		\wp_clear_scheduled_hook( 'atmosphere_delete_records' );

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
				array( 'bsky-tid-123', 'doc-tid-456' )
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
}
