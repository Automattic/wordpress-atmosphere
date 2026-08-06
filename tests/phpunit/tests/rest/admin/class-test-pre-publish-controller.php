<?php
/**
 * Tests for the pre-publish preview REST controller.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group rest
 */

namespace Atmosphere\Tests\Rest\Admin;

use Atmosphere\OAuth\Client;
use Atmosphere\Rest\Admin\Pre_Publish_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Pre-publish preview controller tests.
 *
 * @coversDefaultClass \Atmosphere\Rest\Admin\Pre_Publish_Controller
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
		\delete_option( Client::DISCONNECTED_OPTION );
		\remove_all_filters( 'atmosphere_long_form_composition' );
		\remove_all_filters( 'atmosphere_connection_only_mode' );
		\wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Log in as an administrator, so the reconnect reason resolves the
	 * specific (rather than the generic, non-admin) cause sentence.
	 */
	private function login_as_admin(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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
		$request->set_param( 'disabled', $overrides['disabled'] ?? false );
		$request->set_param( 'customText', $overrides['customText'] ?? '' );

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
		$this->assertSame( 13, $data['records'][0]['characters'] );
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
		$this->assertSame( 13, $data['records'][0]['characters'] );
	}

	/**
	 * The projection reflects the *unsaved* editor content, not the saved
	 * revision — an over-limit body sent with the request reports over_limit
	 * even though the post was saved short.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_uses_unsaved_content() {
		// Keep the over-limit body short-form so the panel still reports the
		// over-limit warning; by default an overflowing titleless body is now
		// reclassified to long-form.
		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'short',
			)
		);

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => \str_repeat( 'word ', 100 ) ) )
		)->get_data();

		\remove_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$this->assertGreaterThan( 300, $data['records'][0]['characters'] );
		$this->assertTrue( $data['records'][0]['over_limit'] );
	}

	/**
	 * Unsaved custom text drives the preview: the projector reports the
	 * `custom-text` strategy and counts the typed text, not the saved body.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_uses_unsaved_custom_text() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'The saved body.',
			)
		);

		$data = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content'    => 'The saved body.',
					'customText' => 'My own Bluesky words.',
				)
			)
		)->get_data();

		$this->assertTrue( $data['will_publish'] );
		$this->assertSame( 'custom-text', $data['strategy'] );
		$this->assertCount( 1, $data['records'] );
		$this->assertSame( 21, $data['records'][0]['characters'] );
	}

	/**
	 * When the request omits `customText` (e.g. an older/cached editor that
	 * predates the field), the projector falls back to the saved meta rather
	 * than forcing the default composition with a cast-from-missing empty
	 * string — so a post with saved custom text still previews as custom text.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_without_custom_text_param_reads_saved_meta() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body.',
			)
		);
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'Saved custom words.' );

		// Build a request that deliberately omits the customText param.
		$request = new WP_REST_Request( 'POST', '/atmosphere/1.0/admin/pre-publish-preview' );
		$request->set_param( 'id', $post->ID );
		$request->set_param( 'title', 'A Titled Post' );
		$request->set_param( 'content', 'Body.' );
		$request->set_param( 'status', 'publish' );

		$data = $this->controller->get_preview( $request )->get_data();

		$this->assertSame( 'custom-text', $data['strategy'] );
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
	 * When auto-publish is off, a dead connection is beside the point:
	 * reconnecting would not change whether this post publishes, so the
	 * auto-publish-off reason wins over a reconnect prompt and no reconnect
	 * is asked for.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_auto_publish_off_ignores_needs_reauth() {
		\update_option( 'atmosphere_auto_publish', '0' );
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'access_token' => 'test-token',
				'needs_reauth' => true,
			)
		);

		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'Hi.' ) )
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertFalse( $data['needs_reconnect'] );
		$this->assertStringContainsString( 'turned off', $data['reason'] );
	}

	/**
	 * Connection-only mode forces auto-publish off, so the preview reports
	 * will_publish=false even with the stored auto-publish option on.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_connection_only_mode_reports_not_publishing() {
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );

		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'Hi.' ) )
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertNotNull( $data['reason'] );
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
	 * A post the author switched sharing off for (the document-panel toggle,
	 * unsaved) is reported as not shared.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_disabled_toggle_reports_reason() {
		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content'  => 'Not this one.',
					'disabled' => true,
				)
			)
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertStringContainsString( 'switched off', $data['reason'] );
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

	/**
	 * An expired session is reported as reconnectable, not as a site that was
	 * never connected. The panel raises the notice to a warning off this flag.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_expired_session_reports_needs_reconnect() {
		$this->login_as_admin();
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'access_token' => 'test-token',
				'needs_reauth' => true,
			)
		);

		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'Hi.' ) )
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertTrue( $data['needs_reconnect'] );
		$this->assertStringContainsString( 'expired', $data['reason'] );
	}

	/**
	 * A never-connected site keeps its own copy and is not offered a reconnect.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_never_connected_does_not_ask_for_reconnect() {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );

		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'Hi.' ) )
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertFalse( $data['needs_reconnect'] );
	}

	/**
	 * Reasons that have nothing to do with the connection never ask for a
	 * reconnect, so the panel keeps them at info level.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_unrelated_reason_does_not_ask_for_reconnect() {
		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content'  => 'Hi.',
					'disabled' => true,
				)
			)
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertFalse( $data['needs_reconnect'] );
	}

	/**
	 * An operator who deliberately disconnected the site is told so, not that
	 * their session expired — matching the swap
	 * {@see \Atmosphere\reauth_lead_for_current_user()} makes for the document
	 * panel.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_operator_disconnected_reports_disconnected_reason() {
		$this->login_as_admin();
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\delete_option( 'atmosphere_connection' );
		\update_option( Client::DISCONNECTED_OPTION, true );

		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'Hi.' ) )
		)->get_data();

		$this->assertTrue( $data['needs_reconnect'] );
		$this->assertStringContainsString( 'disconnected', $data['reason'] );
	}

	/**
	 * `needs_reconnect` tracks whether a cause is actually shown, not just
	 * whether the connection is dead: a non-admin on an operator-initiated
	 * disconnect gets `false` (the lead is suppressed for them, matching the
	 * document panel showing no banner), while an administrator on the same
	 * disconnected site gets `true`.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_needs_reconnect_follows_capability_on_operator_disconnect() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\delete_option( 'atmosphere_connection' );
		\update_option( Client::DISCONNECTED_OPTION, true );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		\wp_set_current_user( $author );

		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'Hi.' ) )
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertFalse( $data['needs_reconnect'] );

		$this->login_as_admin();

		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'Hi.' ) )
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertTrue( $data['needs_reconnect'] );
	}

	/**
	 * Order pin: the connection check runs after the password, private, and
	 * post-type checks, so a private post on a `needs_reauth`-flagged site
	 * reports the private reason, not a reconnect prompt that reconnecting
	 * would do nothing to fix.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_private_wins_over_needs_reauth() {
		$this->login_as_admin();
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'access_token' => 'test-token',
				'needs_reauth' => true,
			)
		);

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
		$this->assertFalse( $data['needs_reconnect'] );
		$this->assertStringContainsString( 'Private', $data['reason'] );
	}

	/**
	 * Order pin: when auto-publish is off AND the post's own per-post toggle
	 * is also off, the auto-publish reason wins. The document panel (home of
	 * the per-post toggle) isn't even enqueued when auto-publish is off, so a
	 * "sharing switched off for this post" reason would point at UI that
	 * isn't on screen.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_auto_publish_off_wins_over_disabled_toggle() {
		\update_option( 'atmosphere_auto_publish', '0' );

		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content'  => 'Hi.',
					'disabled' => true,
				)
			)
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertStringContainsString( 'turned off', $data['reason'] );
	}

	/**
	 * Order pin: with auto-publish on (the default), the per-post toggle
	 * reason still fires and reports no reconnect is needed — the toggle
	 * check runs before the connection check.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_disabled_toggle_reports_reason_when_auto_publish_enabled() {
		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content'  => 'Hi.',
					'disabled' => true,
				)
			)
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertFalse( $data['needs_reconnect'] );
		$this->assertStringContainsString( 'switched off', $data['reason'] );
	}

	/**
	 * A post with sharing switched off is reported that way even when the
	 * site's connection also needs a reconnect — the toggle-off reason wins
	 * and the panel doesn't raise a reconnect call to action for it, matching
	 * the document panel's `shareHelpText( false, true )` behavior.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_disabled_toggle_ignores_needs_reauth() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
		\update_option(
			'atmosphere_connection',
			array(
				'did'          => 'did:plc:test123',
				'access_token' => 'test-token',
				'needs_reauth' => true,
			)
		);

		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content'  => 'Hi.',
					'disabled' => true,
				)
			)
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertFalse( $data['needs_reconnect'] );
		$this->assertStringContainsString( 'switched off', $data['reason'] );
	}

	/**
	 * Making a shared post private removes its Bluesky record, and that is
	 * not undoable: republishing mints a new record, so the reactions on the
	 * old one are gone. "Private posts aren't shared" reads like a no-op, so
	 * the reason has to say what actually happens.
	 */
	public function test_private_post_with_a_record_warns_about_removal() {
		$post = self::factory()->post->create_and_get();
		\update_post_meta( $post->ID, \Atmosphere\Transformer\Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/abc' );

		$data = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content' => 'Hi.',
					'status'  => 'private',
				)
			)
		)->get_data();

		$this->assertFalse( $data['will_publish'] );
		$this->assertStringContainsString( 'will be removed', $data['reason'] );
	}

	/**
	 * A post that was never shared has nothing to lose, so the reason stays
	 * as it was.
	 */
	public function test_private_post_without_a_record_says_nothing_about_removal() {
		$post = self::factory()->post->create_and_get();

		$data = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content' => 'Hi.',
					'status'  => 'private',
				)
			)
		)->get_data();

		$this->assertStringNotContainsString( 'will be removed', $data['reason'] );
	}

	/**
	 * Same for the per-post toggle and for a password, which take the same
	 * removal branch.
	 */
	public function test_other_removal_paths_warn_too() {
		$post = self::factory()->post->create_and_get();
		\update_post_meta( $post->ID, \Atmosphere\Transformer\Post::META_URI, 'at://did:plc:test123/app.bsky.feed.post/abc' );

		$disabled = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content'  => 'Hi.',
					'disabled' => true,
				)
			)
		)->get_data();
		$this->assertStringContainsString( 'will be removed', $disabled['reason'] );

		$password = $this->controller->get_preview(
			$this->make_request(
				$post->ID,
				array(
					'content'  => 'Hi.',
					'password' => 'secret',
				)
			)
		)->get_data();
		$this->assertStringContainsString( 'will be removed', $password['reason'] );
	}
}
