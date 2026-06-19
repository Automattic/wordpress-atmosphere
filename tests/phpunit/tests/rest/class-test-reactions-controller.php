<?php
/**
 * Tests for the reactions REST controller.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests\Rest;

use Atmosphere\Rest\Reactions_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Tests for the reactions REST endpoint.
 *
 * @group atmosphere
 * @group rest
 */
class Test_Reactions_Controller extends WP_UnitTestCase {

	/**
	 * Register the route on the test REST server.
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter( 'rest_url', '__return_empty_string', 0 );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		( new Reactions_Controller() )->register_routes();
	}

	/**
	 * Tear down the REST server.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		\remove_filter( 'rest_url', '__return_empty_string', 0 );
		parent::tear_down();
	}

	/**
	 * Insert an approved reaction comment.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Comment type.
	 * @param string $author  Author name.
	 * @return void
	 */
	private function add_reaction( int $post_id, string $type, string $author ): void {
		\wp_insert_comment(
			array(
				'comment_post_ID'    => $post_id,
				'comment_type'       => $type,
				'comment_approved'   => 1,
				'comment_author'     => $author,
				'comment_author_url' => 'https://bsky.app/profile/' . \sanitize_title( $author ) . '.bsky.social',
			)
		);
	}

	/**
	 * Dispatch a GET to the reactions route for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_REST_Response
	 */
	private function get_reactions( int $post_id ) {
		$request = new WP_REST_Request( 'GET', "/atmosphere/v1/posts/{$post_id}/reactions" );
		return \rest_get_server()->dispatch( $request );
	}

	/**
	 * The endpoint returns likes and reposts with counts and reactor items.
	 */
	public function test_returns_reactions() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->add_reaction( $post->ID, 'like', 'Alice' );
		$this->add_reaction( $post->ID, 'like', 'Bob' );
		$this->add_reaction( $post->ID, 'repost', 'Carol' );

		$data = $this->get_reactions( $post->ID )->get_data();

		$this->assertArrayHasKey( 'like', $data );
		$this->assertArrayHasKey( 'repost', $data );
		$this->assertSame( 2, $data['like']['count'] );
		$this->assertSame( 1, $data['repost']['count'] );
		$this->assertSame( '2 likes', $data['like']['label'] );
		$this->assertCount( 2, $data['like']['items'] );
		$names = \wp_list_pluck( $data['like']['items'], 'name' );
		$this->assertContains( 'Alice', $names );
		$this->assertContains( 'Bob', $names );
		$this->assertArrayHasKey( 'avatar', $data['like']['items'][0] );
	}

	/**
	 * A post with no reactions returns an empty object.
	 */
	public function test_returns_empty_without_reactions() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		$response = $this->get_reactions( $post->ID );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	/**
	 * A non-public post is not exposed.
	 */
	public function test_non_public_post_is_404() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		$this->add_reaction( $post->ID, 'like', 'Alice' );

		$response = $this->get_reactions( $post->ID );

		$this->assertSame( 404, $response->get_status() );
	}
}
