<?php
/**
 * Tests for the shared transformer base class.
 *
 * `Transformer\Base::collect_tags()` collects the tag list once and
 * feeds both the `app.bsky.feed.post` and the `site.standard.document`,
 * so the records are asserted through the concrete transformers that
 * extend it.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;

/**
 * Transformer base class tests.
 */
class Test_Base extends \WP_UnitTestCase {

	/**
	 * Drop anything a test hooked on, so ordering between tests in this
	 * file (and the files after it) stays irrelevant.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_record_tags' );

		parent::tear_down();
	}

	/**
	 * Create a published post carrying the given tags.
	 *
	 * @param string[] $tags Tag names.
	 * @return \WP_Post
	 */
	private function post_with_tags( array $tags ): \WP_Post {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Hello',
				'post_content' => 'Body.',
				'post_status'  => 'publish',
			)
		);

		\wp_set_post_tags( $post->ID, $tags );

		return \get_post( $post->ID );
	}

	/**
	 * Baseline: tags are collected, "uncategorized" is left out, and the
	 * list is capped at 8 when nothing filters it.
	 */
	public function test_unfiltered_tags_are_capped_at_eight() {
		$post   = $this->post_with_tags( array( '1', 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8' ) );
		$record = ( new Document( $post ) )->transform();

		$this->assertCount( 8, $record['tags'] );
		$this->assertContains( '1', $record['tags'] );
		$this->assertNotContains( 'Uncategorized', $record['tags'] );
	}

	/**
	 * The filter runs before the cap: dropping one of nine tags leaves a
	 * full list of 8, not a short list of 7.
	 *
	 * This is the whole reason the hook exists. The record-level
	 * `atmosphere_transform_*` filters can already remove a tag, but
	 * they run after the slice.
	 */
	public function test_filter_runs_before_the_cap() {
		$post = $this->post_with_tags( array( '1', 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8' ) );

		\add_filter(
			'atmosphere_record_tags',
			static fn( array $tags ): array => \array_values( \array_diff( $tags, array( '1' ) ) )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertCount( 8, $record['tags'] );
		$this->assertNotContains( '1', $record['tags'] );
	}

	/**
	 * Both record types read the same filtered list, so a site only has
	 * to hook once.
	 */
	public function test_filter_applies_to_both_record_types() {
		$post = $this->post_with_tags( array( '1', 'keep' ) );

		\add_filter(
			'atmosphere_record_tags',
			static fn( array $tags ): array => \array_values( \array_diff( $tags, array( '1' ) ) )
		);

		$document = ( new Document( $post ) )->transform();
		$bsky     = ( new Post( $post ) )->transform();

		$this->assertSame( array( 'keep' ), $document['tags'] );
		$this->assertSame( array( 'keep' ), $bsky['tags'] );
	}

	/**
	 * The post is passed through, so a filter can scope itself to a
	 * single post type, author, or date range.
	 */
	public function test_filter_receives_the_post() {
		$post     = $this->post_with_tags( array( 'keep' ) );
		$received = null;

		\add_filter(
			'atmosphere_record_tags',
			static function ( array $tags, \WP_Post $filtered_post ) use ( &$received ): array {
				$received = $filtered_post;

				return $tags;
			},
			10,
			2
		);

		( new Document( $post ) )->transform();

		$this->assertInstanceOf( \WP_Post::class, $received );
		$this->assertSame( $post->ID, $received->ID );
	}

	/**
	 * Returning an empty list drops the field entirely rather than
	 * writing an empty array into the record.
	 */
	public function test_filter_can_remove_every_tag() {
		$post = $this->post_with_tags( array( '1' ) );

		\add_filter( 'atmosphere_record_tags', '__return_empty_array' );

		$record = ( new Document( $post ) )->transform();

		$this->assertArrayNotHasKey( 'tags', $record );
	}

	/**
	 * A filter that returns a non-array is reported and ignored, rather
	 * than propagating into the record and fataling inside applyWrites.
	 */
	public function test_non_array_return_falls_back_to_the_unfiltered_tags() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Transformer\\Base::collect_tags' );

		$post = $this->post_with_tags( array( 'keep' ) );

		\add_filter( 'atmosphere_record_tags', static fn() => 'not-an-array' );

		$record = ( new Document( $post ) )->transform();

		$this->assertSame( array( 'keep' ), $record['tags'] );
	}

	/**
	 * Whatever comes back is normalized before it reaches the record:
	 * non-strings are dropped, entries are trimmed, blanks are removed,
	 * and duplicates collapse.
	 */
	public function test_filter_return_value_is_normalized() {
		$post = $this->post_with_tags( array( 'keep' ) );

		\add_filter(
			'atmosphere_record_tags',
			static fn(): array => array(
				'  padded  ',
				'',
				'   ',
				42,
				null,
				array( 'nested' ),
				'dupe',
				'dupe',
			)
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertSame( array( 'padded', 'dupe' ), $record['tags'] );
	}

	/**
	 * A filter that adds tags cannot push a record past what the
	 * lexicons accept.
	 */
	public function test_filter_cannot_exceed_the_cap() {
		$post = $this->post_with_tags( array( 'keep' ) );

		\add_filter(
			'atmosphere_record_tags',
			static fn(): array => array( 't1', 't2', 't3', 't4', 't5', 't6', 't7', 't8', 't9', 't10' )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertCount( 8, $record['tags'] );
	}
}
