<?php
/**
 * Tests for the reaction sync engine.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group reaction-sync
 */

namespace Atmosphere\Tests;

use WP_UnitTestCase;
use Atmosphere\Reaction_Sync;
use Atmosphere\Transformer\Post as BskyPost;

/**
 * Reaction sync tests.
 */
class Test_Reaction_Sync extends WP_UnitTestCase {

	/**
	 * Test that find_post_by_bsky_uri returns the correct post.
	 */
	public function test_find_post_by_bsky_uri() {
		$post_id = self::factory()->post->create();
		$uri     = 'at://did:plc:test123/app.bsky.feed.post/abc123';

		\update_post_meta( $post_id, BskyPost::META_URI, $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );
		$method->setAccessible( true );

		$this->assertSame( $post_id, $method->invoke( null, $uri ) );
	}

	/**
	 * Test that find_post_by_bsky_uri returns false for unknown URI.
	 */
	public function test_find_post_by_bsky_uri_not_found() {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( null, 'at://did:plc:unknown/app.bsky.feed.post/xyz' ) );
	}

	/**
	 * Test that find_comment_by_source_id returns the correct comment.
	 */
	public function test_find_comment_by_source_id() {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$uri        = 'at://did:plc:reply/app.bsky.feed.post/reply123';

		\update_comment_meta( $comment_id, 'source_id', $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_comment_by_source_id' );
		$method->setAccessible( true );

		$this->assertSame( $comment_id, $method->invoke( null, $uri ) );
	}

	/**
	 * Test that find_comment_by_source_id returns false for unknown URI.
	 */
	public function test_find_comment_by_source_id_not_found() {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_comment_by_source_id' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( null, 'at://did:plc:unknown/app.bsky.feed.post/xyz' ) );
	}

	/**
	 * Test that process_reply skips duplicate URIs.
	 */
	public function test_process_reply_skips_duplicates() {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$uri        = 'at://did:plc:author/app.bsky.feed.post/reply456';

		\update_comment_meta( $comment_id, 'source_id', $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );
		$method->setAccessible( true );

		$notification = array(
			'uri'    => $uri,
			'cid'    => 'bafyrei123',
			'record' => array(
				'text'  => 'Duplicate reply',
				'reply' => array(
					'parent' => array( 'uri' => 'at://did:plc:me/app.bsky.feed.post/orig' ),
					'root'   => array( 'uri' => 'at://did:plc:me/app.bsky.feed.post/orig' ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:author',
				'handle' => 'author.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification ) );
	}

	/**
	 * Test that process_reply creates a comment for a direct reply.
	 */
	public function test_process_reply_creates_comment() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );
		$method->setAccessible( true );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/reply789',
			'cid'    => 'bafyrei456',
			'record' => array(
				'text'      => 'Great post!',
				'createdAt' => '2026-03-21T12:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( 'Great post!', $comment->comment_content );
		$this->assertSame( 'comment', $comment->comment_type );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
		$this->assertSame( '0', $comment->comment_parent );

		// Check meta.
		$this->assertSame(
			'atproto',
			\get_comment_meta( $comment_id, 'protocol', true )
		);
		$this->assertSame(
			'at://did:plc:replier/app.bsky.feed.post/reply789',
			\get_comment_meta( $comment_id, 'source_id', true )
		);
		$this->assertSame(
			'https://bsky.app/profile/replier.bsky.social/post/reply789',
			\get_comment_meta( $comment_id, 'source_url', true )
		);
		$this->assertSame(
			'did:plc:replier',
			\get_comment_meta( $comment_id, '_atmosphere_author_did', true )
		);
	}

	/**
	 * Test that process_reply handles nested replies.
	 */
	public function test_process_reply_nested() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		// Create a parent comment.
		$parent_comment_id = self::factory()->comment->create(
			array( 'comment_post_ID' => $post_id )
		);
		$parent_reply_uri  = 'at://did:plc:first/app.bsky.feed.post/firstreply';

		\update_comment_meta( $parent_comment_id, 'source_id', $parent_reply_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );
		$method->setAccessible( true );

		$notification = array(
			'uri'    => 'at://did:plc:second/app.bsky.feed.post/nestedreply',
			'cid'    => 'bafyrei789',
			'record' => array(
				'text'      => 'Nested reply!',
				'createdAt' => '2026-03-21T13:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $parent_reply_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:second',
				'handle' => 'second.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( (string) $parent_comment_id, $comment->comment_parent );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
	}

	/**
	 * Test that process_reply skips when no matching post is found.
	 */
	public function test_process_reply_skips_unmatched() {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );
		$method->setAccessible( true );

		$notification = array(
			'uri'    => 'at://did:plc:someone/app.bsky.feed.post/orphan',
			'cid'    => 'bafyrei000',
			'record' => array(
				'text'  => 'Reply to unknown post',
				'reply' => array(
					'parent' => array( 'uri' => 'at://did:plc:unknown/app.bsky.feed.post/nope' ),
					'root'   => array( 'uri' => 'at://did:plc:unknown/app.bsky.feed.post/nope' ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:someone',
				'handle' => 'someone.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification ) );
	}

	/**
	 * Test that empty text replies are skipped.
	 */
	public function test_process_reply_skips_empty_text() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost2';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );
		$method->setAccessible( true );

		$notification = array(
			'uri'    => 'at://did:plc:empty/app.bsky.feed.post/emptyreply',
			'cid'    => 'bafyrei111',
			'record' => array(
				'text'  => '',
				'reply' => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:empty',
				'handle' => 'empty.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification ) );
	}
}
