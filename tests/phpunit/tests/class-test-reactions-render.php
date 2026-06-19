<?php
/**
 * Tests for the `atmosphere/reactions` block server-side render.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests;

use Atmosphere\Blocks;
use WP_UnitTestCase;

/**
 * Tests for the `atmosphere/reactions` block server-side render output.
 *
 * @group atmosphere
 * @group blocks
 */
class Test_Reactions_Render extends WP_UnitTestCase {

	/**
	 * Ensure the block (and its render callback) is registered. The plugin
	 * registers it on `init`; register defensively in case an earlier test
	 * left it unregistered.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'atmosphere/reactions' ) ) {
			Blocks::register_blocks();
		}
	}

	/**
	 * Reset global post state between tests.
	 */
	public function tear_down(): void {
		\wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * Insert an approved reaction comment of the given type.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Comment type (like/repost).
	 * @param string $author  Author display name.
	 * @return int Comment ID.
	 */
	private function add_reaction( int $post_id, string $type, string $author ): int {
		return (int) \wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_type'         => $type,
				'comment_approved'     => 1,
				'comment_author'       => $author,
				'comment_author_url'   => 'https://bsky.app/profile/' . \sanitize_title( $author ) . '.bsky.social',
				'comment_author_email' => '',
			)
		);
	}

	/**
	 * Render the block in the context of a given post.
	 *
	 * @param \WP_Post $post       Post to render against.
	 * @param string   $class_name Optional block className.
	 * @return string Rendered HTML.
	 */
	private function render_for( \WP_Post $post, string $class_name = '' ): string {
		// Set the global post so the block's render.php resolves get_the_ID();
		// this also lets the non-public test exercise the viewability guard
		// directly (go_to() would 404 a draft before reaching render.php).
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test render context.
		\setup_postdata( $post );

		$attrs = '' === $class_name ? '' : \sprintf( ' {"className":"%s"}', $class_name );
		$html  = \do_blocks( '<!-- wp:atmosphere/reactions' . $attrs . ' /-->' );

		\wp_reset_postdata();

		return $html;
	}

	/**
	 * A post with likes and reposts renders both facepile rows with counts.
	 */
	public function test_renders_likes_and_reposts() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		$this->add_reaction( $post->ID, 'like', 'Alice' );
		$this->add_reaction( $post->ID, 'like', 'Bob' );
		$this->add_reaction( $post->ID, 'repost', 'Carol' );

		$html = $this->render_for( $post );

		$this->assertStringContainsString( 'wp-block-atmosphere-reactions', $html );
		$this->assertStringContainsString( 'data-wp-interactive="atmosphere/reactions"', $html );
		$this->assertStringContainsString( 'data-reaction-type="like"', $html );
		$this->assertStringContainsString( 'data-reaction-type="repost"', $html );
		$this->assertStringContainsString( '2 likes', $html );
		$this->assertStringContainsString( '1 repost', $html );
		// The facepile template and the reactor data (in the interactivity
		// context) are present.
		$this->assertStringContainsString( 'reaction-avatars', $html );
		$this->assertStringContainsString( 'alice.bsky.social', $html );
	}

	/**
	 * A post with no reactions renders nothing.
	 */
	public function test_renders_nothing_without_reactions() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		$html = $this->render_for( $post );

		$this->assertStringNotContainsString( 'wp-block-atmosphere-reactions', $html );
	}

	/**
	 * Only one reaction type present renders only that row.
	 */
	public function test_renders_only_present_type() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->add_reaction( $post->ID, 'like', 'Alice' );

		$html = $this->render_for( $post );

		$this->assertStringContainsString( '1 like', $html );
		$this->assertStringNotContainsString( 'data-reaction-type="repost"', $html );
	}

	/**
	 * Reactions are not rendered for a non-public (draft) post.
	 */
	public function test_renders_nothing_for_non_public_post() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		$this->add_reaction( $post->ID, 'like', 'Alice' );

		$html = $this->render_for( $post );

		$this->assertStringNotContainsString( 'wp-block-atmosphere-reactions', $html );
	}

	/**
	 * The compact style renders counts without the avatar facepile.
	 */
	public function test_compact_style_hides_facepile() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->add_reaction( $post->ID, 'like', 'Alice' );

		$html = $this->render_for( $post, 'is-style-compact' );

		$this->assertStringContainsString( '1 like', $html );
		$this->assertStringNotContainsString( 'reaction-avatars', $html );
	}
}
