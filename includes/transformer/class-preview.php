<?php
/**
 * Resolves `?atproto` preview selectors to AT Protocol record payloads.
 *
 * The preview endpoint offers a set of transformers per context — the
 * site front page exposes its `site.standard.publication` record, a
 * singular post exposes its `site.standard.document` and
 * `app.bsky.feed.post` records. Each transformer is keyed by its own
 * lexicon NSID ({@see Base::get_collection()}), so a selector maps
 * straight to a transformer and `all` returns every one of them.
 *
 * Third parties extend the endpoint with their own lexicons through the
 * `atmosphere_atproto_preview_transformers` filter by appending another
 * {@see Base} subclass — no new interface to implement.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Transformer;

use function Atmosphere\is_supported_post_type;

\defined( 'ABSPATH' ) || exit;

/**
 * AT Protocol record preview resolver.
 */
class Preview {

	/**
	 * Reserved selector that returns every record family keyed by `$type`.
	 *
	 * @var string
	 */
	public const TYPE_ALL = 'all';

	/**
	 * Serve the `?atproto` preview for the current view and end the request.
	 *
	 * Bound to `template_redirect`. On a singular post the selectors expose
	 * the post's record families; on the site front page they expose the
	 * `site.standard.publication` record. Requires the edit_posts
	 * capability — other requests fall through untouched.
	 */
	public static function render(): void {
		$type = \get_query_var( 'atproto', null );

		if ( null === $type || \is_array( $type ) ) {
			return;
		}

		if ( ! \current_user_can( 'edit_posts' ) ) {
			return;
		}

		$type = \sanitize_text_field( (string) $type );

		if ( \is_front_page() ) {
			self::mark_rest_request();
			self::send( self::for_site( $type ) );
			exit;
		}

		if ( ! \is_singular() ) {
			return;
		}

		$post = \get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! is_supported_post_type( $post->post_type ) ) {
			\status_header( 404 );
			exit;
		}

		self::mark_rest_request();
		self::send( self::for_post( $post, $type ) );
		exit;
	}

	/**
	 * Mark the current request as a REST request before rendering a record.
	 *
	 * Called only on the two branches that resolve a record and immediately
	 * `exit`, so the constant never outlives the preview request — an earlier
	 * bare `return` (e.g. `?atproto` on a non-singular archive) must not leave
	 * `REST_REQUEST` defined for the rest of the page render.
	 *
	 * This runs on a singular / front-page front-end request, so display
	 * plugins (sharing buttons, Related Posts, ad units, …) would hook
	 * `the_content` and bleed their chrome into the previewed record — chrome
	 * the real publish path never picks up, because it renders outside the
	 * loop / main query. Those plugins already bail on REST requests, so
	 * marking this one as REST renders the record the way it is published.
	 */
	private static function mark_rest_request(): void {
		if ( ! \defined( 'REST_REQUEST' ) ) {
			\define( 'REST_REQUEST', true );
		}
	}

	/**
	 * Resolve the preview payload for a singular post.
	 *
	 * @param \WP_Post $post Post to preview.
	 * @param string   $type Requested lexicon `$type`, `all`, or empty.
	 * @return array|\WP_Error Preview payload, or error for unsupported types.
	 */
	public static function for_post( \WP_Post $post, string $type = '' ): array|\WP_Error {
		return self::resolve(
			array( new Document( $post ), new Post( $post ) ),
			$post,
			'site.standard.document',
			$type
		);
	}

	/**
	 * Resolve the preview payload for the site front page.
	 *
	 * @param string $type Requested lexicon `$type`, `all`, or empty.
	 * @return array|\WP_Error Preview payload, or error for unsupported types.
	 */
	public static function for_site( string $type = '' ): array|\WP_Error {
		return self::resolve(
			array( new Publication( null ) ),
			null,
			'site.standard.publication',
			$type
		);
	}

	/**
	 * Resolve a selector against a set of transformers.
	 *
	 * @param Base[]        $defaults     Built-in transformers for the context.
	 * @param \WP_Post|null $post         Queried post, or null on the front page.
	 * @param string        $default_type Type used for the bare `?atproto` selector.
	 * @param string        $type         Requested lexicon `$type`, `all`, or empty.
	 * @return array|\WP_Error Preview payload, or error for unsupported types.
	 */
	private static function resolve( array $defaults, ?\WP_Post $post, string $default_type, string $type ): array|\WP_Error {
		$transformers = self::transformers( $defaults, $post );
		$type         = \trim( $type );

		if ( '' === $type ) {
			$type = $default_type;
		}

		if ( self::TYPE_ALL === $type ) {
			return \array_map(
				static fn( Base $transformer ): array => $transformer->get_preview_records(),
				$transformers
			);
		}

		if ( ! isset( $transformers[ $type ] ) ) {
			return self::unsupported_type_error( \array_keys( $transformers ) );
		}

		$records = $transformers[ $type ]->get_preview_records();

		return 1 === \count( $records ) ? $records[0] : \array_values( $records );
	}

	/**
	 * Build the transformer set for a context, keyed by lexicon NSID.
	 *
	 * @param Base[]        $defaults Built-in transformers for the context.
	 * @param \WP_Post|null $post     Queried post, or null on the front page.
	 * @return array<string,Base> Transformers keyed by their collection NSID.
	 */
	private static function transformers( array $defaults, ?\WP_Post $post ): array {
		/**
		 * Filters the transformers offered by the `?atproto` preview endpoint.
		 *
		 * Append a {@see Base} subclass to expose its records under
		 * `?atproto={$type}` (its {@see Base::get_collection()} NSID) and in
		 * `?atproto=all`. Entries that are not `Base` instances are ignored.
		 * A later transformer sharing a built-in's NSID supersedes it, the
		 * same way {@see \Atmosphere\Content_Parser\Registry::register()}
		 * lets a registration override a default by NSID.
		 *
		 * @since unreleased
		 *
		 * @param Base[]        $transformers Default transformers for the context.
		 * @param \WP_Post|null $post         Queried post, or null on the front page.
		 */
		$transformers = \apply_filters( 'atmosphere_atproto_preview_transformers', $defaults, $post );

		if ( ! \is_array( $transformers ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'atmosphere_atproto_preview_transformers must return an array; falling back to the built-in transformers.', 'atmosphere' ),
				'unreleased'
			);
			$transformers = $defaults;
		}

		$keyed = array();

		foreach ( $transformers as $transformer ) {
			if ( $transformer instanceof Base ) {
				// Last writer wins by NSID, so a filter can override a built-in.
				$keyed[ $transformer->get_collection() ] = $transformer;
			}
		}

		return $keyed;
	}

	/**
	 * Build the error returned for an unsupported preview selector.
	 *
	 * @param string[] $types Supported lexicon `$type` selectors.
	 * @return \WP_Error Error object.
	 */
	private static function unsupported_type_error( array $types ): \WP_Error {
		if ( empty( $types ) ) {
			return new \WP_Error(
				'atmosphere_atproto_preview_type',
				\__( 'Unsupported AT Protocol preview type.', 'atmosphere' )
			);
		}

		$types[] = self::TYPE_ALL;

		return new \WP_Error(
			'atmosphere_atproto_preview_type',
			\sprintf(
				/* translators: %s: Comma-separated list of supported AT Protocol preview selectors. */
				\__( 'Unsupported AT Protocol preview type. Use one of: %s.', 'atmosphere' ),
				\implode( ', ', $types )
			)
		);
	}

	/**
	 * Write the preview payload as a JSON response.
	 *
	 * A `WP_Error` is rendered as a `400` with `code`/`message`; any other
	 * payload is sent as `200`.
	 *
	 * @param array|\WP_Error $payload Preview payload or error.
	 */
	private static function send( array|\WP_Error $payload ): void {
		if ( \is_wp_error( $payload ) ) {
			\status_header( 400 );
			$payload = array(
				'code'    => $payload->get_error_code(),
				'message' => $payload->get_error_message(),
			);
		} else {
			\status_header( 200 );
		}

		\header( 'Content-Type: application/json; charset=utf-8' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		echo \wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
