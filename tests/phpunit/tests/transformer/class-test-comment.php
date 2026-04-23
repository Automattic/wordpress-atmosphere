<?php
/**
 * Tests for the Comment transformer.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

use WP_UnitTestCase;
use Atmosphere\Reaction_Sync;
use Atmosphere\Transformer\Comment;
use Atmosphere\Transformer\Post;

/**
 * Comment transformer tests.
 *
 * @coversDefaultClass \Atmosphere\Transformer\Comment
 */
class Test_Comment extends WP_UnitTestCase {

	/**
	 * Post ID used as the reply root.
	 *
	 * @var int
	 */
	private int $post_id = 0;

	/**
	 * AT-URI of the root post.
	 *
	 * @var string
	 */
	private string $post_uri = 'at://did:plc:me/app.bsky.feed.post/rootpost';

	/**
	 * CID of the root post.
	 *
	 * @var string
	 */
	private string $post_cid = 'bafyreiroot';

	/**
	 * Seed a published post the test comments attach to.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->post_id = self::factory()->post->create();
		\update_post_meta( $this->post_id, Post::META_URI, $this->post_uri );
		\update_post_meta( $this->post_id, Post::META_CID, $this->post_cid );
	}

	/**
	 * Remove any filter overrides so they do not leak between tests.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_transform_comment' );
		parent::tear_down();
	}

	/**
	 * A top-level comment replies to the root post (root === parent).
	 *
	 * @covers ::transform
	 */
	public function test_top_level_comment_replies_to_post() {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_content' => 'Top-level comment.',
				'user_id'         => 1,
			)
		);

		$comment = \get_comment( $comment_id );
		$record  = ( new Comment( $comment ) )->transform();

		$this->assertSame( 'app.bsky.feed.post', $record['$type'] );
		$this->assertSame( 'Top-level comment.', $record['text'] );
		$this->assertSame( $this->post_uri, $record['reply']['root']['uri'] );
		$this->assertSame( $this->post_cid, $record['reply']['root']['cid'] );
		$this->assertSame( $this->post_uri, $record['reply']['parent']['uri'] );
		$this->assertSame( $this->post_cid, $record['reply']['parent']['cid'] );
	}

	/**
	 * A reply to a locally-published sibling comment uses the sibling's
	 * AT record as the parent ref.
	 *
	 * @covers ::transform
	 */
	public function test_reply_to_local_parent_uses_comment_uri() {
		$parent_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'user_id'         => 1,
			)
		);
		\update_comment_meta( $parent_id, Comment::META_URI, 'at://did:plc:me/app.bsky.feed.post/localparent' );
		\update_comment_meta( $parent_id, Comment::META_CID, 'bafylocal' );

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_parent'  => $parent_id,
				'comment_content' => 'Nested reply.',
				'user_id'         => 1,
			)
		);

		$record = ( new Comment( \get_comment( $child_id ) ) )->transform();

		$this->assertSame( $this->post_uri, $record['reply']['root']['uri'] );
		$this->assertSame( 'at://did:plc:me/app.bsky.feed.post/localparent', $record['reply']['parent']['uri'] );
		$this->assertSame( 'bafylocal', $record['reply']['parent']['cid'] );
	}

	/**
	 * A reply to a federated parent (ingested via Reaction_Sync) uses
	 * the source_id URI and stored bsky CID.
	 *
	 * @covers ::transform
	 */
	public function test_reply_to_federated_parent_uses_source_id() {
		$parent_id = self::factory()->comment->create(
			array( 'comment_post_ID' => $this->post_id )
		);
		\update_comment_meta( $parent_id, Reaction_Sync::META_PROTOCOL, 'atproto' );
		\update_comment_meta( $parent_id, Reaction_Sync::META_SOURCE_ID, 'at://did:plc:stranger/app.bsky.feed.post/federated' );
		\update_comment_meta( $parent_id, Reaction_Sync::META_BSKY_CID, 'bafyfederated' );

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_parent'  => $parent_id,
				'user_id'         => 1,
			)
		);

		$record = ( new Comment( \get_comment( $child_id ) ) )->transform();

		$this->assertSame( 'at://did:plc:stranger/app.bsky.feed.post/federated', $record['reply']['parent']['uri'] );
		$this->assertSame( 'bafyfederated', $record['reply']['parent']['cid'] );
	}

	/**
	 * A federated parent without a stored CID falls back to the root
	 * ref — AT Protocol strongRef requires both URI and CID, and an
	 * empty CID would be rejected by the PDS.
	 *
	 * @covers ::transform
	 */
	public function test_reply_to_federated_parent_without_cid_falls_back_to_root() {
		$parent_id = self::factory()->comment->create(
			array( 'comment_post_ID' => $this->post_id )
		);
		\update_comment_meta( $parent_id, Reaction_Sync::META_PROTOCOL, 'atproto' );
		\update_comment_meta( $parent_id, Reaction_Sync::META_SOURCE_ID, 'at://did:plc:stranger/app.bsky.feed.post/nocid' );
		// No META_BSKY_CID.

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_parent'  => $parent_id,
				'user_id'         => 1,
			)
		);

		$record = ( new Comment( \get_comment( $child_id ) ) )->transform();

		$this->assertSame( $this->post_uri, $record['reply']['parent']['uri'] );
		$this->assertSame( $this->post_cid, $record['reply']['parent']['cid'] );
	}

	/**
	 * A local parent published with URI but no stored CID (edge case
	 * after a PDS response that omitted CID) falls back to root.
	 *
	 * @covers ::transform
	 */
	public function test_reply_to_local_parent_without_cid_falls_back_to_root() {
		$parent_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'user_id'         => 1,
			)
		);
		\update_comment_meta( $parent_id, Comment::META_URI, 'at://did:plc:me/app.bsky.feed.post/localnocid' );
		// No META_CID.

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_parent'  => $parent_id,
				'user_id'         => 1,
			)
		);

		$record = ( new Comment( \get_comment( $child_id ) ) )->transform();

		$this->assertSame( $this->post_uri, $record['reply']['parent']['uri'] );
		$this->assertSame( $this->post_cid, $record['reply']['parent']['cid'] );
	}

	/**
	 * A reply whose parent has no AT metadata at all falls through to
	 * the root post ref so the reply still threads onto the post.
	 *
	 * @covers ::transform
	 */
	public function test_reply_to_unpublishable_parent_falls_back_to_root() {
		$parent_id = self::factory()->comment->create(
			array( 'comment_post_ID' => $this->post_id )
		);

		$child_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_parent'  => $parent_id,
				'user_id'         => 1,
			)
		);

		$record = ( new Comment( \get_comment( $child_id ) ) )->transform();

		$this->assertSame( $this->post_uri, $record['reply']['parent']['uri'] );
		$this->assertSame( $this->post_cid, $record['reply']['parent']['cid'] );
	}

	/**
	 * The rkey generates and persists a TID on first call, returning
	 * the same value on subsequent calls.
	 *
	 * @covers ::get_rkey
	 */
	public function test_get_rkey_generates_and_persists_tid() {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'user_id'         => 1,
			)
		);

		$transformer = new Comment( \get_comment( $comment_id ) );
		$first       = $transformer->get_rkey();
		$second      = $transformer->get_rkey();

		$this->assertNotEmpty( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, \get_comment_meta( $comment_id, Comment::META_TID, true ) );
	}

	/**
	 * The atmosphere_transform_comment filter can mutate the record.
	 *
	 * @covers ::transform
	 */
	public function test_filter_can_override_record() {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_content' => 'Will be overridden.',
				'user_id'         => 1,
			)
		);

		\add_filter(
			'atmosphere_transform_comment',
			static function ( array $record ) {
				$record['text'] = 'Replaced.';
				return $record;
			}
		);

		$record = ( new Comment( \get_comment( $comment_id ) ) )->transform();

		$this->assertSame( 'Replaced.', $record['text'] );
	}

	/**
	 * Long comment bodies are truncated to 300 characters (Bluesky cap).
	 *
	 * @covers ::transform
	 */
	public function test_long_content_is_truncated() {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_content' => \str_repeat( 'a ', 400 ),
				'user_id'         => 1,
			)
		);

		$record = ( new Comment( \get_comment( $comment_id ) ) )->transform();

		$this->assertLessThanOrEqual( 300, \mb_strlen( $record['text'] ) );
	}
}
