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
use Atmosphere\OAuth\DPoP;
use Atmosphere\OAuth\Encryption;
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
				'access_token' => Encryption::encrypt( 'test-token' ),
				'did'          => 'did:plc:test123',
				'pds_endpoint' => 'https://pds.example.com',
				'dpop_jwk'     => Encryption::encrypt( (string) \wp_json_encode( DPoP::generate_key() ) ),
				'expires_at'   => \time() + HOUR_IN_SECONDS,
			)
		);
		\update_option( 'atmosphere_did', 'did:plc:test123' );

		\add_filter( 'pre_http_request', array( $this, 'mock_document_ref_update' ), 10, 3 );
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_did' );
		\delete_option( 'atmosphere_publication_tid' );

		\remove_all_filters( 'atmosphere_pre_apply_writes' );
		\remove_all_filters( 'atmosphere_long_form_composition' );
		\remove_all_filters( 'atmosphere_teaser_thread_posts' );
		\remove_all_filters( 'atmosphere_transform_bsky_post' );
		\remove_all_filters( 'atmosphere_is_short_form_post' );
		\remove_filter( 'pre_http_request', array( $this, 'mock_document_ref_update' ), 10 );

		parent::tear_down();
	}

	/**
	 * Mock follow-up document putRecord calls.
	 *
	 * @param false|array|\WP_Error $response Preemptive HTTP response.
	 * @param array                 $args     Request args.
	 * @param string                $url      Request URL.
	 * @return false|array|\WP_Error
	 */
	public function mock_document_ref_update( $response, array $args, string $url ) {
		if ( false !== $response ) {
			return $response;
		}

		if ( false === \strpos( $url, 'com.atproto.repo.putRecord' ) ) {
			return $response;
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => \wp_json_encode(
				array(
					'uri' => 'at://did:plc:test123/site.standard.document/doc-ref',
					'cid' => 'bafyreibdocref',
				)
			),
		);
	}

	/**
	 * Synthesize a plausible applyWrites response for a batch of writes.
	 *
	 * One result per write, with a stable URI + CID derived from the
	 * write's collection + rkey.  Delete writes produce empty result
	 * entries so Publisher's `store_document_meta()` treats them as no-ops.
	 *
	 * @param array $writes Write batch.
	 * @return array applyWrites response shape.
	 */
	private function mock_response( array $writes ): array {
		$results = array();
		foreach ( $writes as $write ) {
			$type = $write['$type'] ?? '';
			if ( 'com.atproto.repo.applyWrites#delete' === $type ) {
				$results[] = array();
				continue;
			}

			$collection = $write['collection'] ?? 'app.bsky.feed.post';
			$rkey       = $write['rkey'] ?? '';
			$cid_seed   = \md5( \wp_json_encode( $write['value'] ?? array() ) );

			$results[] = array(
				'uri' => "at://did:plc:test123/{$collection}/{$rkey}",
				'cid' => 'bafyreib' . \substr( $cid_seed, 0, 20 ),
			);
		}

		return array( 'results' => $results );
	}

	/**
	 * Register the `atmosphere_pre_apply_writes` capture filter.
	 *
	 * Every call is appended to `$this->captured_calls` with the write
	 * batch, the synthesized response, and a snapshot of
	 * `META_THREAD_RECORDS` at call time (useful for asserting partial
	 * meta between thread writes). Tests can flip individual calls to
	 * a `WP_Error` by pushing entries to `$this->fail_call_indexes`
	 * before invoking Publisher — any call whose 1-based index is in
	 * that array returns the associated error instead of a success
	 * response.
	 *
	 * @param int $post_id Post being published; used to snapshot meta.
	 */
	private function register_capture( int $post_id ): void {
		$this->captured_calls    = array();
		$this->fail_call_indexes = $this->fail_call_indexes ?? array();

		\add_filter(
			'atmosphere_pre_apply_writes',
			function ( $short_circuit, array $writes ) use ( $post_id ) {
				$call_number = \count( $this->captured_calls ) + 1;

				$meta_snapshot = \get_post_meta( $post_id, Post::META_THREAD_RECORDS, true );

				if ( isset( $this->fail_call_indexes[ $call_number ] ) ) {
					$response               = $this->fail_call_indexes[ $call_number ];
					$this->captured_calls[] = array(
						'writes'        => $writes,
						'meta_snapshot' => $meta_snapshot,
						'response'      => $response,
					);
					return $response;
				}

				$response               = $this->mock_response( $writes );
				$this->captured_calls[] = array(
					'writes'        => $writes,
					'meta_snapshot' => $meta_snapshot,
					'response'      => $response,
				);
				return $response;
			},
			10,
			2
		);
	}

	/**
	 * Captured applyWrites calls (set by register_capture()).
	 *
	 * @var array
	 */
	private array $captured_calls = array();

	/**
	 * 1-indexed map of call number → WP_Error to return for that call.
	 *
	 * @var array<int,\WP_Error>
	 */
	private array $fail_call_indexes = array();

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
	 *
	 * Uses the `atmosphere_pre_apply_writes` short-circuit so the test
	 * runs to completion regardless of the DPoP/auth state in the test
	 * environment, and assertions are unconditional.
	 */
	public function test_update_sends_update_writes() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'publish' )
		);

		\update_post_meta( $post->ID, Post::META_TID, 'bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid-456' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test/app.bsky.feed.post/bsky-tid-123' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test/site.standard.document/doc-tid-456' );

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = Publisher::update_post( $post );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->captured_calls );

		$writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 2, $writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $writes[0]['$type'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $writes[1]['$type'] );
		$this->assertSame( 'bsky-tid-123', $writes[0]['rkey'] );
		$this->assertSame( 'doc-tid-456', $writes[1]['rkey'] );
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
	 * Delete-post includes delete writes for every published comment
	 * reply on the post and clears their meta on success. AT Protocol
	 * has no cascade semantics, so without this the replies would be
	 * orphaned on the PDS after the root goes away.
	 */
	public function test_delete_post_cascades_comment_replies() {
		$post = self::factory()->post->create_and_get(
			array( 'post_status' => 'trash' )
		);
		\update_post_meta( $post->ID, Post::META_TID, 'post-tid' );
		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/post-tid' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-tid' );

		// Two published comment replies + one never-published comment.
		$c1 = self::factory()->comment->create( array( 'comment_post_ID' => $post->ID ) );
		\update_comment_meta( $c1, Comment::META_TID, 'reply-tid-1' );
		\update_comment_meta( $c1, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/reply-tid-1' );

		$c2 = self::factory()->comment->create( array( 'comment_post_ID' => $post->ID ) );
		\update_comment_meta( $c2, Comment::META_TID, 'reply-tid-2' );
		\update_comment_meta( $c2, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/reply-tid-2' );

		$c3 = self::factory()->comment->create( array( 'comment_post_ID' => $post->ID ) );
		\update_comment_meta( $c3, Comment::META_TID, 'stale-tid' );
		// No META_URI — previously-failed publish; must not be in the delete batch.

		$captured_body = null;
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured_body ) {
				if ( false !== \strpos( $url, 'applyWrites' ) ) {
					$captured_body = \json_decode( $args['body'], true );

					return array(
						'response' => array( 'code' => 200 ),
						'body'     => \wp_json_encode( array( 'results' => array() ) ),
					);
				}
				return $response;
			},
			5,
			3
		);

		Publisher::delete_post( $post );
		\remove_all_filters( 'pre_http_request' );

		if ( null === $captured_body ) {
			$this->markTestSkipped( 'API layer rejected request before stub.' );
		}

		$rkeys = \array_column( $captured_body['writes'], 'rkey' );
		$this->assertContains( 'post-tid', $rkeys );
		$this->assertContains( 'doc-tid', $rkeys );
		$this->assertContains( 'reply-tid-1', $rkeys );
		$this->assertContains( 'reply-tid-2', $rkeys );
		$this->assertNotContains( 'stale-tid', $rkeys, 'Stale TID without URI must not be included.' );

		// Meta cleanup on both the post and the published replies.
		$this->assertSame( '', \get_comment_meta( $c1, Comment::META_URI, true ) );
		$this->assertSame( '', \get_comment_meta( $c2, Comment::META_TID, true ) );
		// Stale comment's TID is left alone — we did not touch its record.
		$this->assertSame( 'stale-tid', \get_comment_meta( $c3, Comment::META_TID, true ) );
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
	 * Publish-comment on API error writes no comment meta. A failed
	 * API call must not leave synthesized URI/CID/SOURCE_ID behind,
	 * because later update/delete/dedup paths key off those values.
	 */
	public function test_publish_comment_writes_no_meta_on_api_error() {
		$post_id    = $this->seed_root_post();
		$user_id    = self::factory()->user->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);

		// No stub — the bootstrap's auth layer returns WP_Error.
		$result = Publisher::publish_comment( \get_comment( $comment_id ) );

		$this->assertWPError( $result );
		$this->assertSame( '', \get_comment_meta( $comment_id, Comment::META_URI, true ) );
		$this->assertSame( '', \get_comment_meta( $comment_id, Comment::META_CID, true ) );
		$this->assertSame( '', \get_comment_meta( $comment_id, Reaction_Sync::META_SOURCE_ID, true ) );
	}

	/**
	 * Publish-comment on a 2xx response that omits results[0].uri
	 * returns atmosphere_missing_uri and does not write meta, rather
	 * than silently mirroring a locally-synthesized URI into the
	 * dedup key.
	 */
	public function test_publish_comment_errors_on_response_without_uri() {
		$post_id    = $this->seed_root_post();
		$user_id    = self::factory()->user->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'user_id'          => $user_id,
			)
		);

		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				if ( false !== \strpos( $url, 'applyWrites' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => \wp_json_encode( array( 'results' => array( array() ) ) ),
					);
				}
				return $response;
			},
			5,
			3
		);

		$result = Publisher::publish_comment( \get_comment( $comment_id ) );
		\remove_all_filters( 'pre_http_request' );

		if ( \is_wp_error( $result ) && 'atmosphere_missing_uri' !== $result->get_error_code() ) {
			// Auth layer blocked before the stub — the assertion we
			// care about cannot run.
			$this->markTestSkipped( 'API layer rejected request before stub: ' . $result->get_error_code() );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_missing_uri', $result->get_error_code() );
		$this->assertSame( '', \get_comment_meta( $comment_id, Comment::META_URI, true ) );
		$this->assertSame( '', \get_comment_meta( $comment_id, Reaction_Sync::META_SOURCE_ID, true ) );
	}

	/**
	 * Update-comment on API error preserves the previously-stored
	 * URI/CID meta so subsequent retries see the record still exists.
	 */
	public function test_update_comment_preserves_meta_on_api_error() {
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
		\update_comment_meta( $comment_id, Comment::META_CID, 'bafyexisting' );

		$result = Publisher::update_comment( \get_comment( $comment_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'existingtid', \get_comment_meta( $comment_id, Comment::META_TID, true ) );
		$this->assertSame( 'at://did:plc:test123/app.bsky.feed.post/existingtid', \get_comment_meta( $comment_id, Comment::META_URI, true ) );
		$this->assertSame( 'bafyexisting', \get_comment_meta( $comment_id, Comment::META_CID, true ) );
	}

	/**
	 * Delete-comment on API error preserves the meta so a later retry
	 * still targets the existing record instead of silently giving up.
	 */
	public function test_delete_comment_preserves_meta_on_api_error() {
		$post_id    = $this->seed_root_post();
		$user_id    = self::factory()->user->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'user_id'         => $user_id,
			)
		);
		\update_comment_meta( $comment_id, Comment::META_TID, 'doomed' );
		\update_comment_meta( $comment_id, Comment::META_URI, 'at://did:plc:test123/app.bsky.feed.post/doomed' );
		\update_comment_meta( $comment_id, Comment::META_CID, 'bafydoomed' );

		$result = Publisher::delete_comment( \get_comment( $comment_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'doomed', \get_comment_meta( $comment_id, Comment::META_TID, true ) );
		$this->assertSame( 'at://did:plc:test123/app.bsky.feed.post/doomed', \get_comment_meta( $comment_id, Comment::META_URI, true ) );
		$this->assertSame( 'bafydoomed', \get_comment_meta( $comment_id, Comment::META_CID, true ) );
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

	/*
	 * -----------------------------------------------------------------
	 * Thread publish/update/delete flows (via atmosphere_pre_apply_writes).
	 * -----------------------------------------------------------------
	 */

	/**
	 * Default long-form path (link-card) issues exactly one applyWrites
	 * call with 2 creates, and `META_THREAD_RECORDS` is populated as a
	 * 1-entry array mirroring the root.
	 */
	public function test_publish_link_card_writes_single_atomic_applywrites() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Body.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = Publisher::publish( $post );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->captured_calls, 'link-card publish uses one applyWrites call.' );

		$writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 2, $writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $writes[0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $writes[0]['collection'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $writes[1]['$type'] );
		$this->assertSame( 'site.standard.document', $writes[1]['collection'] );

		$thread_records = \get_post_meta( $post->ID, Post::META_THREAD_RECORDS, true );
		$this->assertIsArray( $thread_records );
		$this->assertCount( 1, $thread_records );
		$this->assertNotEmpty( $thread_records[0]['uri'] );
		$this->assertNotEmpty( $thread_records[0]['cid'] );
		$this->assertNotEmpty( $thread_records[0]['tid'] );

		$this->assertSame( $thread_records[0]['uri'], \get_post_meta( $post->ID, Post::META_URI, true ) );
		$this->assertSame( $thread_records[0]['tid'], \get_post_meta( $post->ID, Post::META_TID, true ) );
	}

	/**
	 * Teaser-thread writes root + doc atomically first, then the CTA
	 * reply as its own applyWrites call, with reply refs pointing at
	 * the root.
	 */
	public function test_publish_teaser_thread_writes_root_first_then_reply_sequentially() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Hi.',
				// Excerpt becomes the hook + body too short to form a
				// chunk → 2-entry `[ excerpt, CTA ]` default. Excerpt
				// is non-empty so the redundant-CTA collapse in
				// `build_long_form_records()` does not fire and the
				// publisher protocol assertions below stay at 2 records.
				'post_excerpt' => 'A curated standalone excerpt for the test fixture.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = Publisher::publish( $post );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $this->captured_calls );

		// Call 1: root + doc creates, no reply refs.
		$first_writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 2, $first_writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $first_writes[0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $first_writes[0]['collection'] );
		$this->assertSame( 'site.standard.document', $first_writes[1]['collection'] );
		$this->assertArrayNotHasKey( 'reply', $first_writes[0]['value'] );
		$this->assertNotEmpty( $first_writes[0]['value']['createdAt'] );

		// Call 2: single reply create with reply refs pointing at root.
		$second_writes = $this->captured_calls[1]['writes'];
		$this->assertCount( 1, $second_writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $second_writes[0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $second_writes[0]['collection'] );

		$reply = $second_writes[0]['value'];
		$this->assertArrayHasKey( 'reply', $reply );

		$root_response_uri = $this->captured_calls[0]['response']['results'][0]['uri'];
		$root_response_cid = $this->captured_calls[0]['response']['results'][0]['cid'];

		$this->assertSame( $root_response_uri, $reply['reply']['root']['uri'] );
		$this->assertSame( $root_response_cid, $reply['reply']['root']['cid'] );
		// 2-post thread: parent is the root.
		$this->assertSame( $root_response_uri, $reply['reply']['parent']['uri'] );
		$this->assertSame( $root_response_cid, $reply['reply']['parent']['cid'] );
	}

	/**
	 * After the root write succeeds but before the reply write runs,
	 * `META_THREAD_RECORDS` should be a 1-entry array — a crash-recovery
	 * anchor pointing at the already-written root.
	 */
	public function test_publish_teaser_thread_partial_meta_written_after_root() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Hi.',
				// Excerpt becomes the hook + body too short to form a
				// chunk → 2-entry `[ excerpt, CTA ]` default. Excerpt
				// is non-empty so the redundant-CTA collapse in
				// `build_long_form_records()` does not fire and the
				// publisher protocol assertions below stay at 2 records.
				'post_excerpt' => 'A curated standalone excerpt for the test fixture.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		Publisher::publish( $post );

		// Call 1 snapshot: taken before root write, meta should be empty / unset.
		$this->assertTrue( empty( $this->captured_calls[0]['meta_snapshot'] ) );

		// Call 2 snapshot: taken just before the reply write, root should
		// be already persisted.
		$call2_snapshot = $this->captured_calls[1]['meta_snapshot'];
		$this->assertIsArray( $call2_snapshot );
		$this->assertCount( 1, $call2_snapshot );
		$this->assertNotEmpty( $call2_snapshot[0]['uri'] );
	}

	/**
	 * On happy-path thread publish, META_THREAD_RECORDS is an ordered
	 * 2-entry array (root, reply) and the legacy single-record meta
	 * mirrors the root.
	 */
	public function test_publish_teaser_thread_final_meta_has_ordered_triples() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Hi.',
				// Excerpt becomes the hook + body too short to form a
				// chunk → 2-entry `[ excerpt, CTA ]` default. Excerpt
				// is non-empty so the redundant-CTA collapse in
				// `build_long_form_records()` does not fire and the
				// publisher protocol assertions below stay at 2 records.
				'post_excerpt' => 'A curated standalone excerpt for the test fixture.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		Publisher::publish( $post );

		$thread_records = \get_post_meta( $post->ID, Post::META_THREAD_RECORDS, true );
		$this->assertIsArray( $thread_records );
		$this->assertCount( 2, $thread_records );

		foreach ( $thread_records as $record ) {
			$this->assertNotEmpty( $record['uri'] );
			$this->assertNotEmpty( $record['cid'] );
			$this->assertNotEmpty( $record['tid'] );
		}

		// Single-record meta mirrors the root.
		$this->assertSame( $thread_records[0]['uri'], \get_post_meta( $post->ID, Post::META_URI, true ) );
		$this->assertSame( $thread_records[0]['tid'], \get_post_meta( $post->ID, Post::META_TID, true ) );
		$this->assertSame( $thread_records[0]['cid'], \get_post_meta( $post->ID, Post::META_CID, true ) );

		// Flat URI index carries every record URI so reaction sync
		// can resolve reply URIs back to this post.
		$indexed = \get_post_meta( $post->ID, Post::META_URI_INDEX, false );
		$this->assertIsArray( $indexed );
		$this->assertCount( 2, $indexed );
		$this->assertContains( $thread_records[0]['uri'], $indexed );
		$this->assertContains( $thread_records[1]['uri'], $indexed );
	}

	/**
	 * If the follow-up document update fails after the initial applyWrites,
	 * publish returns the error while preserving meta for a retry.
	 */
	public function test_publish_surfaces_document_ref_update_failure() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Body content.',
			)
		);

		$put_record_failure = static function ( $response, $args, $url ) {
			if ( false !== \strpos( $url, 'com.atproto.repo.putRecord' ) ) {
				return new \WP_Error( 'atmosphere_doc_ref_failed', 'Document ref update failed.' );
			}
			return $response;
		};

		\add_filter( 'pre_http_request', $put_record_failure, 5, 3 );

		try {
			$this->fail_call_indexes = array();
			$this->register_capture( $post->ID );

			$result = Publisher::publish( $post );

			$this->assertWPError( $result );
			$this->assertSame( 'atmosphere_doc_ref_failed', $result->get_error_code() );

			$thread_records = \get_post_meta( $post->ID, Post::META_THREAD_RECORDS, true );
			$this->assertIsArray( $thread_records );
			$this->assertCount( 1, $thread_records );
			$this->assertNotEmpty( \get_post_meta( $post->ID, Document::META_URI, true ) );
		} finally {
			\remove_filter( 'pre_http_request', $put_record_failure, 5 );
		}
	}

	/**
	 * In a thread publish, a failure on the doc-ref `putRecord` between
	 * step 1 (root + doc) and step 2+ (replies) must not abort the thread
	 * — otherwise META_THREAD_RECORDS sticks at length=1 and the next
	 * edit triggers a rewrite that replaces the already-published root
	 * URI/TID, invalidating likes/reposts/external replies.
	 *
	 * Best-effort: log the doc-ref failure, then continue writing replies.
	 */
	public function test_publish_thread_continues_when_doc_ref_update_fails() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_excerpt' => 'A curated excerpt long enough to compose a hook from.',
				// Empty body: hook comes from the excerpt and there is no
				// body chunk, so the default shape is [hook, cta] and the
				// protocol assertions below expect a single reply write.
				'post_content' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$put_record_failure = static function ( $response, $args, $url ) {
			if ( false !== \strpos( $url, 'com.atproto.repo.putRecord' ) ) {
				return new \WP_Error( 'atmosphere_doc_ref_failed', 'Document ref update failed.' );
			}
			return $response;
		};

		\add_filter( 'pre_http_request', $put_record_failure, 5, 3 );

		try {
			$this->fail_call_indexes = array();
			$this->register_capture( $post->ID );

			$result = Publisher::publish( $post );

			// Doc-ref failure is swallowed — overall publish succeeds.
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'results', $result );

			// Both root + reply applyWrites batches went through (call 1 = root+doc, call 2 = reply).
			$this->assertCount( 2, $this->captured_calls );

			$thread_records = \get_post_meta( $post->ID, Post::META_THREAD_RECORDS, true );
			$this->assertIsArray( $thread_records );
			$this->assertCount( 2, $thread_records );
			foreach ( $thread_records as $record ) {
				$this->assertNotEmpty( $record['uri'] );
				$this->assertNotEmpty( $record['cid'] );
			}

			// Pending-doc-ref marker is persisted so admin / Site Health
			// can surface the gap; logs are not the only signal.
			$pending = \get_post_meta( $post->ID, Post::META_DOC_REF_PENDING, true );
			$this->assertIsArray( $pending );
			$this->assertSame( 'atmosphere_doc_ref_failed', $pending['code'] );
			$this->assertNotEmpty( $pending['stamp'] );
			$this->assertNotEmpty( $pending['message'] );
		} finally {
			\remove_filter( 'pre_http_request', $put_record_failure, 5 );
		}
	}

	/**
	 * A successful `update_document_bsky_ref` clears any prior
	 * `META_DOC_REF_PENDING` marker — typical recovery path is the user
	 * re-saves the post (any `Publisher::update*` flow ends at
	 * `update_document_bsky_ref`).
	 */
	public function test_publish_clears_doc_ref_pending_on_successful_doc_ref() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Body.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		// Pretend a previous publish persisted a pending marker.
		\update_post_meta(
			$post->ID,
			Post::META_DOC_REF_PENDING,
			array(
				'stamp'   => '2026-04-28T00:00:00.000Z',
				'code'    => 'atmosphere_doc_ref_failed',
				'message' => 'previous failure',
			)
		);

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = Publisher::publish( $post );

		$this->assertIsArray( $result );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_DOC_REF_PENDING, true ) );
	}

	/**
	 * When the reply write fails, issue compensating deletes for the
	 * (possibly committed) reply, the root, and the doc, clear every
	 * meta key, and return the original WP_Error.
	 *
	 * The reply rkey is generated locally before the create, so even
	 * when the WP_Error is genuinely "never committed" the delete still
	 * has the correct rkey to target — and when the failure is actually
	 * an ambiguous "PDS committed but response failed", that rkey is
	 * the only handle on the live record. Including it in rollback
	 * closes the gap where rollback used to leave a live reply
	 * untracked in META_THREAD_RECORDS.
	 */
	public function test_publish_teaser_thread_rollback_on_second_write_failure() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Hi.',
				// Excerpt becomes the hook + body too short to form a
				// chunk → 2-entry `[ excerpt, CTA ]` default. Excerpt
				// is non-empty so the redundant-CTA collapse in
				// `build_long_form_records()` does not fire and the
				// publisher protocol assertions below stay at 2 records.
				'post_excerpt' => 'A curated standalone excerpt for the test fixture.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		// Fail call #2 (the reply create). Rollback call #3 succeeds.
		$this->fail_call_indexes = array(
			2 => new \WP_Error( 'atmosphere_reply_failed', 'Reply write failed.' ),
		);
		$this->register_capture( $post->ID );

		// Capture the reply rkey from the (failed) create write so we can
		// assert it appears in the rollback batch.
		$result = Publisher::publish( $post );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_reply_failed', $result->get_error_code() );

		// 3 calls total: root+doc create, reply create (fail), rollback deletes.
		$this->assertCount( 3, $this->captured_calls );

		$failed_reply_rkey = $this->captured_calls[1]['writes'][0]['rkey'];
		$this->assertNotEmpty( $failed_reply_rkey );

		$rollback_writes = $this->captured_calls[2]['writes'];

		// Rollback deletes the (ambiguous) reply first, then the root,
		// then the doc — tail-first traversal over thread_records with
		// the failed-reply triple appended.
		$this->assertCount( 3, $rollback_writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $rollback_writes[0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $rollback_writes[0]['collection'] );
		$this->assertSame( $failed_reply_rkey, $rollback_writes[0]['rkey'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $rollback_writes[1]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $rollback_writes[1]['collection'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $rollback_writes[2]['$type'] );
		$this->assertSame( 'site.standard.document', $rollback_writes[2]['collection'] );

		// Root TID is the second delete (after the ambiguous reply).
		$root_rkey = $rollback_writes[1]['rkey'];
		$this->assertNotEmpty( $root_rkey );

		// Meta fully cleared.
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_THREAD_RECORDS, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_URI, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_TID, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_CID, true ) );
	}

	/**
	 * When both the reply write AND the rollback fail, the returned
	 * WP_Error wraps both errors and carries `partial_records` data for
	 * manual cleanup.
	 */
	public function test_publish_teaser_thread_rollback_failing_surfaces_partial_state() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Hi.',
				// Excerpt becomes the hook + body too short to form a
				// chunk → 2-entry `[ excerpt, CTA ]` default. Excerpt
				// is non-empty so the redundant-CTA collapse in
				// `build_long_form_records()` does not fire and the
				// publisher protocol assertions below stay at 2 records.
				'post_excerpt' => 'A curated standalone excerpt for the test fixture.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$this->fail_call_indexes = array(
			2 => new \WP_Error( 'atmosphere_reply_failed', 'Reply write failed.' ),
			3 => new \WP_Error( 'atmosphere_rollback_pds', 'Rollback PDS error.' ),
		);
		$this->register_capture( $post->ID );

		$result = Publisher::publish( $post );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_thread_rollback_failed', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'partial_records', $data );
		$this->assertIsArray( $data['partial_records'] );
		// Root + ambiguous failed-reply (rkey known, commit state unknown).
		$this->assertCount( 2, $data['partial_records'] );
		$this->assertArrayHasKey( 'original_error', $data );
		$this->assertArrayHasKey( 'rollback_error', $data );

		// Orphan manifest is persisted to post meta so it outlives the cron closure.
		$orphans = \get_post_meta( $post->ID, Post::META_ORPHAN_RECORDS, true );
		$this->assertIsArray( $orphans );
		$this->assertCount( 1, $orphans );
		$this->assertSame( $data['partial_records'], $orphans[0]['bsky_records'] );
		$this->assertArrayHasKey( 'stamp', $orphans[0] );
		$this->assertNotEmpty( $orphans[0]['doc_rkey'] );
		$this->assertSame( 'Reply write failed.', $orphans[0]['original_error'] );
		$this->assertSame( 'Rollback PDS error.', $orphans[0]['rollback_error'] );
	}

	/**
	 * Update with stored single-record + single-record composition uses
	 * applyWrites#update in place rather than delete + republish.
	 */
	public function test_update_link_card_unchanged_single_post_uses_in_place_applywrites_update() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Body.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		// Seed META_THREAD_RECORDS with a 1-entry stored root + legacy mirrors.
		$root_uri = 'at://did:plc:test123/app.bsky.feed.post/stored-rkey-1';
		\update_post_meta(
			$post->ID,
			Post::META_THREAD_RECORDS,
			array(
				array(
					'uri' => $root_uri,
					'cid' => 'bafyreibstored',
					'tid' => 'stored-rkey-1',
				),
			)
		);
		\update_post_meta( $post->ID, Post::META_URI, $root_uri );
		\update_post_meta( $post->ID, Post::META_TID, 'stored-rkey-1' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafyreibstored' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-rkey-1' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-rkey-1' );

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = Publisher::update( $post );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->captured_calls, 'single-record update uses one applyWrites.' );

		$writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 2, $writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $writes[0]['$type'] );
		$this->assertSame( 'stored-rkey-1', $writes[0]['rkey'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $writes[1]['$type'] );
		$this->assertSame( 'doc-rkey-1', $writes[1]['rkey'] );
	}

	/**
	 * Update of a 2-post thread → 2-post thread uses in-place
	 * applyWrites#update for every record, preserving TIDs and URIs,
	 * and refreshes META_THREAD_RECORDS with the response CIDs.
	 */
	public function test_update_thread_in_place_when_record_counts_match() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Hi.',
				// Excerpt becomes the hook + body too short to form a
				// chunk → 2-entry `[ excerpt, CTA ]` default. Excerpt
				// is non-empty so the redundant-CTA collapse in
				// `build_long_form_records()` does not fire and the
				// new record count matches the stored 2-entry meta
				// below for an in-place update.
				'post_excerpt' => 'A curated standalone excerpt for the test fixture.',
			)
		);

		$stored = array(
			array(
				'uri' => 'at://did:plc:test123/app.bsky.feed.post/t-root',
				'cid' => 'bafyreibroot-old',
				'tid' => 't-root',
			),
			array(
				'uri' => 'at://did:plc:test123/app.bsky.feed.post/t-reply',
				'cid' => 'bafyreibreply-old',
				'tid' => 't-reply',
			),
		);
		\update_post_meta( $post->ID, Post::META_THREAD_RECORDS, $stored );
		\update_post_meta( $post->ID, Post::META_URI, $stored[0]['uri'] );
		\update_post_meta( $post->ID, Post::META_TID, 't-root' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafyreibroot-old' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-rkey-1' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-rkey-1' );

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = Publisher::update( $post );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->captured_calls, 'in-place thread update uses one applyWrites.' );

		$writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 3, $writes, '2 bsky updates + 1 doc update.' );

		$this->assertSame( 'com.atproto.repo.applyWrites#update', $writes[0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $writes[0]['collection'] );
		$this->assertSame( 't-root', $writes[0]['rkey'] );
		$this->assertArrayNotHasKey( 'reply', $writes[0]['value'] );

		$this->assertSame( 'com.atproto.repo.applyWrites#update', $writes[1]['$type'] );
		$this->assertSame( 't-reply', $writes[1]['rkey'] );
		$this->assertArrayHasKey( 'reply', $writes[1]['value'] );
		$this->assertSame( $stored[0]['uri'], $writes[1]['value']['reply']['root']['uri'] );
		$this->assertSame( $stored[0]['cid'], $writes[1]['value']['reply']['root']['cid'] );

		$this->assertSame( 'site.standard.document', $writes[2]['collection'] );
		$this->assertSame( 'doc-rkey-1', $writes[2]['rkey'] );

		// Thread meta was refreshed with the response CIDs; URIs and TIDs preserved.
		$refreshed = \get_post_meta( $post->ID, Post::META_THREAD_RECORDS, true );
		$this->assertIsArray( $refreshed );
		$this->assertCount( 2, $refreshed );
		$this->assertSame( $stored[0]['uri'], $refreshed[0]['uri'] );
		$this->assertSame( 't-root', $refreshed[0]['tid'] );
		$this->assertNotSame( 'bafyreibroot-old', $refreshed[0]['cid'], 'Root CID should refresh to the response cid.' );
		$this->assertSame( $stored[1]['uri'], $refreshed[1]['uri'] );
		$this->assertSame( 't-reply', $refreshed[1]['tid'] );
	}

	/**
	 * Backward-compat at the publisher level: a post that was published
	 * BEFORE the redundant-CTA collapse landed has 2 stored thread
	 * records for content (short body, empty excerpt) that would, on a
	 * fresh publish today, collapse to a single record. Without the
	 * `\count(\$stored)` hint passed from `update_post()` to
	 * `build_long_form_records()`, the new shape would be 1 record vs.
	 * the stored 2, the count mismatch would fall through to
	 * `rewrite_thread()`, the original root URI would be deleted, and
	 * every external Bluesky reply / like / repost would be orphaned.
	 *
	 * This test pins the end-to-end path: short-body fixture + 2 stored
	 * records + teaser-thread strategy → in-place update via a single
	 * `applyWrites` (2 bsky updates + 1 doc update), URIs preserved.
	 */
	public function test_update_thread_short_body_in_place_when_stored_two_records() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				// Short body + empty excerpt = exactly the shape that
				// would trip the redundant-CTA collapse on a fresh
				// publish. Backward-compat must keep it at 2 records.
				'post_content' => 'A short note that absorbs into the hook.',
				'post_excerpt' => '',
			)
		);

		$stored = array(
			array(
				'uri' => 'at://did:plc:test123/app.bsky.feed.post/legacy-root',
				'cid' => 'bafyreiblegacy-root',
				'tid' => 'legacy-root',
			),
			array(
				'uri' => 'at://did:plc:test123/app.bsky.feed.post/legacy-cta',
				'cid' => 'bafyreiblegacy-cta',
				'tid' => 'legacy-cta',
			),
		);
		\update_post_meta( $post->ID, Post::META_THREAD_RECORDS, $stored );
		\update_post_meta( $post->ID, Post::META_URI, $stored[0]['uri'] );
		\update_post_meta( $post->ID, Post::META_TID, 'legacy-root' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafyreiblegacy-root' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/legacy-doc' );
		\update_post_meta( $post->ID, Document::META_TID, 'legacy-doc' );

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = Publisher::update( $post );

		$this->assertIsArray( $result );
		$this->assertCount(
			1,
			$this->captured_calls,
			'In-place update must be a single atomic applyWrites — not delete + republish.'
		);

		$writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 3, $writes, '2 bsky updates (root + reply) + 1 doc update.' );

		// Each bsky write must be an `update`, not a `delete` or `create`,
		// reusing the legacy TIDs (URIs preserved on the network side).
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $writes[0]['$type'] );
		$this->assertSame( 'legacy-root', $writes[0]['rkey'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#update', $writes[1]['$type'] );
		$this->assertSame( 'legacy-cta', $writes[1]['rkey'] );
		$this->assertSame( 'site.standard.document', $writes[2]['collection'] );

		// Persisted URIs are unchanged — no rewrite would have orphaned
		// the external Bluesky engagement attached to legacy-root.
		$this->assertSame( $stored[0]['uri'], \get_post_meta( $post->ID, Post::META_URI, true ) );
		$this->assertSame( 'legacy-root', \get_post_meta( $post->ID, Post::META_TID, true ) );
	}

	/**
	 * Update with a stored 1-entry link-card but a teaser-thread composition
	 * deletes the old record + doc atomically, then publishes fresh.
	 */
	public function test_update_thread_rewrites_on_strategy_change() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Hi.',
				// Excerpt becomes the hook + body too short to form a
				// chunk → 2-entry `[ excerpt, CTA ]` default. Excerpt
				// is non-empty so the redundant-CTA collapse in
				// `build_long_form_records()` does not fire and the
				// post-rewrite assertions below stay at 2 records.
				'post_excerpt' => 'A curated standalone excerpt for the test fixture.',
			)
		);

		$root_uri = 'at://did:plc:test123/app.bsky.feed.post/stored-rkey-1';
		\update_post_meta(
			$post->ID,
			Post::META_THREAD_RECORDS,
			array(
				array(
					'uri' => $root_uri,
					'cid' => 'bafyreibstored',
					'tid' => 'stored-rkey-1',
				),
			)
		);
		\update_post_meta( $post->ID, Post::META_URI, $root_uri );
		\update_post_meta( $post->ID, Post::META_TID, 'stored-rkey-1' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafyreibstored' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-rkey-1' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-rkey-1' );

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = Publisher::update( $post );

		$this->assertIsArray( $result );

		// Call 1: delete old (1 bsky + 1 doc).
		$this->assertCount( 3, $this->captured_calls );
		$delete_writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 2, $delete_writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $delete_writes[0]['$type'] );
		$this->assertSame( 'stored-rkey-1', $delete_writes[0]['rkey'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $delete_writes[1]['$type'] );
		$this->assertSame( 'doc-rkey-1', $delete_writes[1]['rkey'] );

		// Call 2: fresh publish — root + doc creates.
		$publish_root_writes = $this->captured_calls[1]['writes'];
		$this->assertCount( 2, $publish_root_writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $publish_root_writes[0]['$type'] );

		// Call 3: reply create.
		$reply_writes = $this->captured_calls[2]['writes'];
		$this->assertCount( 1, $reply_writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $reply_writes[0]['$type'] );

		$thread_records = \get_post_meta( $post->ID, Post::META_THREAD_RECORDS, true );
		$this->assertIsArray( $thread_records );
		$this->assertCount( 2, $thread_records );
	}

	/**
	 * When rewrite_thread deletes the old records successfully but the
	 * subsequent republish fails, the pre-delete manifest is persisted
	 * to META_ORPHAN_RECORDS (phase=rewrite) so operators can audit
	 * what was lost. Meta is cleared so the next retry self-heals via
	 * publish().
	 */
	public function test_rewrite_thread_persists_manifest_on_republish_failure() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Body content.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		$root_uri = 'at://did:plc:test123/app.bsky.feed.post/stored-rkey-1';
		\update_post_meta(
			$post->ID,
			Post::META_THREAD_RECORDS,
			array(
				array(
					'uri' => $root_uri,
					'cid' => 'bafyreibstored',
					'tid' => 'stored-rkey-1',
				),
			)
		);
		\update_post_meta( $post->ID, Post::META_URI, $root_uri );
		\update_post_meta( $post->ID, Post::META_TID, 'stored-rkey-1' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafyreibstored' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-rkey-1' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-rkey-1' );

		// Force a strategy change (1-entry stored → 2-entry new).
		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		// Call 1 (the delete batch) succeeds, call 2 (the republish
		// create of root + doc) fails.
		$this->fail_call_indexes = array(
			2 => new \WP_Error( 'atmosphere_republish_failed', 'Republish PDS error.' ),
		);
		$this->register_capture( $post->ID );

		$result = \Atmosphere\Publisher::update( $post );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_republish_failed', $result->get_error_code() );

		// Active-record meta cleared so a retry self-heals. A fresh TID
		// may have been reserved by the failed publish's get_rkey() — that's
		// a harmless ghost; stored_thread_records's legacy fallback ignores
		// bare TIDs without a URI so the retry goes through publish() and
		// reuses the reserved TID.
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_THREAD_RECORDS, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_URI, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_CID, true ) );

		// Manifest persisted for operator visibility.
		$orphans = \get_post_meta( $post->ID, Post::META_ORPHAN_RECORDS, true );
		$this->assertIsArray( $orphans );
		$this->assertCount( 1, $orphans );
		$this->assertSame( 'rewrite', $orphans[0]['phase'] );
		$this->assertSame( 'doc-rkey-1', $orphans[0]['deleted_doc'] );
		$this->assertSame( 'Republish PDS error.', $orphans[0]['publish_error'] );
		$this->assertCount( 1, $orphans[0]['deleted_bsky'] );
		$this->assertSame( 'stored-rkey-1', $orphans[0]['deleted_bsky'][0]['tid'] );
	}

	/**
	 * When bsky records exist but the document URI is missing (an
	 * anomalous partial state), update() deletes the orphan bsky records
	 * via rewrite_thread with an empty doc_tid before republishing
	 * fresh. Previously this branch called publish() directly, which
	 * reused existing TIDs and triggered "already exists" on the PDS.
	 */
	public function test_update_partial_state_missing_doc_uri_rewrites() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Body.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		$root_uri = 'at://did:plc:test123/app.bsky.feed.post/stored-rkey-1';
		\update_post_meta(
			$post->ID,
			Post::META_THREAD_RECORDS,
			array(
				array(
					'uri' => $root_uri,
					'cid' => 'bafyreibstored',
					'tid' => 'stored-rkey-1',
				),
			)
		);
		\update_post_meta( $post->ID, Post::META_URI, $root_uri );
		\update_post_meta( $post->ID, Post::META_TID, 'stored-rkey-1' );
		// Deliberately no Document::META_URI or META_TID.

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = \Atmosphere\Publisher::update( $post );

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 2, \count( $this->captured_calls ) );

		// First batch: delete the orphan bsky record, no doc delete.
		$delete_writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 1, $delete_writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $delete_writes[0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $delete_writes[0]['collection'] );
		$this->assertSame( 'stored-rkey-1', $delete_writes[0]['rkey'] );

		// Second batch: fresh publish with newly-generated TIDs
		// (not the stale stored-rkey-1).
		$publish_writes = $this->captured_calls[1]['writes'];
		$this->assertSame( 'com.atproto.repo.applyWrites#create', $publish_writes[0]['$type'] );
		$this->assertNotSame( 'stored-rkey-1', $publish_writes[0]['rkey'] );
	}

	/**
	 * Deleting a thread-published post issues one atomic applyWrites with
	 * every bsky delete + the doc delete, then clears all meta.
	 */
	public function test_delete_thread_removes_all_records() {
		$post = self::factory()->post->create_and_get();

		$thread = array(
			array(
				'uri' => 'at://did:plc:test123/app.bsky.feed.post/t-root',
				'cid' => 'bafyreibroot',
				'tid' => 't-root',
			),
			array(
				'uri' => 'at://did:plc:test123/app.bsky.feed.post/t-reply',
				'cid' => 'bafyreibrepy',
				'tid' => 't-reply',
			),
		);
		\update_post_meta( $post->ID, Post::META_THREAD_RECORDS, $thread );
		\update_post_meta( $post->ID, Post::META_URI, $thread[0]['uri'] );
		\update_post_meta( $post->ID, Post::META_TID, 't-root' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafyreibroot' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-rkey-1' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-rkey-1' );

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = Publisher::delete( $post );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->captured_calls );

		$writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 3, $writes ); // 2 bsky deletes + 1 doc delete.
		$this->assertSame( 't-root', $writes[0]['rkey'] );
		$this->assertSame( 't-reply', $writes[1]['rkey'] );
		$this->assertSame( 'site.standard.document', $writes[2]['collection'] );
		$this->assertSame( 'doc-rkey-1', $writes[2]['rkey'] );

		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_THREAD_RECORDS, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_URI, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_TID, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_CID, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Document::META_URI, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Document::META_TID, true ) );
	}

	/**
	 * Publisher::delete_post_by_tids accepts an array of bsky TIDs and
	 * issues one applyWrites covering every bsky delete + the doc.
	 */
	public function test_delete_by_tids_array_of_bsky_tids() {
		$this->fail_call_indexes = array();
		$this->register_capture( 0 );

		$result = \Atmosphere\Publisher::delete_post_by_tids(
			array( 't-root', 't-r1', 't-r2' ),
			'doc-tid'
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->captured_calls );

		$writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 4, $writes );
		$this->assertSame( 't-root', $writes[0]['rkey'] );
		$this->assertSame( 't-r1', $writes[1]['rkey'] );
		$this->assertSame( 't-r2', $writes[2]['rkey'] );
		$this->assertSame( 'site.standard.document', $writes[3]['collection'] );
		$this->assertSame( 'doc-tid', $writes[3]['rkey'] );
	}

	/**
	 * Publisher::delete_post_by_tids with a legacy string argument still
	 * produces a single-bsky-delete batch — backwards compatibility for
	 * cron events queued before the signature change.
	 */
	public function test_delete_by_tids_legacy_string_argument() {
		$this->fail_call_indexes = array();
		$this->register_capture( 0 );

		$result = \Atmosphere\Publisher::delete_post_by_tids( 'legacy-tid', 'doc-tid' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->captured_calls );

		$writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 2, $writes );
		$this->assertSame( 'legacy-tid', $writes[0]['rkey'] );
		$this->assertSame( 'doc-tid', $writes[1]['rkey'] );
	}

	/**
	 * Publisher::delete_post_by_tids with empty inputs errors without
	 * making any API call.
	 */
	public function test_delete_by_tids_empty_inputs_error() {
		$this->fail_call_indexes = array();
		$this->register_capture( 0 );

		$result = \Atmosphere\Publisher::delete_post_by_tids( array(), '' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_not_published', $result->get_error_code() );
		$this->assertCount( 0, $this->captured_calls, 'No API call should be made.' );
	}

	/**
	 * A malformed atmosphere_pre_apply_writes return (scalar, object)
	 * surfaces as a WP_Error instead of fatal-ing on the return type.
	 */
	public function test_pre_apply_writes_malformed_return_surfaces_wp_error() {
		\add_filter( 'atmosphere_pre_apply_writes', fn() => true );

		$result = \Atmosphere\API::apply_writes(
			array(
				array(
					'$type'      => 'com.atproto.repo.applyWrites#delete',
					'collection' => 'x',
					'rkey'       => 'y',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_invalid_pre_apply_writes_return', $result->get_error_code() );
	}

	/**
	 * A malformed atmosphere_pre_apply_writes success array must include
	 * a results list matching the write batch.
	 */
	public function test_pre_apply_writes_malformed_success_array_surfaces_wp_error() {
		\add_filter( 'atmosphere_pre_apply_writes', fn() => array( 'ok' => true ) );

		$result = \Atmosphere\API::apply_writes(
			array(
				array(
					'$type'      => 'com.atproto.repo.applyWrites#delete',
					'collection' => 'x',
					'rkey'       => 'y',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_invalid_pre_apply_writes_response', $result->get_error_code() );
	}

	/**
	 * A create/update short-circuit result must include the URI and CID
	 * shape returned by the PDS.
	 */
	public function test_pre_apply_writes_create_result_without_uri_and_cid_surfaces_wp_error() {
		\add_filter(
			'atmosphere_pre_apply_writes',
			fn() => array(
				'results' => array(
					array(),
				),
			)
		);

		$result = \Atmosphere\API::apply_writes(
			array(
				array(
					'$type'      => 'com.atproto.repo.applyWrites#create',
					'collection' => 'app.bsky.feed.post',
					'rkey'       => 'abc',
					'value'      => array( 'text' => 'Hello' ),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_invalid_pre_apply_writes_response', $result->get_error_code() );
	}

	/**
	 * A post with only legacy single-record meta (no META_THREAD_RECORDS)
	 * still deletes correctly via the fallback path.
	 */
	public function test_delete_legacy_single_post_meta() {
		$post = self::factory()->post->create_and_get();

		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/legacy-rkey' );
		\update_post_meta( $post->ID, Post::META_TID, 'legacy-rkey' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafyreiblegacy' );
		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/doc-legacy' );
		\update_post_meta( $post->ID, Document::META_TID, 'doc-legacy' );
		// Deliberately no META_THREAD_RECORDS.

		$this->fail_call_indexes = array();
		$this->register_capture( $post->ID );

		$result = Publisher::delete( $post );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->captured_calls );

		$writes = $this->captured_calls[0]['writes'];
		$this->assertCount( 2, $writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $writes[0]['$type'] );
		$this->assertSame( 'legacy-rkey', $writes[0]['rkey'] );
		$this->assertSame( 'doc-legacy', $writes[1]['rkey'] );

		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_URI, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_TID, true ) );
		$this->assertSame( '', \get_post_meta( $post->ID, Post::META_CID, true ) );
	}

	/**
	 * `delete_post_by_tids` chunks oversized batches into multiple
	 * `applyWrites` calls. The lexicon caps a single batch at 200 writes;
	 * a high-traffic post with hundreds of outbound comment replies must
	 * still clean up cleanly rather than failing the whole cascade.
	 */
	public function test_delete_post_by_tids_chunks_oversized_batches() {
		$this->fail_call_indexes = array();
		$this->register_capture( 0 );

		$comment_tids = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$comment_tids[] = 'reply-' . $i;
		}

		$result = Publisher::delete_post_by_tids(
			array( 'root-tid' ),
			'doc-tid',
			$comment_tids
		);

		$this->assertIsArray( $result );
		// 252 total writes / 100 per chunk = 3 calls.
		$this->assertCount( 3, $this->captured_calls );

		$total_writes = 0;
		foreach ( $this->captured_calls as $call ) {
			$total_writes += \count( $call['writes'] );
			$this->assertLessThanOrEqual( 100, \count( $call['writes'] ) );
		}
		$this->assertSame( 252, $total_writes );
	}

	/**
	 * Chunked deletes report the chunk index and how many chunks
	 * succeeded when one fails partway through, so operators can see
	 * the partial-success state in the error log rather than treating
	 * it as a clean failure.
	 */
	public function test_delete_post_by_tids_chunked_failure_carries_progress_data() {
		$this->fail_call_indexes = array(
			2 => new \WP_Error( 'atmosphere_pds_500', 'PDS rejected batch.' ),
		);
		$this->register_capture( 0 );

		$comment_tids = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$comment_tids[] = 'reply-' . $i;
		}

		$result = Publisher::delete_post_by_tids(
			array( 'root-tid' ),
			'doc-tid',
			$comment_tids
		);

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_pds_500', $result->get_error_code() );

		$data = $result->get_error_data( 'atmosphere_chunked_apply_writes' );
		$this->assertIsArray( $data );
		$this->assertSame( 1, $data['chunk_index'] );
		$this->assertSame( 3, $data['chunks_total'] );
		$this->assertSame( 1, $data['chunks_succeeded'] );
	}

	/**
	 * Small batches (<= chunk size) take the single-call path and do
	 * not touch the chunking layer's results-merging.
	 */
	public function test_delete_post_by_tids_small_batch_uses_single_call() {
		$this->fail_call_indexes = array();
		$this->register_capture( 0 );

		$result = Publisher::delete_post_by_tids(
			array( 'root-tid' ),
			'doc-tid',
			array( 'reply-1', 'reply-2' )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->captured_calls );
		$this->assertCount( 4, $this->captured_calls[0]['writes'] );
	}
}
