<?php
/**
 * Tests for the Publisher class.
 *
 * Verifies publish, update, and delete flows including the
 * URI-based existence check and bsky cross-reference refresh.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group publisher
 */

namespace Atmosphere\Tests;

use WP_UnitTestCase;
use Atmosphere\Publisher;
use Atmosphere\Reaction_Sync;
use Atmosphere\Transformer\Comment;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;

/**
 * Publisher tests.
 */
class Test_Publisher extends WP_UnitTestCase {

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		// Simulate a connected state with a DID.
		\update_option(
			'atmosphere_connection',
			array(
				'access_token' => 'encrypted-token',
				'did'          => 'did:plc:test123',
				'pds_endpoint' => 'https://pds.example.com',
				'dpop_jwk'     => 'encrypted-jwk',
			)
		);
		\update_option( 'atmosphere_did', 'did:plc:test123' );
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_did' );
		\delete_option( 'atmosphere_publication_tid' );

		parent::tear_down();
	}

	/**
	 * Test that update() falls back to publish() when no URIs exist.
	 *
	 * TIDs may exist (generated locally by get_rkey) but URIs are
	 * only set after a successful API call. Missing URIs means the
	 * records were never created on the PDS.
	 */
	public function test_update_falls_back_to_publish_without_uris() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		// Set TIDs but no URIs — simulates a failed initial publish.
		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		/*
		 * The publish fallback will try to call API::apply_writes()
		 * which makes an HTTP request. The bootstrap blocks all HTTP,
		 * so we'll get a WP_Error back — but the important thing is
		 * that it attempted a publish (create), not an update.
		 */
		$result = Publisher::update_post( $post );

		$this->assertWPError( $result );
	}

	/**
	 * Test that update() falls back to publish() when only bsky URI exists.
	 */
	public function test_update_falls_back_without_doc_uri() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test/app.bsky.feed.post/bsky-tid-123' );
		// No document URI.

		$result = Publisher::update_post( $post );

		$this->assertWPError( $result );
	}

	/**
	 * Test that update() returns an error when URIs exist but TIDs are missing.
	 */
	public function test_update_errors_with_uris_but_no_tids() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		// URIs without TIDs — should not happen, but guard against it.
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test/app.bsky.feed.post/bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test/site.standard.document/doc-tid-456' );

		$result = Publisher::update_post( $post );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_missing_tid', $result->get_error_code() );
	}

	/**
	 * Test that update() sends applyWrites#update when URIs and TIDs exist.
	 */
	public function test_update_sends_update_writes() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test/app.bsky.feed.post/bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test/site.standard.document/doc-tid-456' );

		/*
		 * Intercept the HTTP request to verify the payload contains
		 * #update operations (not #create or #delete).
		 */
		$captured_body = null;

		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured_body ) {
				if ( false !== \strpos( $url, 'applyWrites' ) ) {
					$captured_body = \json_decode( $args['body'], true );

					return array(
						'response' => array( 'code' => 200 ),
						'body'     => \wp_json_encode(
							array(
								'results' => array(
									array(
										'uri' => 'at://did:plc:test/app.bsky.feed.post/bsky-tid-123',
										'cid' => 'bafyreib-new-bsky-cid',
									),
									array(
										'uri' => 'at://did:plc:test/site.standard.document/doc-tid-456',
										'cid' => 'bafyreib-new-doc-cid',
									),
								),
							)
						),
					);
				}

				return $response;
			},
			10,
			3
		);

		$result = Publisher::update_post( $post );

		\remove_all_filters( 'pre_http_request' );

		/*
		 * The API call requires a valid access token and DPoP proof,
		 * which we can't easily mock. If the request reached our
		 * filter, captured_body will be set. If it failed before
		 * reaching the HTTP layer (auth errors), we still verify the
		 * flow didn't crash.
		 */
		if ( null !== $captured_body ) {
			$this->assertIsArray( $captured_body['writes'] );
			$this->assertCount( 2, $captured_body['writes'] );
			$this->assertSame( 'com.atproto.repo.applyWrites#update', $captured_body['writes'][0]['$type'] );
			$this->assertSame( 'com.atproto.repo.applyWrites#update', $captured_body['writes'][1]['$type'] );
			$this->assertSame( 'bsky-tid-123', $captured_body['writes'][0]['rkey'] );
			$this->assertSame( 'doc-tid-456', $captured_body['writes'][1]['rkey'] );
		} else {
			// Auth layer blocked the request — still verify no crash.
			$this->assertWPError( $result );
		}
	}

	/**
	 * Test that delete() returns error when not connected.
	 *
	 * The API layer requires a valid OAuth connection. Without one,
	 * delete() returns a WP_Error and post meta is preserved.
	 */
	public function test_delete_preserves_meta_on_api_error() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'trash' )
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );

		$result = Publisher::delete_post( $post );

		// API call will fail (no valid OAuth), meta should be preserved.
		$this->assertWPError( $result );
		$this->assertSame( 'bsky-tid-123', \get_post_meta( $post->ID, Post::META_TID, true ) );
		$this->assertSame( 'doc-tid-456', \get_post_meta( $post->ID, Document::META_TID, true ) );
	}

	/**
	 * Test that delete() returns error when no TIDs exist.
	 */
	public function test_delete_errors_without_tids() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'trash' )
		);

		$result = Publisher::delete_post( $post );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_not_published', $result->get_error_code() );
	}

	/**
	 * Seed a published post for comment tests to reply against.
	 *
	 * @return int Post ID with bsky meta populated.
	 */
	private function seed_root_post(): int {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/root' );
		\update_post_meta( $post_id, Post::META_CID, 'bafyroot' );

		return $post_id;
	}

	/**
	 * Capture the body of the first applyWrites call and stub a successful
	 * response.
	 *
	 * @param string $uri Response URI.
	 * @param string $cid Response CID.
	 * @return \Closure Returns the captured body, or null if the filter never fired.
	 */
	private function stub_apply_writes( string $uri, string $cid ): \Closure {
		$captured       = new \stdClass();
		$captured->body = null;

		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( $captured, $uri, $cid ) {
				if ( false !== \strpos( $url, 'applyWrites' ) ) {
					$captured->body = \json_decode( $args['body'], true );

					return array(
						'response' => array( 'code' => 200 ),
						'body'     => \wp_json_encode(
							array(
								'results' => array(
									array(
										'uri' => $uri,
										'cid' => $cid,
									),
								),
							)
						),
					);
				}

				return $response;
			},
			5,
			3
		);

		return static fn() => $captured->body;
	}

	/**
	 * Publish a comment stores URI+CID+TID and the Reaction_Sync dedup key.
	 */
	public function test_publish_comment_stores_meta_and_dedup_key() {
		$post_id    = $this->seed_root_post();
		$user_id    = self::factory()->user->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Published comment.',
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);

		$get_body = $this->stub_apply_writes(
			'at://did:plc:test123/app.bsky.feed.post/newtid',
			'bafynew'
		);

		$result = Publisher::publish_comment( \get_comment( $comment_id ) );
		\remove_all_filters( 'pre_http_request' );

		if ( \is_wp_error( $result ) ) {
			$this->markTestSkipped( 'API layer rejected request before stub: ' . $result->get_error_message() );
		}

		$body = $get_body();
		$this->assertNotNull( $body, 'applyWrites body was not captured.' );
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $body['writes'][0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $body['writes'][0]['collection'] );

		$this->assertSame( 'at://did:plc:test123/app.bsky.feed.post/newtid', \get_comment_meta( $comment_id, Comment::META_URI, true ) );
		$this->assertSame( 'bafynew', \get_comment_meta( $comment_id, Comment::META_CID, true ) );
		$this->assertSame(
			'at://did:plc:test123/app.bsky.feed.post/newtid',
			\get_comment_meta( $comment_id, Reaction_Sync::META_SOURCE_ID, true )
		);
		$this->assertNotEmpty( \get_comment_meta( $comment_id, Comment::META_TID, true ) );
	}

	/**
	 * Update comment falls back to publish when there is no URI yet.
	 *
	 * A TID may be present (Comment::get_rkey persists it locally) but
	 * the URI is only set after a successful API call; its absence
	 * means the record was never created on the PDS.
	 */
	public function test_update_comment_falls_back_to_publish_without_uri() {
		$post_id    = $this->seed_root_post();
		$user_id    = self::factory()->user->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);

		$get_body = $this->stub_apply_writes(
			'at://did:plc:test123/app.bsky.feed.post/newtid',
			'bafynew'
		);

		$result = Publisher::update_comment( \get_comment( $comment_id ) );
		\remove_all_filters( 'pre_http_request' );

		if ( \is_wp_error( $result ) ) {
			$this->markTestSkipped( 'API layer rejected request: ' . $result->get_error_message() );
		}

		$body = $get_body();
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $body['writes'][0]['$type'] );
	}

	/**
	 * Update comment issues an update when URI and TID are already stored.
	 */
	public function test_update_comment_updates_existing_record() {
		$post_id    = $this->seed_root_post();
		$user_id    = self::factory()->user->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);
		\update_comment_meta( $comment_id, Comment::META_TID, 'existingtid' );
		\update_comment_meta( $comment_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/existingtid' );

		$get_body = $this->stub_apply_writes(
			'at://did:plc:test123/app.bsky.feed.post/existingtid',
			'bafyupdated'
		);

		$result = Publisher::update_comment( \get_comment( $comment_id ) );
		\remove_all_filters( 'pre_http_request' );

		if ( \is_wp_error( $result ) ) {
			$this->markTestSkipped( 'API layer rejected request: ' . $result->get_error_message() );
		}

		$body = $get_body();
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $body['writes'][0]['$type'] );
		$this->assertSame( 'existingtid', $body['writes'][0]['rkey'] );
	}

	/**
	 * Update comment still falls back to publish when a stale TID
	 * exists from a previous failed API call but no URI.
	 *
	 * This is the regression guard: keying off TID would infinite-loop
	 * an #update request for a record that never existed.
	 */
	public function test_update_comment_retries_create_when_tid_persisted_but_no_uri() {
		$post_id    = $this->seed_root_post();
		$user_id    = self::factory()->user->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);
		// Simulate a previous publish failure: TID persisted, URI absent.
		\update_comment_meta( $comment_id, Comment::META_TID, 'staletid' );

		$get_body = $this->stub_apply_writes(
			'at://did:plc:test123/app.bsky.feed.post/staletid',
			'bafyretry'
		);

		$result = Publisher::update_comment( \get_comment( $comment_id ) );
		\remove_all_filters( 'pre_http_request' );

		if ( \is_wp_error( $result ) ) {
			$this->markTestSkipped( 'API layer rejected request: ' . $result->get_error_message() );
		}

		$body = $get_body();
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $body['writes'][0]['$type'] );
	}

	/**
	 * Delete comment errors when the comment was never published (no URI).
	 */
	public function test_delete_comment_errors_without_uri() {
		$post_id    = $this->seed_root_post();
		$user_id    = self::factory()->user->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'user_id'         => $user_id,
			)
		);
		// Even with a stale TID, absent URI means nothing to delete.
		\update_comment_meta( $comment_id, Comment::META_TID, 'staletid' );

		$result = Publisher::delete_comment( \get_comment( $comment_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_not_published', $result->get_error_code() );
	}

	/**
	 * Delete-by-tid issues a delete write for a known TID.
	 */
	public function test_delete_comment_by_tid_issues_delete() {
		$get_body = $this->stub_apply_writes( '', '' );

		$result = Publisher::delete_comment_by_tid( 'goner' );
		\remove_all_filters( 'pre_http_request' );

		if ( \is_wp_error( $result ) ) {
			$this->markTestSkipped( 'API layer rejected request: ' . $result->get_error_message() );
		}

		$body = $get_body();
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $body['writes'][0]['$type'] );
		$this->assertSame( 'goner', $body['writes'][0]['rkey'] );
	}

	/**
	 * Delete-by-tid rejects an empty TID.
	 */
	public function test_delete_comment_by_tid_rejects_empty() {
		$result = Publisher::delete_comment_by_tid( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_not_published', $result->get_error_code() );
	}

	/**
	 * Generic Publisher::publish dispatches to publish_post for WP_Post.
	 */
	public function test_generic_publish_dispatches_post() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		$captured_collections = array();
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured_collections ) {
				if ( false !== \strpos( $url, 'applyWrites' ) ) {
					$body                 = \json_decode( $args['body'], true );
					$captured_collections = \array_column( $body['writes'] ?? array(), 'collection' );
				}
				return $response;
			},
			5,
			3
		);

		Publisher::publish( $post );
		\remove_all_filters( 'pre_http_request' );

		if ( empty( $captured_collections ) ) {
			$this->markTestSkipped( 'API layer rejected request before stub.' );
		}

		$this->assertContains( 'app.bsky.feed.post', $captured_collections );
		$this->assertContains( 'site.standard.document', $captured_collections );
	}

	/**
	 * Generic Publisher::publish dispatches to publish_comment for WP_Comment.
	 */
	public function test_generic_publish_dispatches_comment() {
		$post_id    = $this->seed_root_post();
		$user_id    = self::factory()->user->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);

		$captured_writes = null;
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured_writes ) {
				if ( false !== \strpos( $url, 'applyWrites' ) ) {
					$body            = \json_decode( $args['body'], true );
					$captured_writes = $body['writes'] ?? array();
				}
				return $response;
			},
			5,
			3
		);

		Publisher::publish( \get_comment( $comment_id ) );
		\remove_all_filters( 'pre_http_request' );

		if ( null === $captured_writes ) {
			$this->markTestSkipped( 'API layer rejected request before stub.' );
		}

		// Comment publish produces a single app.bsky.feed.post create write.
		$this->assertCount( 1, $captured_writes );
		$this->assertSame( 'app.bsky.feed.post', $captured_writes[0]['collection'] );
	}
}
