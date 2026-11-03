<?php
/**
 * Tests for the reasons recorded when a post does not reach Bluesky.
 *
 * A failed publish attempt already stores its error. These tests cover the
 * cases that used to leave nothing behind: the post was skipped because the
 * login needed reconnecting, the publish job was queued but never ran, and
 * the job started but never finished. Each one must surface through
 * `Atmosphere::get_publish_error()`, which backs both the posts-list column
 * and the editor panel.
 *
 * @package Atmosphere
 * @group atmosphere
 */

namespace Atmosphere\Tests;

use Atmosphere\Atmosphere;
use Atmosphere\OAuth\Client;
use Atmosphere\Transformer\Post;
use Atmosphere\WP_Admin\Post_List;

/**
 * Share diagnostics tests.
 */
class Test_Share_Diagnostics extends \WP_UnitTestCase {

	/**
	 * Plugin instance under test.
	 *
	 * @var Atmosphere
	 */
	private Atmosphere $atmosphere;

	/**
	 * Start every test from a healthy connection.
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
	 * Remove connection state and queued events.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( Client::DISCONNECTED_OPTION );

		\wp_unschedule_hook( 'atmosphere_publish_post' );
		\wp_unschedule_hook( 'atmosphere_update_post' );
		\wp_unschedule_hook( 'atmosphere_delete_post' );

		parent::tear_down();
	}

	/**
	 * Flag the stored session as needing a reconnect, the state a rejected
	 * login renewal leaves behind.
	 */
	private function lose_connection(): void {
		$conn                 = \get_option( 'atmosphere_connection' );
		$conn['needs_reauth'] = true;
		\update_option( 'atmosphere_connection', $conn );
	}

	/**
	 * Run a publish → publish save the way core does: the status change,
	 * then the end of the save, where held-back skips are recorded.
	 *
	 * @param \WP_Post $post Post being saved.
	 */
	private function save_while_disconnected( \WP_Post $post ): void {
		$this->atmosphere->on_status_change( 'publish', 'publish', $post );
		Atmosphere::record_skips_after_save( $post->ID );
	}

	/**
	 * A published post with no Bluesky history, and no queued events.
	 *
	 * @return \WP_Post
	 */
	private function unshared_published_post(): \WP_Post {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		\wp_unschedule_hook( 'atmosphere_publish_post' );
		\delete_post_meta( $post->ID, '_atmosphere_share_pending' );

		return $post;
	}

	/**
	 * Short-circuit applyWrites with a plausible success response.
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
	 * Publishing while the login needs reconnecting records why the post
	 * was not shared, instead of leaving no trace.
	 */
	public function test_publishing_while_connection_is_lost_records_a_skip_reason() {
		$this->lose_connection();

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post_id ) ),
			'Nothing can be published while the connection is lost.'
		);

		$error = Atmosphere::get_publish_error( $post_id );

		$this->assertIsArray( $error, 'The skip must leave a reason behind.' );
		$this->assertSame( 'atmosphere_share_skipped_disconnected', $error['code'] );
		$this->assertNotSame( '', $error['message'] );
		$this->assertFalse( $error['retrying'] );
		$this->assertTrue( $error['needs_reconnect'], 'While still disconnected, reconnecting is the fix.' );
	}

	/**
	 * Publishing from the block editor with sharing switched off in the same
	 * save is not a missed share. REST saves the toggle after the status
	 * change, so the skip is only decided once the save is complete.
	 */
	public function test_publishing_with_sharing_switched_off_in_the_same_save_records_nothing() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->lose_connection();

		// Registered meta is reset between tests; the toggle needs it to save over REST.
		$this->atmosphere->register_share_meta();

		$fired = 0;
		\add_action(
			'atmosphere_publish_post_skipped',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			array(
				'status' => 'publish',
				'meta'   => array( ATMOSPHERE_META_DISABLED => true ),
			)
		);
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', \get_post_meta( $post_id, ATMOSPHERE_META_DISABLED, true ), 'The toggle must have been saved.' );
		$this->assertSame( 0, $fired );
		$this->assertNull( Atmosphere::get_publish_error( $post_id ) );
	}

	/**
	 * A skip reason stops showing once the post is switched off, since it
	 * no longer owes a share.
	 */
	public function test_skip_reason_is_dropped_once_sharing_is_switched_off() {
		$this->lose_connection();

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );
		\update_post_meta( $post_id, ATMOSPHERE_META_DISABLED, '1' );

		$this->assertNull( Atmosphere::get_publish_error( $post_id ) );
	}

	/**
	 * The skip reason stays readable after the site reconnects, which is
	 * when the author needs it: the reconnect notice is gone by then.
	 */
	public function test_skip_reason_survives_reconnecting() {
		$this->lose_connection();

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		$conn = \get_option( 'atmosphere_connection' );
		unset( $conn['needs_reauth'] );
		\update_option( 'atmosphere_connection', $conn );

		$error = Atmosphere::get_publish_error( $post_id );

		$this->assertIsArray( $error );
		$this->assertNotSame( '', $error['message'], 'The reason must still read after reconnecting.' );
		$this->assertFalse( $error['needs_reconnect'] );
	}

	/**
	 * A skip fires an action, so a site can send its own alert.
	 */
	public function test_skip_fires_an_action() {
		$this->lose_connection();

		$seen = array();
		\add_action(
			'atmosphere_publish_post_skipped',
			static function ( $post, $reason ) use ( &$seen ) {
				$seen[] = array( $post, $reason );
			},
			10,
			2
		);

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		$this->assertCount( 1, $seen );
		$this->assertInstanceOf( \WP_Post::class, $seen[0][0] );
		$this->assertSame( $post_id, $seen[0][0]->ID );
		$this->assertInstanceOf( \WP_Error::class, $seen[0][1] );
		$this->assertSame( 'atmosphere_share_skipped_disconnected', $seen[0][1]->get_error_code() );
	}

	/**
	 * Saving a skipped post again while still disconnected keeps the first
	 * record and does not alert again.
	 */
	public function test_repeated_saves_while_disconnected_record_and_alert_once() {
		$this->lose_connection();

		$fired = 0;
		\add_action(
			'atmosphere_publish_post_skipped',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );
		$first = \get_post_meta( $post_id, '_atmosphere_last_publish_error', true );

		$this->save_while_disconnected( \get_post( $post_id ) );
		$this->save_while_disconnected( \get_post( $post_id ) );

		$this->assertSame( 1, $fired );
		$this->assertSame( $first, \get_post_meta( $post_id, '_atmosphere_last_publish_error', true ) );
	}

	/**
	 * An earlier failure is a more specific reason than the disconnect, so a
	 * save while disconnected leaves it in place.
	 */
	public function test_skip_does_not_replace_an_earlier_failure() {
		$post  = $this->unshared_published_post();
		$prior = array(
			'code'     => 'atmosphere_pds',
			'message'  => 'Upstream failure',
			'retrying' => false,
			'time'     => \time() - HOUR_IN_SECONDS,
		);
		\update_post_meta( $post->ID, '_atmosphere_last_publish_error', $prior );
		$this->lose_connection();

		$this->save_while_disconnected( $post );

		$this->assertSame( $prior, \get_post_meta( $post->ID, '_atmosphere_last_publish_error', true ) );
	}

	/**
	 * A site that was never connected has nothing to report.
	 */
	public function test_never_connected_site_records_nothing() {
		\delete_option( 'atmosphere_connection' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		$this->assertNull( Atmosphere::get_publish_error( $post_id ) );
	}

	/**
	 * A deliberate disconnect is the operator's choice, not a failure, so
	 * posts published afterwards are not flagged.
	 */
	public function test_operator_disconnect_records_nothing() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\delete_option( 'atmosphere_connection' );
		\update_option( Client::DISCONNECTED_OPTION, \time() );

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		$this->assertNull( Atmosphere::get_publish_error( $post_id ) );
	}

	/**
	 * With automatic sharing off, nothing was going to be shared.
	 */
	public function test_skip_not_recorded_when_auto_publish_is_off() {
		$this->lose_connection();
		\add_filter( 'atmosphere_should_auto_publish', '__return_false' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		$this->assertNull( Atmosphere::get_publish_error( $post_id ) );
	}

	/**
	 * A post switched off in the editor was not meant to be shared.
	 */
	public function test_skip_not_recorded_when_sharing_is_off_for_the_post() {
		$this->lose_connection();

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\update_post_meta( $post_id, ATMOSPHERE_META_DISABLED, '1' );
		\wp_publish_post( $post_id );

		$this->assertNull( Atmosphere::get_publish_error( $post_id ) );
	}

	/**
	 * Editing a published post that predates the plugin is not a missed
	 * share; recording one would fill old posts with noise.
	 */
	public function test_skip_not_recorded_when_editing_a_post_with_no_history() {
		$post = $this->unshared_published_post();
		$this->lose_connection();

		$this->save_while_disconnected( $post );

		$this->assertNull( Atmosphere::get_publish_error( $post->ID ) );
	}

	/**
	 * Editing an already shared post while disconnected is an update, not
	 * a first share. The post keeps its link, so no reason is recorded.
	 */
	public function test_skip_not_recorded_for_a_post_already_on_bluesky() {
		$post = $this->unshared_published_post();
		\update_post_meta( $post->ID, Post::META_TID, 'rkey789' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/rkey789' );
		$this->lose_connection();

		$this->save_while_disconnected( $post );

		$this->assertSame( '', \get_post_meta( $post->ID, '_atmosphere_last_publish_error', true ) );
	}

	/**
	 * Once reconnected, saving a post that was skipped shares it, so the
	 * editor's "update the post to try again" is true.
	 */
	public function test_updating_a_skipped_post_after_reconnecting_shares_it() {
		$this->lose_connection();
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		$conn = \get_option( 'atmosphere_connection' );
		unset( $conn['needs_reauth'] );
		\update_option( 'atmosphere_connection', $conn );

		$this->atmosphere->on_status_change( 'publish', 'publish', \get_post( $post_id ) );

		$this->assertNotFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post_id ) ),
			'The save must queue a first share, not an update the worker would skip.'
		);
		$this->assertFalse( \wp_next_scheduled( 'atmosphere_update_post', array( $post_id ) ) );
	}

	/**
	 * Saving a post whose share is still waiting for its job queues a share
	 * rather than an update, since there is nothing to update yet.
	 */
	public function test_updating_a_post_with_a_pending_share_queues_a_share() {
		$post = $this->unshared_published_post();
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'queued',
				'time'  => \time() - HOUR_IN_SECONDS,
			)
		);

		$this->atmosphere->on_status_change( 'publish', 'publish', $post );

		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
		$this->assertFalse( \wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) ) );
	}

	/**
	 * A save made while the first share's job is running does not queue a
	 * second share, which would race the first for the same record key. It
	 * gets an update instead, as it would on trunk.
	 */
	public function test_saving_while_the_first_share_runs_queues_an_update() {
		$post = $this->unshared_published_post();
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'running',
				'time'  => \time(),
			)
		);

		$this->atmosphere->on_status_change( 'publish', 'publish', $post );

		$this->assertFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) ) );
	}

	/**
	 * A save after a share died part-way queues it again, which is what the
	 * editor's "update the post to try again" promises.
	 */
	public function test_saving_after_an_interrupted_share_queues_it_again() {
		$post = $this->unshared_published_post();
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'running',
				'time'  => \time() - HOUR_IN_SECONDS,
			)
		);

		$this->atmosphere->on_status_change( 'publish', 'publish', $post );

		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
	}

	/**
	 * WP-Cron removes an event just before running its job, so a share
	 * queued moments ago with no event left is starting, not lost: a save
	 * then must not queue a second job next to it.
	 */
	public function test_saving_while_the_first_share_is_starting_queues_an_update() {
		$post = $this->unshared_published_post();
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'queued',
				'time'  => \time(),
			)
		);

		$this->atmosphere->on_status_change( 'publish', 'publish', $post );

		$this->assertFalse( \wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ) );
		$this->assertNotFalse( \wp_next_scheduled( 'atmosphere_update_post', array( $post->ID ) ) );
	}

	/**
	 * The update job skips a post with no Bluesky history and reports that
	 * as success. That must not wipe the marker of a first share still
	 * owed, or the post would never be shared nor explained.
	 */
	public function test_update_job_skip_keeps_the_first_share_marker() {
		$post   = $this->unshared_published_post();
		$marker = array(
			'state' => 'running',
			'time'  => \time(),
		);
		\update_post_meta( $post->ID, '_atmosphere_share_pending', $marker );

		\do_action( 'atmosphere_update_post', $post->ID );

		$this->assertSame( $marker, \get_post_meta( $post->ID, '_atmosphere_share_pending', true ) );
	}

	/**
	 * A save does not add a second publish job next to one already waiting,
	 * such as a pending retry; the waiting one reads the post when it runs.
	 */
	public function test_saving_does_not_queue_alongside_a_waiting_publish_job() {
		$post  = $this->unshared_published_post();
		$retry = \time() + 15 * MINUTE_IN_SECONDS;
		\wp_schedule_single_event( $retry, 'atmosphere_publish_post', array( $post->ID ) );
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'queued',
				'time'  => \time(),
			)
		);

		$this->atmosphere->on_status_change( 'publish', 'publish', $post );

		$this->assertSame(
			$retry,
			\wp_next_scheduled( 'atmosphere_publish_post', array( $post->ID ) ),
			'Only the waiting job may be queued.'
		);
		$this->assertTrue( Atmosphere::is_share_pending( $post->ID ) );
	}

	/**
	 * Sharing from the posts list replaces an old reason with "Sharing…".
	 */
	public function test_manual_share_clears_an_earlier_reason() {
		$this->lose_connection();
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		$conn = \get_option( 'atmosphere_connection' );
		unset( $conn['needs_reauth'] );
		\update_option( 'atmosphere_connection', $conn );

		$this->assertTrue( Atmosphere::queue_post_share( $post_id ) );
		$this->assertNull( Atmosphere::get_publish_error( $post_id ) );
		$this->assertTrue( Atmosphere::is_share_pending( $post_id ) );
	}

	/**
	 * A share already queued when the connection dropped runs anyway and
	 * records its own outcome, so a save in between records no skip.
	 */
	public function test_skip_not_recorded_while_a_publish_job_is_queued() {
		$post = $this->unshared_published_post();
		\wp_schedule_single_event( \time(), 'atmosphere_publish_post', array( $post->ID ) );
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'queued',
				'time'  => \time(),
			)
		);
		$this->lose_connection();

		$this->save_while_disconnected( $post );

		$this->assertSame( '', \get_post_meta( $post->ID, '_atmosphere_last_publish_error', true ) );
	}

	/**
	 * Publishing marks the share as queued.
	 */
	public function test_publishing_marks_the_share_as_queued() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		$pending = \get_post_meta( $post_id, '_atmosphere_share_pending', true );

		$this->assertIsArray( $pending );
		$this->assertSame( 'queued', $pending['state'] );
		$this->assertTrue( Atmosphere::is_share_pending( $post_id ) );
	}

	/**
	 * The manual share action marks the share as queued too.
	 */
	public function test_manual_share_marks_the_share_as_queued() {
		$post = $this->unshared_published_post();

		$this->assertTrue( Atmosphere::queue_post_share( $post->ID ) );

		$pending = \get_post_meta( $post->ID, '_atmosphere_share_pending', true );
		$this->assertSame( 'queued', $pending['state'] );
	}

	/**
	 * Unpublishing drops a pending marker: nothing is owed any more.
	 */
	public function test_unpublishing_clears_the_pending_marker() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		\wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( '', \get_post_meta( $post_id, '_atmosphere_share_pending', true ) );
	}

	/**
	 * A revision's own status change must not touch its parent's marker:
	 * `delete_post_meta()` maps a revision ID to the parent post.
	 */
	public function test_revision_transition_keeps_the_parent_marker() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		$revision = self::factory()->post->create_and_get(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post_id,
				'post_name'   => $post_id . '-revision-v1',
			)
		);

		$this->atmosphere->on_status_change( 'inherit', 'new', $revision );

		$this->assertIsArray( \get_post_meta( $post_id, '_atmosphere_share_pending', true ) );
	}

	/**
	 * Disconnecting or deactivating removes the queued publish events, so
	 * their markers go too; otherwise those posts would blame WP-Cron.
	 */
	public function test_clearing_scheduled_hooks_drops_pending_markers() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		\Atmosphere\clear_scheduled_hooks();

		$this->assertSame( '', \get_post_meta( $post_id, '_atmosphere_share_pending', true ) );
		$this->assertNull( Atmosphere::get_publish_error( $post_id ) );
	}

	/**
	 * The publish job also runs as an update for a post already on
	 * Bluesky; that is not a first share, so it is not tracked.
	 */
	public function test_publish_worker_does_not_track_a_post_already_on_bluesky() {
		$post = $this->unshared_published_post();
		\update_post_meta( $post->ID, Post::META_TID, 'rkey789' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/rkey789' );

		$seen = 'unset';
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function () use ( $post, &$seen ) {
				$seen = \get_post_meta( $post->ID, '_atmosphere_share_pending', true );

				return new \WP_Error( 'atmosphere_pds', 'PDS request failed.', array( 'status' => 400 ) );
			}
		);
		\do_action( 'atmosphere_publish_post', $post->ID );

		$this->assertSame( '', $seen );
	}

	/**
	 * A finished job removes the marker, whatever the outcome.
	 */
	public function test_publish_worker_clears_the_marker_on_success() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post_id ) );

		$this->force_apply_writes_success();
		\do_action( 'atmosphere_publish_post', $post_id );

		$this->assertSame( '', \get_post_meta( $post_id, '_atmosphere_share_pending', true ) );
		$this->assertNull( Atmosphere::get_publish_error( $post_id ) );
	}

	/**
	 * A failed job also removes the marker; its stored error says why.
	 */
	public function test_publish_worker_clears_the_marker_on_failure() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post_id ) );

		\add_filter(
			'atmosphere_pre_apply_writes',
			static fn() => new \WP_Error( 'atmosphere_pds', 'PDS request failed.', array( 'status' => 400 ) )
		);
		\do_action( 'atmosphere_publish_post', $post_id );

		$this->assertSame( '', \get_post_meta( $post_id, '_atmosphere_share_pending', true ) );
		$this->assertSame( 'atmosphere_pds', Atmosphere::get_publish_error( $post_id )['code'] );
	}

	/**
	 * A job that dies mid-run leaves the marker in its running state.
	 */
	public function test_publish_worker_marks_the_share_as_running() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );
		\wp_clear_scheduled_hook( 'atmosphere_publish_post', array( $post_id ) );

		$state = null;
		\add_filter(
			'atmosphere_pre_apply_writes',
			static function () use ( $post_id, &$state ) {
				$state = \get_post_meta( $post_id, '_atmosphere_share_pending', true )['state'] ?? null;

				return new \WP_Error( 'atmosphere_pds', 'PDS request failed.', array( 'status' => 400 ) );
			}
		);
		\do_action( 'atmosphere_publish_post', $post_id );

		$this->assertSame( 'running', $state );
	}

	/**
	 * A queued share whose event is gone never ran.
	 */
	public function test_lost_event_reports_that_sharing_did_not_run() {
		$post = $this->unshared_published_post();
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'queued',
				'time'  => \time() - HOUR_IN_SECONDS,
			)
		);

		$error = Atmosphere::get_publish_error( $post->ID );

		$this->assertIsArray( $error );
		$this->assertSame( 'atmosphere_share_not_run', $error['code'] );
		$this->assertNotSame( '', $error['message'] );
		$this->assertFalse( $error['retrying'], 'The event is gone; a save queues it again.' );
		$this->assertFalse( Atmosphere::is_share_pending( $post->ID ) );
	}

	/**
	 * WP-Cron removes an event just before running it, so a marker that was
	 * just queued and has no event is a job starting, not a lost one.
	 */
	public function test_fresh_queued_marker_without_its_event_is_pending() {
		$post = $this->unshared_published_post();
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'queued',
				'time'  => \time(),
			)
		);

		$this->assertNull( Atmosphere::get_publish_error( $post->ID ) );
		$this->assertTrue( Atmosphere::is_share_pending( $post->ID ) );
	}

	/**
	 * A queued share whose event is long overdue has not run either: cron
	 * is not firing on this site.
	 */
	public function test_overdue_event_reports_that_sharing_did_not_run() {
		$post = $this->unshared_published_post();
		\wp_schedule_single_event( \time() - 2 * HOUR_IN_SECONDS, 'atmosphere_publish_post', array( $post->ID ) );
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'queued',
				'time'  => \time(),
			)
		);

		$error = Atmosphere::get_publish_error( $post->ID );

		$this->assertSame( 'atmosphere_share_not_run', $error['code'], 'Judged by the event, not the marker.' );
		$this->assertTrue( $error['retrying'], 'The event is still queued; saving would not help.' );
	}

	/**
	 * An event a quarter of an hour late is normal on a host that runs
	 * WP-Cron from a system cron every 15 minutes, so it is not a failure.
	 */
	public function test_event_late_by_one_system_cron_interval_is_still_pending() {
		$post = $this->unshared_published_post();
		$late = \time() - 15 * MINUTE_IN_SECONDS;
		\wp_schedule_single_event( $late, 'atmosphere_publish_post', array( $post->ID ) );
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'queued',
				'time'  => $late,
			)
		);

		$this->assertNull( Atmosphere::get_publish_error( $post->ID ) );
		$this->assertTrue( Atmosphere::is_share_pending( $post->ID ) );
	}

	/**
	 * A share waiting on its event is pending, not failed.
	 */
	public function test_queued_share_with_its_event_is_pending() {
		$post = $this->unshared_published_post();
		\wp_schedule_single_event( \time(), 'atmosphere_publish_post', array( $post->ID ) );
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'queued',
				'time'  => \time(),
			)
		);

		$this->assertNull( Atmosphere::get_publish_error( $post->ID ) );
		$this->assertTrue( Atmosphere::is_share_pending( $post->ID ) );
	}

	/**
	 * A job that started a while ago and never cleared its marker died
	 * part-way through.
	 */
	public function test_stale_running_marker_reports_an_interrupted_share() {
		$post = $this->unshared_published_post();
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'running',
				'time'  => \time() - HOUR_IN_SECONDS,
			)
		);

		$error = Atmosphere::get_publish_error( $post->ID );

		$this->assertSame( 'atmosphere_share_interrupted', $error['code'] );
		$this->assertNotSame( '', $error['message'] );
		$this->assertFalse( Atmosphere::is_share_pending( $post->ID ) );
	}

	/**
	 * A job that just started is still in progress.
	 */
	public function test_fresh_running_marker_is_pending() {
		$post = $this->unshared_published_post();
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'running',
				'time'  => \time(),
			)
		);

		$this->assertNull( Atmosphere::get_publish_error( $post->ID ) );
		$this->assertTrue( Atmosphere::is_share_pending( $post->ID ) );
	}

	/**
	 * A post that made it to Bluesky never reports a stalled share, even if
	 * a marker was left behind.
	 */
	public function test_leftover_marker_is_ignored_once_the_post_is_shared() {
		$post = $this->unshared_published_post();
		\update_post_meta( $post->ID, Post::META_TID, 'rkey789' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/rkey789' );
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'running',
				'time'  => \time() - HOUR_IN_SECONDS,
			)
		);

		$this->assertNull( Atmosphere::get_publish_error( $post->ID ) );
		$this->assertFalse( Atmosphere::is_share_pending( $post->ID ) );
	}

	/**
	 * A stored error from an actual attempt wins over the marker.
	 */
	public function test_stored_error_takes_precedence_over_the_marker() {
		$post = $this->unshared_published_post();
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'running',
				'time'  => \time() - HOUR_IN_SECONDS,
			)
		);
		\update_post_meta(
			$post->ID,
			'_atmosphere_last_publish_error',
			array(
				'code'     => 'atmosphere_pds',
				'message'  => 'Upstream failure',
				'retrying' => true,
				'time'     => \time(),
			)
		);

		$this->assertSame( 'atmosphere_pds', Atmosphere::get_publish_error( $post->ID )['code'] );
	}

	/**
	 * A malformed marker is treated as absent.
	 */
	public function test_malformed_marker_is_ignored() {
		$post = $this->unshared_published_post();
		\update_post_meta( $post->ID, '_atmosphere_share_pending', 'queued' );

		$this->assertNull( Atmosphere::get_publish_error( $post->ID ) );
		$this->assertFalse( Atmosphere::is_share_pending( $post->ID ) );
	}

	/**
	 * The posts list says a share is under way instead of showing a dash.
	 */
	public function test_post_list_column_shows_a_pending_share() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post = $this->unshared_published_post();
		\wp_schedule_single_event( \time(), 'atmosphere_publish_post', array( $post->ID ) );
		\update_post_meta(
			$post->ID,
			'_atmosphere_share_pending',
			array(
				'state' => 'queued',
				'time'  => \time(),
			)
		);

		\ob_start();
		Post_List::render_column( Post_List::COLUMN, $post->ID );
		$html = \ob_get_clean();

		$this->assertStringContainsString( 'Sharing', $html );
		$this->assertStringNotContainsString( '&mdash;', $html );
	}

	/**
	 * The posts list shows why a share did not happen.
	 */
	public function test_post_list_column_shows_a_skip_reason() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->lose_connection();

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\wp_publish_post( $post_id );

		\ob_start();
		Post_List::render_column( Post_List::COLUMN, $post_id );
		$html = \ob_get_clean();

		$this->assertStringContainsString(
			\esc_html( Atmosphere::get_publish_error( $post_id )['message'] ),
			$html
		);
	}
}
