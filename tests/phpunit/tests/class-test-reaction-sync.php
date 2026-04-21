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

	/**
	 * Test that process_like creates a like comment.
	 */
	public function test_process_like_creates_comment() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/likedpost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );
		$method->setAccessible( true );

		$notification = array(
			'uri'    => 'at://did:plc:liker/app.bsky.feed.like/like1',
			'cid'    => 'bafyreilike1',
			'record' => array(
				'createdAt' => '2026-03-21T14:00:00.000Z',
				'subject'   => array(
					'uri' => $post_uri,
					'cid' => 'bafyreimypost',
				),
			),
			'author' => array(
				'did'    => 'did:plc:liker',
				'handle' => 'liker.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification, 'like' );

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( '', $comment->comment_content );
		$this->assertSame( 'like', $comment->comment_type );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
		$this->assertSame( '0', $comment->comment_parent );

		$this->assertSame(
			'atproto',
			\get_comment_meta( $comment_id, 'protocol', true )
		);
		$this->assertSame(
			'at://did:plc:liker/app.bsky.feed.like/like1',
			\get_comment_meta( $comment_id, 'source_id', true )
		);
		$this->assertSame(
			'',
			\get_comment_meta( $comment_id, 'source_url', true )
		);
	}

	/**
	 * Test that process_like skips an unknown subject post.
	 */
	public function test_process_like_skips_unknown_subject() {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );
		$method->setAccessible( true );

		$notification = array(
			'uri'    => 'at://did:plc:liker/app.bsky.feed.like/like2',
			'cid'    => 'bafyreilike2',
			'record' => array(
				'subject' => array( 'uri' => 'at://did:plc:other/app.bsky.feed.post/notours' ),
			),
			'author' => array(
				'did'    => 'did:plc:liker',
				'handle' => 'liker.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification, 'like' ) );
	}

	/**
	 * Test that process_subject_reaction deduplicates on source_id.
	 */
	public function test_process_like_skips_duplicates() {
		$post_id     = self::factory()->post->create();
		$post_uri    = 'at://did:plc:me/app.bsky.feed.post/likedpost2';
		$like_uri    = 'at://did:plc:liker/app.bsky.feed.like/like3';
		$existing_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );
		\update_comment_meta( $existing_id, 'source_id', $like_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );
		$method->setAccessible( true );

		$notification = array(
			'uri'    => $like_uri,
			'record' => array( 'subject' => array( 'uri' => $post_uri ) ),
			'author' => array(
				'did'    => 'did:plc:liker',
				'handle' => 'liker.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification, 'like' ) );
	}

	/**
	 * Test that process_subject_reaction creates a repost comment.
	 */
	public function test_process_repost_creates_comment() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/repostedpost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );
		$method->setAccessible( true );

		$notification = array(
			'uri'    => 'at://did:plc:reposter/app.bsky.feed.repost/rep1',
			'cid'    => 'bafyreirepost1',
			'record' => array(
				'createdAt' => '2026-03-21T15:00:00.000Z',
				'subject'   => array(
					'uri' => $post_uri,
					'cid' => 'bafyreimypost',
				),
			),
			'author' => array(
				'did'    => 'did:plc:reposter',
				'handle' => 'reposter.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification, 'repost' );

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( '', $comment->comment_content );
		$this->assertSame( 'repost', $comment->comment_type );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );

		$this->assertSame(
			'at://did:plc:reposter/app.bsky.feed.repost/rep1',
			\get_comment_meta( $comment_id, 'source_id', true )
		);
		$this->assertSame(
			'',
			\get_comment_meta( $comment_id, 'source_url', true )
		);
		$this->assertSame(
			'did:plc:reposter',
			\get_comment_meta( $comment_id, '_atmosphere_author_did', true )
		);
	}

	/**
	 * Test that process_repost skips an unknown subject post.
	 */
	public function test_process_repost_skips_unknown_subject() {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );
		$method->setAccessible( true );

		$notification = array(
			'uri'    => 'at://did:plc:reposter/app.bsky.feed.repost/rep2',
			'record' => array(
				'subject' => array( 'uri' => 'at://did:plc:other/app.bsky.feed.post/notours' ),
			),
			'author' => array(
				'did'    => 'did:plc:reposter',
				'handle' => 'reposter.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification, 'repost' ) );
	}

	/**
	 * Seed a fake connection and profile cache so self-sync tests can
	 * call get_did() and resolve_author() without hitting the network.
	 *
	 * @param string $did    Fake self DID to store in atmosphere_connection.
	 * @param string $handle Fake self handle (also used as display name).
	 */
	private function seed_self_identity( string $did = 'did:plc:me', string $handle = 'me.bsky.social' ): void {
		\update_option(
			'atmosphere_connection',
			array( 'did' => $did ),
			false
		);

		\set_transient(
			'atmosphere_profile_' . \md5( $did ),
			array(
				'name'   => $handle,
				'handle' => $handle,
				'avatar' => 'https://example.com/avatar.jpg',
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Test that a self-like record on one of our posts becomes a like comment.
	 */
	public function test_process_own_record_like_on_our_post() {
		$this->seed_self_identity();

		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_own_record' );
		$method->setAccessible( true );

		$record = array(
			'uri'   => 'at://did:plc:me/app.bsky.feed.like/selflike1',
			'cid'   => 'bafyselflike1',
			'value' => array(
				'$type'     => 'app.bsky.feed.like',
				'createdAt' => '2026-04-20T14:00:00.000Z',
				'subject'   => array(
					'uri' => $post_uri,
					'cid' => 'bafymypost',
				),
			),
		);

		$comment_id = $method->invoke( null, $record, 'like' );

		$this->assertIsInt( $comment_id );
		$comment = \get_comment( $comment_id );
		$this->assertSame( 'like', $comment->comment_type );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
		$this->assertSame(
			'at://did:plc:me/app.bsky.feed.like/selflike1',
			\get_comment_meta( $comment_id, 'source_id', true )
		);
	}

	/**
	 * Test that a self-like on someone else's post is skipped.
	 */
	public function test_process_own_record_like_on_foreign_post_is_skipped() {
		$this->seed_self_identity();

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_own_record' );
		$method->setAccessible( true );

		$record = array(
			'uri'   => 'at://did:plc:me/app.bsky.feed.like/selflike2',
			'value' => array(
				'subject' => array( 'uri' => 'at://did:plc:somebodyelse/app.bsky.feed.post/theirs' ),
			),
		);

		$this->assertFalse( $method->invoke( null, $record, 'like' ) );
	}

	/**
	 * Test that a self-reply to our own post becomes a reply comment.
	 */
	public function test_process_own_record_reply_on_our_post() {
		$this->seed_self_identity();

		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost2';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_own_record' );
		$method->setAccessible( true );

		$record = array(
			'uri'   => 'at://did:plc:me/app.bsky.feed.post/selfreply1',
			'cid'   => 'bafyselfreply1',
			'value' => array(
				'$type'     => 'app.bsky.feed.post',
				'text'      => 'Replying to myself',
				'createdAt' => '2026-04-20T15:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
		);

		$comment_id = $method->invoke( null, $record, 'comment' );

		$this->assertIsInt( $comment_id );
		$comment = \get_comment( $comment_id );
		$this->assertSame( 'comment', $comment->comment_type );
		$this->assertSame( 'Replying to myself', $comment->comment_content );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
	}

	/**
	 * Test that a self-authored original post (no reply field) is skipped.
	 */
	public function test_process_own_record_original_post_is_skipped() {
		$this->seed_self_identity();

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_own_record' );
		$method->setAccessible( true );

		$record = array(
			'uri'   => 'at://did:plc:me/app.bsky.feed.post/originalpost',
			'value' => array(
				'$type' => 'app.bsky.feed.post',
				'text'  => 'A brand new top-level post',
			),
		);

		$this->assertFalse( $method->invoke( null, $record, 'comment' ) );
	}
}
