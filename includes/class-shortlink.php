<?php
/**
 * Bluesky rkey short links.
 *
 * Every post ATmosphere shares already carries a globally unique,
 * 13-character identifier: the TID it minted for the AT Protocol record.
 * It is sitting in post meta doing nothing but addressing a record on the
 * PDS. This class points it back at the post, so `example.com/3m…` is a
 * working short link with nothing new to store and no counter to keep.
 *
 * The idea is Felix Schwenzel's, from {@link https://wirres.net/articles/kurzurls},
 * which weighs a handful of candidate identifiers for a personal short
 * URL — including, explicitly, a Bluesky post id like `3mn3kzvtns72d` —
 * and settles on the one the site already had. This takes the same
 * position from the other side: on a site running ATmosphere, the rkey
 * *is* the identifier it already has.
 *
 * The IndieWeb frames the general pattern as a permashortlink
 * ({@link https://indieweb.org/permashortlink}): a short URL on your own
 * domain that expands to your own permalink, so it cannot rot the way a
 * third-party shortener does. Tantek Çelik's Whistle and NewBase60 derive
 * theirs from the publication date; this derives it from the record id
 * instead, which is already unique and already stored.
 *
 * @package Atmosphere
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
	 */
	public static function register(): void {
		/*
		 * Deliberately NOT a rewrite rule.
		 *
		 * A rule for a bare 13-character path would have to be registered
		 * at the top to fire at all — WordPress's page catch-all
		 * (`(.?.+?)/?$`) sits above anything appended at the bottom, so a
		 * low-priority rule for a single segment is never reached. But at
		 * the top it outranks real content, and a TID is drawn from
		 * `234567a-z`, which ordinary slugs are too: `wordpressblog` is
		 * exactly thirteen of those characters. That page would become
		 * unreachable.
		 *
		 * Resolving after the 404 inverts the priority for free. Anything
		 * WordPress can serve itself — page, post, custom post type,
		 * another plugin's rule — has already won by the time this runs,
		 * so the short link can only ever claim a URL that was going
		 * nowhere. No ordering to get right, and no collisions possible.
		 */
		\add_action( 'template_redirect', array( self::class, 'maybe_redirect' ), 0 );

		\add_filter( 'pre_get_shortlink', array( self::class, 'filter_shortlink' ), 10, 2 );
	}

	/**
	 * Redirect a bare rkey to the post that owns it.
	 *
	 * Runs only on a request WordPress could not resolve. See the note in
	 * {@see self::register()} for why that is the whole collision strategy.
	 */
	public static function maybe_redirect(): void {
		if ( ! \is_404() ) {
			return;
		}

		$tid = self::requested_tid();

		if ( '' === $tid ) {
			return;
		}

		$post_id = self::resolve( $tid );

		if ( null === $post_id ) {
			return;
		}

		$permalink = \get_permalink( $post_id );

		if ( ! $permalink ) {
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
	 * The rkey the current request is asking for, if it is asking for one.
	 *
	 * Reads the parsed path rather than `REQUEST_URI` so the query string,
	 * the site's subdirectory, and any trailing slash are already off.
	 *
	 * @return string A valid TID, or an empty string.
	 */
	private static function requested_tid(): string {
		$request = isset( $GLOBALS['wp']->request ) ? (string) $GLOBALS['wp']->request : '';

		if ( '' === $request || \str_contains( $request, '/' ) ) {
			return '';
		}

		return TID::is_valid( $request ) ? $request : '';
	}

	/**
	 * Find the post that owns an rkey.
	 *
	 * Both record ids resolve: the Bluesky post rkey and the
	 * `site.standard.document` one. They are minted separately, so a
	 * document-only site still gets working short links, and a site
	 * publishing both has two that land on the same post.
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

		$url = \home_url( '/' . $tid );

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
	 * @param false|string $shortlink Short-circuit value from a prior filter.
	 * @param int          $post_id   Post ID, or 0 for the current post.
	 * @return false|string
	 */
	public static function filter_shortlink( $shortlink, $post_id ) {
		// Another plugin already answered; leave it alone.
		if ( false !== $shortlink ) {
			return $shortlink;
		}

		$post_id = $post_id ? (int) $post_id : (int) \get_the_ID();

		if ( ! $post_id ) {
			return $shortlink;
		}

		$url = self::get( $post_id );

		return '' === $url ? $shortlink : $url;
	}
}
