<?php
/**
 * Pre-publish preview REST controller.
 *
 * Projects, for the post currently open in the block editor, whether it
 * will publish to Bluesky, which strategy will run, and how its text
 * measures against the 300-character limit — all before the author clicks
 * Publish. Lives in the admin REST namespace; it is not a public route.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Rest\Admin;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Transformer\Post;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function Atmosphere\is_auto_publish_enabled;
use function Atmosphere\is_connected;
use function Atmosphere\is_supported_post_type;
use function Atmosphere\needs_reauth;
use function Atmosphere\share_status;

/**
 * Pre-publish preview controller.
 */
class Pre_Publish_Controller extends \WP_REST_Controller {

	/**
	 * The REST namespace for this controller's route.
	 *
	 * Deliberately separate from the public OAuth `atmosphere/v1`
	 * namespace: this is an authenticated, editor-only surface.
	 *
	 * @var string
	 */
	public const ROUTE_NAMESPACE = 'atmosphere/1.0';

	/**
	 * The base of this controller's route.
	 *
	 * @var string
	 */
	public const ROUTE_BASE = 'admin/pre-publish-preview';

	/**
	 * The namespace of this controller's route.
	 *
	 * @var string
	 */
	protected $namespace = self::ROUTE_NAMESPACE;

	/**
	 * The base of this controller's route.
	 *
	 * @var string
	 */
	protected $rest_base = self::ROUTE_BASE;

	/**
	 * The full REST path for the preview route, leading slash included.
	 *
	 * Single source of truth shared with the editor JS via
	 * {@see Block_Editor::script_data()} so the path is not duplicated.
	 *
	 * @return string
	 */
	public static function full_route(): string {
		return '/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE_BASE;
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_preview' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'show_in_index'       => false,
					'args'                => array(
						'id'          => array(
							'description'       => \__( 'The ID of the post being edited.', 'atmosphere' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'title'       => array(
							'description'       => \__( 'The unsaved post title.', 'atmosphere' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),

						/*
						 * `content` is intentionally not sanitized at the
						 * route layer: it is raw block markup, and the
						 * transformer renders it through `the_content` +
						 * `sanitize_text()` itself. `wp_kses_post()` would
						 * strip the `<!-- wp:* -->` block delimiters and
						 * corrupt the projection.
						 */
						'content'     => array(
							'description' => \__( 'The unsaved post content.', 'atmosphere' ),
							'type'        => 'string',
							'default'     => '',
						),
						'excerpt'     => array(
							'description'       => \__( 'The unsaved post excerpt.', 'atmosphere' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'      => array(
							'description'       => \__( 'The intended post status / visibility.', 'atmosphere' ),
							'type'              => 'string',
							'default'           => 'publish',
							'sanitize_callback' => 'sanitize_key',
						),
						'password'    => array(
							'description' => \__( 'The intended post password (empty when not protected).', 'atmosphere' ),
							'type'        => 'string',
							'default'     => '',
						),
						'disabled'    => array(
							'description' => \__( 'Whether sharing is switched off for this post.', 'atmosphere' ),
							'type'        => 'boolean',
							'default'     => false,
						),

						/*
						 * `customText` mirrors the saved custom-text meta but as
						 * the *unsaved* editor value, so the preview reflects what
						 * the author is typing. `sanitize_textarea_field` keeps
						 * line breaks while stripping tags, matching the meta's
						 * registered sanitizer.
						 */
						'customText'  => array(
							'description'       => \__( 'The unsaved custom Bluesky text (empty to use the default composition).', 'atmosphere' ),
							'type'              => 'string',
							'default'           => '',
							// Bounds the per-keystroke projection work; the text
							// is clamped to Bluesky's 300-character limit when
							// published anyway.
							'maxLength'         => 2000,
							'sanitize_callback' => 'sanitize_textarea_field',
						),

						/*
						 * The unsaved whole-post access level (e.g. a membership
						 * plugin's subscriber/paid visibility). Forwarded so the
						 * preview reflects a gating change the author has made but
						 * not yet saved; integrations read it on
						 * `atmosphere_pre_projection`. Opaque to this endpoint.
						 */
						'accessLevel' => array(
							'description'       => \__( 'The unsaved whole-post access level, when a membership plugin is active.', 'atmosphere' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check: the requester must be able to edit the target post.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( ! \current_user_can( 'edit_post', (int) $request['id'] ) ) {
			return new WP_Error(
				'rest_forbidden',
				\__( 'Sorry, you are not allowed to preview this post.', 'atmosphere' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Project the publish outcome for the post currently in the editor.
	 *
	 * Runs the real {@see Post} transformer against the *unsaved* editor
	 * content (title/content/excerpt/status/password arrive with the
	 * request) so the panel reflects what the author is about to publish,
	 * not the last saved revision. The transformer runs in projection mode,
	 * so no blobs are uploaded and no meta is written.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_preview( WP_REST_Request $request ) {
		$post = \get_post( (int) $request['id'] );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'atmosphere_post_not_found',
				\__( 'Post not found.', 'atmosphere' ),
				array( 'status' => 404 )
			);
		}

		/*
		 * Clone the post and apply the unsaved editor state. The clone
		 * keeps the real ID (so permalink, meta, and attachments resolve)
		 * while its title/content/excerpt reflect the draft in the editor.
		 */
		$draft               = clone $post;
		$draft->post_title   = (string) $request['title'];
		$draft->post_content = (string) $request['content'];
		$draft->post_excerpt = (string) $request['excerpt'];

		/*
		 * Whether the post will actually be shared is decided from the
		 * *intended* visibility in the editor (private / password-protected
		 * posts are never shared), not from the saved revision.
		 */
		$decision = $this->publish_decision(
			$draft,
			(string) $request['status'],
			(string) $request['password'],
			(bool) $request['disabled']
		);

		/*
		 * The strategy + character count answer "how will it look once
		 * published", so project the clone as a public, published post: the
		 * transformer redacts non-published and password-protected posts,
		 * which would otherwise report a misleading empty body. The result
		 * is only surfaced when `will_publish` is true, so this never
		 * "pretends to publish" a post the decision above ruled out.
		 */
		$draft->post_status   = 'publish';
		$draft->post_password = '';

		/*
		 * Projection renders `the_content`, whose filter chain can fire
		 * oEmbed/shortcode HTTP requests. This endpoint is keystroke-driven,
		 * so block all outbound HTTP for the duration to keep the preview
		 * truly side-effect-free and prevent it being used as an egress
		 * amplifier. Embeds simply fall back to their plain-text URL.
		 */
		$block_http = static function () {
			return new WP_Error( 'atmosphere_projection_no_http', 'HTTP is disabled during pre-publish projection.' );
		};

		\add_filter( 'pre_http_request', $block_http, 0 );

		$transformer = new Post( $draft );

		/*
		 * Project against the *unsaved* custom text so the preview tracks
		 * the textarea as the author types. Only override when the param is
		 * actually present: an older/cached editor that doesn't send it must
		 * fall back to the saved meta, not be forced to the default
		 * composition by a cast-from-missing empty string.
		 */
		if ( $request->has_param( 'customText' ) ) {
			$transformer->set_custom_text_override( (string) $request['customText'] );
		}

		/*
		 * Let integrations reflect *unsaved* editor state that the transformer
		 * would otherwise read from the last save. A membership integration, for
		 * instance, gates on the post's access level, which is only written on
		 * save; without this the preview would show the full body of a paid post
		 * the author is still drafting, then publish a teaser. The clone carries
		 * unsaved content/title/excerpt already; this covers state that lives in
		 * meta rather than the post row. Paired so the override never leaks past
		 * this request, even if projection throws.
		 */
		\do_action( 'atmosphere_pre_projection', $draft, $request );

		try {
			$projection = $transformer->project();
		} finally {
			\do_action( 'atmosphere_post_projection', $draft, $request );
		}

		\remove_filter( 'pre_http_request', $block_http, 0 );

		return \rest_ensure_response(
			array(
				'will_publish'    => $decision['will_publish'],

				/*
				 * Only the reconnect branch sets this, covering both an
				 * expired session and a deliberate disconnect, so every
				 * other "will not publish" reason defaults to false and
				 * stays an info-level note in the panel.
				 */
				'needs_reconnect' => $decision['needs_reconnect'] ?? false,
				'reason'          => $decision['reason'],
				'is_short_form'   => $projection['is_short_form'],
				'strategy'        => $projection['strategy'],
				'limit'           => $projection['limit'],
				'records'         => $projection['records'],
			)
		);
	}


	/**
	 * Decide whether the post will be shared to Bluesky, with a
	 * human-readable reason when it will not.
	 *
	 * Mirrors the real publish gate ({@see \Atmosphere\is_post_publishable()})
	 * but reads the *intended* visibility from the editor rather than the
	 * saved status: a post the author is about to make private, or has just
	 * password-protected in the editor, is never shared — and the panel must
	 * say so before they publish.
	 *
	 * @param WP_Post $post     The post being edited (for post type).
	 * @param string  $status   The intended post status (e.g. 'publish', 'private').
	 * @param string  $password The intended post password ('' when not protected).
	 * @param bool    $disabled Whether sharing is switched off for this post.
	 * @return array{will_publish: bool, reason: ?string, needs_reconnect?: bool}
	 */
	private function publish_decision( WP_Post $post, string $status, string $password, bool $disabled ): array {
		/*
		 * Checked first, ahead of the per-post toggle and the connection
		 * state. Automatic sharing being off decides the answer on its own:
		 * the per-post toggle only records a preference for `backfill` in
		 * that state, and reconnecting would not change whether this post
		 * publishes either, so both would be pointing at something that
		 * cannot alter the outcome.
		 */
		$site = share_status();

		if ( ! $site['sharing_enabled'] ) {
			/*
			 * `reason`, not `message`: this panel was asked a direct question
			 * about a post, so it always answers, including in the state where
			 * the document panel deliberately says nothing because a host
			 * plugin owns the sharing experience. Same sentence either way,
			 * decided in {@see \Atmosphere\share_status()}.
			 */
			return array(
				'will_publish' => false,
				'reason'       => $site['reason'],
			);
		}

		if ( $disabled ) {
			return array(
				'will_publish' => false,
				'reason'       => \__( 'Sharing is switched off for this post.', 'atmosphere' ),
			);
		}

		if ( ! is_supported_post_type( $post->post_type ) ) {
			return array(
				'will_publish' => false,
				'reason'       => \__( 'This post type isn’t shared to Bluesky.', 'atmosphere' ),
			);
		}

		if ( '' !== $password ) {
			return array(
				'will_publish' => false,
				'reason'       => \__( 'Password-protected posts aren’t shared to Bluesky.', 'atmosphere' ),
			);
		}

		if ( 'private' === $status ) {
			return array(
				'will_publish' => false,
				'reason'       => \__( 'Private posts aren’t shared to Bluesky.', 'atmosphere' ),
			);
		}

		if ( ! is_connected() ) {
			/*
			 * `is_connected()` is false for both a dead session and a site
			 * that never connected. Only the first is fixable by an admin,
			 * so it gets its own copy and lifts the panel's notice from
			 * info to warning.
			 */
			if ( needs_reauth() ) {
				/*
				 * The cause sentence is shared with the document panel via
				 * {@see \Atmosphere\share_status()}, so a
				 * `key_changed` (or any other recorded) cause reads
				 * identically on both surfaces, including the
				 * operator-disconnect swap. The consequence sentence is
				 * this panel's own.
				 *
				 * `needs_reconnect` tracks whether a cause sentence is
				 * actually shown, not just whether the connection is dead:
				 * a non-admin reading a suppressed operator-disconnect gets
				 * `false`, matching the document panel showing no banner
				 * for the same reader.
				 */
				$lead   = $site['reason'];
				$tail   = \__( 'This post will not be shared until your site is reconnected.', 'atmosphere' );
				$reason = '' !== $lead ? $lead . ' ' . $tail : $tail;

				return array(
					'will_publish'    => false,
					'needs_reconnect' => '' !== $lead,
					'reason'          => $reason,
				);
			}

			return array(
				'will_publish' => false,
				'reason'       => \__( 'Your site isn’t connected to Bluesky yet.', 'atmosphere' ),
			);
		}

		return array(
			'will_publish' => true,
			'reason'       => null,
		);
	}
}
