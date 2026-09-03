<?php
/**
 * Bluesky rkey short links.
 *
 * Every post ATmosphere shares already carries a globally unique,
 * 13-character identifier: the rkey it minted for the AT Protocol record.
 * It sits in post meta addressing a record on the PDS and nothing else.
 * This class points it back at the post, so the site gets a short URL for
 * every cross-posted article with nothing new to store and no counter to
 * keep.
 *
 * The path mirrors Bluesky's own. An app URL looks like
 * `bsky.app/profile/<handle>/post/<rkey>`; drop the profile segment and
 * swap the host and you have `example.com/post/<rkey>`, the same post on
 * the author's own domain. Aaron Parecki's site already works this way —
 * `aaronpk.com/post/3juasablkof2o` — which is where the shape comes from.
 *
 * The idea is Felix Schwenzel's, from {@link https://wirres.net/articles/kurzurls},
 * which weighs up several candidate identifiers for a personal short URL
 * and lands on the position that the best one is whatever the site
 * already has. On a site running ATmosphere, that is the rkey.
 *
 * The IndieWeb frames the general pattern as a permashortlink
 * ({@link https://indieweb.org/permashortlink}): a short URL on your own
 * domain that expands to your own permalink, so it cannot rot the way a
 * third-party shortener does. Tantek Çelik's Whistle and NewBase60 derive
 * theirs from the publication date; this derives it from the record id,
 * which is already unique and already stored.
 *
 * @package Atmosphere
 * @since unreleased
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\TID;

/**
 * Resolve and advertise rkey-based short links.
 */
class Shortlink {

	/**
	 * Register the hooks.
	 *
	 * The rewrite rule itself is declared alongside the plugin's other
	 * rules in {@see Atmosphere}, so the persisted-rules drift check that
	 * keeps the well-known endpoints working covers this one too.
	 *
	 * @since unreleased
	 */
	public static function register(): void {
		\add_action( 'template_redirect', array( self::class, 'maybe_redirect' ), 0 );

		\add_filter( 'pre_get_shortlink', array( self::class, 'filter_shortlink' ), 10, 4 );
	}

	/**
	 * Redirect a short link to the post that owns the rkey.
	 *
	 * @since unreleased
	 */
	public static function maybe_redirect(): void {
		$tid = (string) \get_query_var( 'atmosphere_shortlink' );

		if ( '' === $tid ) {
			return;
		}

		/*
		 * A 301 invites most clients to repeat the request as a GET, so a
		 * write that happened to land here would come back as something
		 * the caller never sent. Nothing legitimately POSTs to a short
		 * link.
		 */
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? \strtoupper( \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		$post_id   = self::resolve( $tid );
		$permalink = $post_id ? \get_permalink( $post_id ) : '';

		if ( ! $permalink ) {
			/*
			 * The rule matched the shape but nothing owns this rkey. The
			 * query var alone would leave WordPress with no constraints
			 * and render the blog index at a URL that means nothing, so
			 * say so properly instead.
			 */
			self::set_404();

			return;
		}

		/*
		 * 301: the rkey is minted once and never reassigned, so the
		 * mapping is as permanent as the permalink it points at. Caching
		 * it hard is the point of a short link.
		 */
		\wp_safe_redirect( $permalink, 301 );
		exit;
	}

	/**
	 * Turn the current request into a proper 404.
	 *
	 * @since unreleased
	 */
	private static function set_404(): void {
		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		\status_header( 404 );
		\nocache_headers();
	}

	/**
	 * Find the post that owns an rkey.
	 *
	 * Both record ids resolve: the Bluesky post rkey and the
	 * `site.standard.document` one. They are minted separately, so a
	 * document-only site still gets working short links, and a site
	 * publishing both has two that land on the same post.
	 *
	 * Takes the first match. TIDs are unique by construction — see the
	 * monotonic counter and clock id in {@see TID} — so a second owner
	 * can only come from a hand-edited meta row or a partially restored
	 * backup, and picking either one is as good an answer as exists.
	 *
	 * @since unreleased
	 *
	 * @param string $tid A TID, already validated.
	 * @return int|null Post ID, or null when nothing owns it.
	 */
	public static function resolve( string $tid ): ?int {
		if ( ! TID::is_valid( $tid ) ) {
			return null;
		}

		$posts = \get_posts(
			array(
				'post_type'           => 'any',
				'post_status'         => 'publish',
				'posts_per_page'      => 1,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => false,

				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed meta lookup on a 404; the alternative is not resolving at all.
				'meta_query'          => array(
					'relation' => 'OR',
					array(
						'key'   => Post::META_TID,
						'value' => $tid,
					),
					array(
						'key'   => Document::META_TID,
						'value' => $tid,
					),
				),
			)
		);

		return empty( $posts ) ? null : (int) $posts[0];
	}

	/**
	 * The short link for a post, when it has a record to build one from.
	 *
	 * @since unreleased
	 *
	 * @param int $post_id Post ID.
	 * @return string Short link URL, or an empty string.
	 */
	public static function get( int $post_id ): string {
		$tid = (string) \get_post_meta( $post_id, Post::META_TID, true );

		if ( '' === $tid ) {
			$tid = (string) \get_post_meta( $post_id, Document::META_TID, true );
		}

		if ( ! TID::is_valid( $tid ) ) {
			return '';
		}

		$url = \home_url( '/post/' . $tid );

		/**
		 * Filters the short link for a post.
		 *
		 * Runs last, so a site already running its own shortener (Hum, or
		 * anything else claiming `rel=shortlink`) can take the field back
		 * without unhooking anything. Return an empty string to fall
		 * through to WordPress's own `?p=` short link.
		 *
		 * @since unreleased
		 *
		 * @param string $url     The rkey short link.
		 * @param int    $post_id Post the link points at.
		 * @param string $tid     The rkey the link is built from.
		 */
		return (string) \apply_filters( 'atmosphere_shortlink', $url, $post_id, $tid );
	}

	/**
	 * Advertise the short link as the post's `rel=shortlink`.
	 *
	 * Short-circuits {@see \wp_get_shortlink()} rather than printing a
	 * second `<link>`: two competing `rel=shortlink` elements on one page
	 * is worse than either alone. Returning `false` leaves WordPress to
	 * emit its own `?p=` link exactly as before, which is what happens for
	 * every post ATmosphere has not shared.
	 *
	 * Mirrors {@see \wp_get_shortlink()}'s own resolution of `$context`,
	 * including the `is_singular()` gate, because the short-circuit runs
	 * before core gets to apply it. Every real caller —
	 * `wp_shortlink_wp_head()`, `wp_shortlink_header()`, the admin bar —
	 * passes `0` with the `query` context, and on an archive WordPress has
	 * already primed `$GLOBALS['post']` with the first post in the loop.
	 * Falling back to "the current post" there would advertise one post's
	 * short link on every archive that happened to list it first.
	 *
	 * @since unreleased
	 *
	 * @param false|string $shortlink   Short-circuit value from a prior filter.
	 * @param int          $id          Post ID, or 0 to resolve from context.
	 * @param string       $context     Either 'post' or 'query'.
	 * @param bool         $allow_slugs Unused; the short link is never a slug.
	 * @return false|string
	 */
	public static function filter_shortlink( $shortlink, $id, $context = 'post', $allow_slugs = true ) {
		// Another plugin already answered; leave it alone.
		if ( false !== $shortlink ) {
			return $shortlink;
		}

		unset( $allow_slugs );

		$post_id = 0;

		if ( 'query' === $context && \is_singular() ) {
			$post_id = (int) \get_queried_object_id();
		} elseif ( 'post' === $context ) {
			$post = \get_post( $id );

			if ( ! empty( $post->ID ) ) {
				$post_id = (int) $post->ID;
			}
		}

		if ( ! $post_id ) {
			return $shortlink;
		}

		$url = self::get( $post_id );

		return '' === $url ? $shortlink : $url;
	}
}
