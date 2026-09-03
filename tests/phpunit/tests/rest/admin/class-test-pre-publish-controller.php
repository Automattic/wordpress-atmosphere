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
use Atmosphere\OAuth\DPoP;
use Atmosphere\OAuth\Encryption;
use Atmosphere\Rest\Admin\Pre_Publish_Controller;
use WP_REST_Request;
use WP_UnitTestCase;
use const Atmosphere\SESSION_VERIFIED_TRANSIENT;

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

		/*
		 * Real, decryptable credentials rather than a placeholder string:
		 * the endpoint now verifies the session with the PDS before it
		 * reads local connection state, so a fixture holding credentials
		 * nothing can decrypt is a *broken* connection, not a connected
		 * site, and every preview here would correctly report that.
		 */
		$dpop_jwk = DPoP::generate_key();

		\update_option(
			'atmosphere_connection',
			array(
				'did'            => 'did:plc:test123',
				'handle'         => 'me.example.com',
				'access_token'   => Encryption::encrypt( 'test-access-token' ),
				'refresh_token'  => Encryption::encrypt( 'test-refresh-token' ),
				'dpop_jwk'       => Encryption::encrypt( \wp_json_encode( $dpop_jwk ) ),
				'pds_endpoint'   => 'https://pds.example.com',
				'token_endpoint' => 'https://auth.example.com/oauth/token',
				'expires_at'     => \time() + 3600,
				'needs_reauth'   => false,
			)
		);
		\update_option( 'atmosphere_auto_publish', '1' );

		// A PDS that accepts the session, so the probe is a no-op here.
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				if ( false !== \strpos( $url, 'getSession' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => \wp_json_encode( array( 'did' => 'did:plc:test123' ) ),
					);
				}

				return $response;
			},
			1,
			3
		);
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
		\delete_transient( SESSION_VERIFIED_TRANSIENT );
		\remove_all_filters( 'pre_http_request' );
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
	 * A blank `customText` the client actually sent means the author cleared
	 * the textarea: the preview must project the default composition — what
	 * publish will do once the cleared meta is saved — not fall back to the
	 * stale saved custom text. Presence is read from the request payload
	 * itself, so this stays distinguishable from an omitted param.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_with_blank_custom_text_param_clears_saved_meta() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body.',
			)
		);
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'Saved custom words.' );

		// make_request() sends customText => '' — the cleared textarea.
		$data = $this->controller->get_preview(
			$this->make_request( $post->ID, array( 'content' => 'Body.' ) )
		)->get_data();

		$this->assertNotSame( 'custom-text', $data['strategy'] );
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
	 * The whole point of the probe: a session the user revoked from their
	 * Bluesky account is caught while the panel is still open.
	 *
	 * Local state cannot see this. The stored credentials are byte-for-byte
	 * what a working connection holds, so without asking the PDS the panel
	 * would promise a share that is already impossible. The probe runs the
	 * ordinary request path, the flag lands on the connection row, and the
	 * decision below reaches its existing reconnect branch — no new copy.
	 */
	public function test_revoked_session_is_caught_before_the_author_publishes() {
		$this->login_as_admin();

		// Replace the accepting PDS from set_up() with one that rejects.
		\remove_all_filters( 'pre_http_request' );
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				if ( false !== \strpos( $url, 'oauth/token' ) ) {
					return array(
						'response' => array( 'code' => 400 ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => \wp_json_encode( array( 'error' => 'invalid_grant' ) ),
					);
				}

				if ( false !== \strpos( $url, 'getSession' ) ) {
					return array(
						'response' => array( 'code' => 401 ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => \wp_json_encode( array( 'error' => 'InvalidToken' ) ),
					);
				}

				return $response;
			},
			1,
			3
		);

		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$data = \rest_do_request( $this->make_request( $post ) )->get_data();

		$this->assertFalse( $data['will_publish'], 'A revoked session must not promise a share.' );
		$this->assertTrue( $data['needs_reconnect'], 'The panel must offer the reconnect prompt.' );
		$this->assertNotEmpty( $data['reason'] );
	}

	/**
	 * A PDS we cannot reach is not a disconnection.
	 *
	 * Reporting one would block an author over our own inability to ask —
	 * a worse failure than the stale verdict the probe exists to fix.
	 */
	public function test_unreachable_pds_does_not_block_the_share() {
		$this->login_as_admin();

		\remove_all_filters( 'pre_http_request' );
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				if ( false !== \strpos( $url, 'getSession' ) ) {
					return new \WP_Error( 'http_request_failed', 'Connection timed out.' );
				}

				return $response;
			},
			1,
			3
		);

		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$data = \rest_do_request( $this->make_request( $post ) )->get_data();

		$this->assertTrue( $data['will_publish'], 'An unreachable PDS must not be read as a disconnection.' );
	}
}
