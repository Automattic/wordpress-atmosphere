<?php
/**
 * Tests that synced reactions are hidden from the comments section.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests;

use Atmosphere\Reaction_Sync;
use WP_Comment_Query;
use WP_UnitTestCase;

/**
 * Tests that synced reactions are hidden from the comments section.
 *
 * @group atmosphere
 */
class Test_Reaction_Comment_Display extends WP_UnitTestCase {

	/**
	 * Reset global query state between tests.
	 */
	public function tear_down(): void {
		\wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * Insert an approved comment of a given type.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Comment type.
	 * @return void
	 */
	private function add_comment( int $post_id, string $type ): void {
		\wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => $type,
				'comment_approved' => 1,
				'comment_author'   => 'Someone',
				'comment_content'  => 'comment' === $type ? 'A reply.' : '',
			)
		);
	}

	/**
	 * On a singular front-end view, the default comment query drops likes and
	 * reposts but keeps real replies.
	 */
	public function test_reactions_excluded_from_singular_comment_list() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->add_comment( $post->ID, 'comment' );
		$this->add_comment( $post->ID, 'like' );
		$this->add_comment( $post->ID, 'repost' );

		$this->go_to( \get_permalink( $post ) );
		$this->assertTrue( \is_singular() );

		$comments = \get_comments( array( 'post_id' => $post->ID ) );

		$this->assertCount( 1, $comments, 'Only the reply should appear in the comment list.' );
		$this->assertSame( 'comment', $comments[0]->comment_type );
	}

	/**
	 * A query that already constrains the type is left untouched (so the
	 * reactions block and admin can still read likes/reposts).
	 */
	public function test_typed_query_is_not_filtered() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->add_comment( $post->ID, 'like' );

		$this->go_to( \get_permalink( $post ) );

		$likes = \get_comments(
			array(
				'post_id' => $post->ID,
				'type'    => 'like',
			)
		);

		$this->assertCount( 1, $likes );
	}

	/**
	 * The exclusion does not apply off singular views.
	 */
	public function test_not_filtered_when_not_singular() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->add_comment( $post->ID, 'like' );

		$query    = new WP_Comment_Query();
		$comments = $query->query( array( 'post_id' => $post->ID ) );

		// Not on a singular view, so the like is not excluded.
		$this->assertCount( 1, $comments );
	}

	/**
	 * The post comment count excludes likes and reposts.
	 */
	public function test_comment_count_excludes_reactions() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->add_comment( $post->ID, 'comment' );
		$this->add_comment( $post->ID, 'like' );
		$this->add_comment( $post->ID, 'repost' );

		// Inserts above already triggered a recount through the filter.
		$this->assertSame( '1', (string) \get_comments_number( $post->ID ) );
	}
}
