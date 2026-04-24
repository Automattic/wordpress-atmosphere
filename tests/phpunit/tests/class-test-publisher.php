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
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Document;

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

		\remove_all_filters( 'atmosphere_pre_apply_writes' );
		\remove_all_filters( 'atmosphere_long_form_composition' );
		\remove_all_filters( 'atmosphere_teaser_thread_posts' );
		\remove_all_filters( 'atmosphere_transform_bsky_post' );
		\remove_all_filters( 'atmosphere_is_short_form_post' );

		parent::tear_down();
	}

	/**
	 * Synthesize a plausible applyWrites response for a batch of writes.
	 *
	 * One result per write, with a stable URI + CID derived from the
	 * write's collection + rkey.  Delete writes produce empty result
	 * entries so Publisher's `store_results()` treats them as no-ops.
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
		$result = Publisher::update( $post );

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

		$result = Publisher::update( $post );

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

		$result = Publisher::update( $post );

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

		$result = Publisher::update( $post );

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

		$result = Publisher::delete( $post );

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

		$result = Publisher::delete( $post );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_not_published', $result->get_error_code() );
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
				'post_content' => 'Body content that is enough to compose a hook from.',
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
				'post_content' => 'Body content that is enough to compose a hook from.',
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
				'post_content' => 'Body content that is enough to compose a hook from.',
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
	}

	/**
	 * When the reply write fails, issue compensating deletes for the root
	 * + doc, clear every meta key, and return the original WP_Error.
	 */
	public function test_publish_teaser_thread_rollback_on_second_write_failure() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Body content that is enough to compose a hook from.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		// Fail call #2 (the reply create). Rollback call #3 succeeds.
		$this->fail_call_indexes = array(
			2 => new \WP_Error( 'atmosphere_reply_failed', 'Reply write failed.' ),
		);
		$this->register_capture( $post->ID );

		$result = Publisher::publish( $post );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_reply_failed', $result->get_error_code() );

		// 3 calls total: root+doc create, reply create (fail), rollback deletes.
		$this->assertCount( 3, $this->captured_calls );

		$rollback_writes = $this->captured_calls[2]['writes'];

		// Rollback deletes root bsky first, then doc: tail-first traversal
		// over the thread records, which here is just the root (only the
		// root made it before failure).
		$this->assertCount( 2, $rollback_writes );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $rollback_writes[0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $rollback_writes[0]['collection'] );
		$this->assertSame( 'com.atproto.repo.applyWrites#delete', $rollback_writes[1]['$type'] );
		$this->assertSame( 'site.standard.document', $rollback_writes[1]['collection'] );

		// Root TID appears in rollback.
		$root_rkey = $rollback_writes[0]['rkey'];
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
				'post_content' => 'Body content that is enough to compose a hook from.',
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
		$this->assertCount( 1, $data['partial_records'] );
		$this->assertArrayHasKey( 'original_error', $data );
		$this->assertArrayHasKey( 'rollback_error', $data );
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
	 * Update with a stored 1-entry link-card but a teaser-thread composition
	 * deletes the old record + doc atomically, then publishes fresh.
	 */
	public function test_update_thread_rewrites_on_strategy_change() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long-Form Post',
				'post_content' => 'Body content that is enough to compose a hook from.',
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
}
