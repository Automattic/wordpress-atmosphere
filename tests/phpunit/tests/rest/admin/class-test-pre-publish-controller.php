<?php
/**
 * Tests for the pre-publish preview REST controller.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group rest
 */

namespace Atmosphere\Tests\REST\Admin;

use Atmosphere\REST\Admin\Pre_Publish_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Pre-publish preview controller tests.
 *
 * @coversDefaultClass \Atmosphere\REST\Admin\Pre_Publish_Controller
 */
class Test_Pre_Publish_Controller extends WP_UnitTestCase {

	/**
	 * Controller under test.
	 *
	 * @var Pre_Publish_Controller
	 */
	private $controller;

	/**
	 * Put the site into a connected, auto-publishing state.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->controller = new Pre_Publish_Controller();

		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'me.example.com',
				'access_token' => 'test-token',
			)
		);
		\update_option( 'atmosphere_auto_publish', '1' );
	}

	/**
	 * Reset connection + settings between tests.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_auto_publish' );
		\delete_option( 'atmosphere_support_post_types' );
		\remove_all_filters( 'atmosphere_long_form_composition' );
		parent::tear_down();
	}

	/**
	 * Build a preview request for a post with optional editor overrides.
	 *
	 * @param int   $post_id   Target post.
	 * @param array $overrides title/content/excerpt overrides.
	 * @return WP_REST_Request
	 */
	private function make_request( int $post_id, array $overrides = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/atmosphere/1.0/admin/pre-publish-preview' );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'title', $overrides['title'] ?? '' );
		$request->set_param( 'content', $overrides['content'] ?? '' );
		$request->set_param( 'excerpt', $overrides['excerpt'] ?? '' );
		$request->set_param( 'status', $overrides['status'] ?? 'publish' );
		$request->set_param( 'password', $overrides['password'] ?? '' );

		return $request;
	}

	/**
	 * The controller registers its route under the admin `atmosphere/1.0`
	 * namespace — not the public `atmosphere/v1` one.
	 *
	 * @covers ::register_routes
	 */
	public function test_route_is_registered_under_admin_namespace() {
		\do_action( 'rest_api_init' );
		$this->controller->register_routes();

		$routes = \rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/atmosphere/1.0/admin/pre-publish-preview', $routes );
	}

	/**
	 * A connected site with auto-publish on reports will_publish=true and
	 * the projected short-form strategy + count for the unsaved body.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_connected_short_form() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => '' ) );

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'A quick note.' ) )
		)->get_data();

		$this->assertTrue( $data['will_publish'] );
		$this->assertNull( $data['reason'] );
		$this->assertTrue( $data['is_short_form'] );
		$this->assertSame( 'short-form', $data['strategy'] );
		$this->assertSame( 300, $data['limit'] );
		$this->assertCount( 1, $data['records'] );
		$this->assertSame( 13, $data['records'][0]['graphemes'] );
		$this->assertFalse( $data['records'][0]['over_limit'] );
	}

	/**
	 * A draft (not yet published) reports the real character count, not a
	 * redacted zero. The transformer redacts non-published posts, so the
	 * controller must project the draft as if it were published — this is
	 * the panel's primary use case (composing a brand-new post). Regression
	 * test: without the status override the count is always 0.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_projects_draft_as_publishable() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'  => '',
				'post_status' => 'draft',
			)
		);

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'A quick note.' ) )
		)->get_data();

		$this->assertSame( 'short-form', $data['strategy'] );
		$this->assertSame( 13, $data['records'][0]['graphemes'] );
	}

	/**
	 * The projection reflects the *unsaved* editor content, not the saved
	 * revision — an over-limit body sent with the request reports over_limit
	 * even though the post was saved short.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_uses_unsaved_content() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'short',
			)
		);

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => \str_repeat( 'word ', 100 ) ) )
		)->get_data();

		$this->assertGreaterThan( 300, $data['records'][0]['graphemes'] );
		$this->assertTrue( $data['records'][0]['over_limit'] );
	}

	/**
	 * A disconnected site reports will_publish=false with a reason and still
	 * returns a projection.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_disconnected_reports_reason() {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );

		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'Hi.' ) )
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertNotNull( $data['reason'] );
		$this->assertArrayHasKey( 'strategy', $data );
	}

	/**
	 * Auto-publish disabled is surfaced as a distinct reason.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_auto_publish_off_reports_reason() {
		\update_option( 'atmosphere_auto_publish', '0' );

		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'Hi.' ) )
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertStringContainsString( 'turned off', $data['reason'] );
	}

	/**
	 * An unsupported post type reports will_publish=false.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_unsupported_post_type_reports_reason() {
		\update_option( 'atmosphere_support_post_types', array( 'post' ) );

		$page = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );

		$data = $this->controller->get_preview(
			$this->make_request( $page->ID, array( 'content' => 'Hi.' ) )
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertNotNull( $data['reason'] );
	}

	/**
	 * A post the author intends to keep private is not shared, even though
	 * the saved post is published — the decision reads the intended editor
	 * visibility, not the stored status.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_private_visibility_reports_reason() {
		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content' => 'Secret.',
					'status'  => 'private',
				)
			)
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertStringContainsString( 'Private', $data['reason'] );
	}

	/**
	 * A post the author just password-protected in the editor (unsaved) is
	 * not shared, even though the saved post has no password.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_password_protected_reports_reason() {
		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content'  => 'Members only.',
					'password' => 'hunter2',
				)
			)
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertStringContainsString( 'Password', $data['reason'] );
	}

	/**
	 * A missing post yields a 404 WP_Error rather than fataling.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_missing_post_returns_error() {
		$result = $this->controller->get_preview( $this->make_request( 99999999 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_post_not_found', $result->get_error_code() );
	}

	/**
	 * The permission callback denies a user who cannot edit the post.
	 *
	 * @covers ::check_permission
	 */
	public function test_check_permission_denies_subscriber() {
		$post       = self::factory()->post->create();
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		\wp_set_current_user( $subscriber );

		$result = $this->controller->check_permission( $this->make_request( $post ) );

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * The permission callback allows an editor of the post.
	 *
	 * @covers ::check_permission
	 */
	public function test_check_permission_allows_editor() {
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );

		\wp_set_current_user( $author );

		$this->assertTrue( $this->controller->check_permission( $this->make_request( $post ) ) );
	}
}
