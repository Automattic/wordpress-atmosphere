<?php
/**
 * Reactions REST controller.
 *
 * Serves the Bluesky likes and reposts a post received (synced as WordPress
 * comments by {@see \Atmosphere\Reaction_Sync}) for the reactions block's
 * facepile and reactor popover. Mirrors the ActivityPub plugin's
 * `posts/{id}/reactions` endpoint.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Rest;

\defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reactions controller.
 */
class Reactions_Controller extends \WP_REST_Controller {

	/**
	 * REST namespace for this controller's route.
	 *
	 * Public namespace — the reactions data is shown on the front end.
	 *
	 * @var string
	 */
	public const ROUTE_NAMESPACE = 'atmosphere/v1';

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
	protected $rest_base = 'posts';

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/reactions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_reactions' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'description'       => \__( 'The post ID.', 'atmosphere' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Return the likes and reposts for a post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error Reactions keyed by type, or an error.
	 */
	public function get_reactions( $request ) {
		$post_id = (int) $request['id'];

		// Don't leak reaction metadata for posts that aren't publicly viewable.
		if ( ! \is_post_publicly_viewable( $post_id ) ) {
			return new WP_Error(
				'atmosphere_post_not_viewable',
				\__( 'Reactions are not available for this post.', 'atmosphere' ),
				array( 'status' => 404 )
			);
		}

		$reactions = array();

		foreach ( array( 'like', 'repost' ) as $type ) {
			$comments = \get_comments(
				array(
					'post_id' => $post_id,
					'type'    => $type,
					'status'  => 'approve',
					'parent'  => 0,
				)
			);

			if ( empty( $comments ) ) {
				continue;
			}

			$count = \count( $comments );

			if ( 'like' === $type ) {
				/* translators: %s: number of likes. */
				$label = \sprintf( \_n( '%s like', '%s likes', $count, 'atmosphere' ), \number_format_i18n( $count ) );
			} else {
				/* translators: %s: number of reposts. */
				$label = \sprintf( \_n( '%s repost', '%s reposts', $count, 'atmosphere' ), \number_format_i18n( $count ) );
			}

			$reactions[ $type ] = array(
				'label' => $label,
				'count' => $count,
				'items' => \array_map(
					static function ( $comment ) {
						return array(
							'name'   => \html_entity_decode( $comment->comment_author, \ENT_QUOTES, 'UTF-8' ),
							'url'    => $comment->comment_author_url,
							'avatar' => \get_avatar_url( $comment ),
						);
					},
					$comments
				),
			);
		}

		return new WP_REST_Response( $reactions );
	}
}
