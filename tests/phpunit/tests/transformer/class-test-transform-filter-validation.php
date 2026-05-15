<?php
/**
 * Tests for `atmosphere_transform_*` filter return-shape validation.
 *
 * Every transformer applies a filter to its produced record before
 * publish. A third-party filter that returns a non-array would
 * otherwise propagate downstream and fatal inside `applyWrites`. The
 * transformers fall back to the pre-filter record when the filter
 * misbehaves.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

use WP_UnitTestCase;
use Atmosphere\Transformer\Comment;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Publication;

/**
 * Transform-filter validation tests.
 */
class Test_Transform_Filter_Validation extends WP_UnitTestCase {

	/**
	 * Remove any filters that tests in this class added.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_transform_bsky_post' );
		\remove_all_filters( 'atmosphere_transform_document' );
		\remove_all_filters( 'atmosphere_transform_publication' );
		\remove_all_filters( 'atmosphere_transform_comment' );

		parent::tear_down();
	}

	/**
	 * `Post::transform()` falls back to the pre-filter record when a
	 * third-party filter returns a non-array.
	 */
	public function test_post_transform_falls_back_when_filter_returns_non_array() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Hello',
				'post_content' => 'Body.',
				'post_status'  => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\add_filter( 'atmosphere_transform_bsky_post', static fn() => 'not-an-array' );

		$transformer = new Post( $post );
		$record      = $transformer->transform();

		$this->assertIsArray( $record );
		$this->assertSame( 'app.bsky.feed.post', $record['$type'] );
		$this->assertArrayHasKey( 'text', $record );
	}

	/**
	 * `Document::transform()` falls back when the filter returns null.
	 */
	public function test_document_transform_falls_back_on_null() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Hello',
				'post_status' => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\add_filter( 'atmosphere_transform_document', static fn() => null );

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertIsArray( $record );
		$this->assertSame( 'site.standard.document', $record['$type'] );
	}

	/**
	 * `Publication::transform()` falls back when the filter returns a
	 * scalar.
	 */
	public function test_publication_transform_falls_back_on_scalar() {
		\add_filter( 'atmosphere_transform_publication', static fn() => 42 );

		$transformer = new Publication();
		$record      = $transformer->transform();

		$this->assertIsArray( $record );
		$this->assertSame( 'site.standard.publication', $record['$type'] );
	}

	/**
	 * `Comment::transform()` falls back when the filter returns an
	 * object.
	 */
	public function test_comment_transform_falls_back_on_object() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Hello',
				'post_status' => 'publish',
			)
		);
		// Set Post URI + CID so the comment build_reply_ref doesn't bail.
		\update_post_meta( $post_id, Post::META_URI, 'at://did:plc:test/app.bsky.feed.post/abc' );
		\update_post_meta( $post_id, Post::META_CID, 'cidvalue' );

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Reply text.',
				'comment_approved' => '1',
				'user_id'          => 1,
			)
		);
		$comment    = \get_comment( $comment_id );

		\add_filter( 'atmosphere_transform_comment', static fn() => new \stdClass() );

		$transformer = new Comment( $comment );
		$record      = $transformer->transform();

		$this->assertIsArray( $record );
		$this->assertSame( 'app.bsky.feed.post', $record['$type'] );
	}

	/**
	 * A filter that returns a properly-shaped array IS used —
	 * validation must not block the legitimate extension point.
	 */
	public function test_post_transform_accepts_filtered_array() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Hello',
				'post_status' => 'publish',
			)
		);
		$post    = \get_post( $post_id );

		\add_filter(
			'atmosphere_transform_bsky_post',
			static function ( $record ) {
				$record['custom-key'] = 'custom-value';
				return $record;
			}
		);

		$transformer = new Post( $post );
		$record      = $transformer->transform();

		$this->assertSame( 'custom-value', $record['custom-key'] );
	}
}
