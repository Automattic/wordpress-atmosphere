<?php
/**
 * Tests for the Post transformer (bsky.app record composition).
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

use WP_UnitTestCase;
use Atmosphere\Transformer\Post;

/**
 * Post transformer tests.
 *
 * @coversDefaultClass \Atmosphere\Transformer\Post
 */
class Test_Post extends WP_UnitTestCase {

	/**
	 * Tear down filters between tests so overrides don't leak.
	 */
	public function tear_down() {
		\remove_all_filters( 'atmosphere_is_short_form_post' );
		parent::tear_down();
	}

	/**
	 * A titled post with no post format uses the long-form path:
	 * title + excerpt + permalink as text, plus an external embed card.
	 *
	 * @covers ::transform
	 */
	public function test_long_form_titled_no_format_has_external_embed() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Long-form blog body.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'app.bsky.feed.post', $record['$type'] );
		$this->assertStringContainsString( 'A Titled Post', $record['text'] );
		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
	}

	/**
	 * An untitled post is short-form: body becomes the text, no embed.
	 *
	 * @covers ::transform
	 */
	public function test_untitled_post_is_short_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'A quick untitled thought.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'A quick untitled thought.', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * A titled post with post_format=status is short-form.
	 *
	 * @covers ::transform
	 */
	public function test_titled_post_with_status_format_is_short_form() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Has a title but also a format',
				'post_content' => 'Short-form body despite the title.',
			)
		);
		\set_post_format( $post_id, 'status' );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'Short-form body despite the title.', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * Any post format triggers short-form, not just status.
	 *
	 * @covers ::transform
	 */
	public function test_titled_post_with_aside_format_is_short_form() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Has a title',
				'post_content' => 'An aside.',
			)
		);
		\set_post_format( $post_id, 'aside' );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'An aside.', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * Short-form text over 300 graphemes is truncated.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_truncates_over_cap() {
		$long_body = \str_repeat( 'Lorem ipsum dolor sit amet. ', 50 );
		$post      = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => $long_body,
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertLessThanOrEqual( 300, \mb_strlen( $record['text'] ) );
		$this->assertStringContainsString( 'Lorem', $record['text'] );
	}

	/**
	 * The atmosphere_is_short_form_post filter can force short-form on a
	 * titled-no-format post that would otherwise be long-form.
	 *
	 * @covers ::transform
	 */
	public function test_filter_can_force_short_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body overridden to short-form.',
			)
		);

		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'Body overridden to short-form.', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * The filter can force long-form on an untitled post that would
	 * otherwise be short-form.
	 *
	 * @covers ::transform
	 */
	public function test_filter_can_force_long_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'Would be short-form by default.',
			)
		);

		\add_filter( 'atmosphere_is_short_form_post', '__return_false' );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
	}

	/**
	 * The filter receives the computed default and the post.
	 *
	 * @covers ::transform
	 */
	public function test_filter_receives_computed_default_and_post() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body.',
			)
		);

		$received_default = null;
		$received_post_id = null;
		$callback         = function ( $is_short, $filter_post ) use ( &$received_default, &$received_post_id ) {
			$received_default = $is_short;
			$received_post_id = $filter_post->ID;
			return $is_short;
		};

		\add_filter( 'atmosphere_is_short_form_post', $callback, 10, 2 );

		( new Post( $post ) )->transform();

		$this->assertFalse( $received_default, 'Default for titled-no-format post should be false (long-form).' );
		$this->assertSame( $post->ID, $received_post_id, 'Filter should receive the post being transformed.' );
	}
}
