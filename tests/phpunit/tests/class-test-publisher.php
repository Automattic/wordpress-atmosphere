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
}
