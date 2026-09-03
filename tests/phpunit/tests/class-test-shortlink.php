<?php
/**
 * Tests for the AT Protocol rkey short links.
 *
 * @package Atmosphere
 * @group atmosphere
 */

namespace Atmosphere\Tests;

use Atmosphere\Shortlink;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\TID;

/**
 * Short link tests.
 */
class Test_Shortlink extends \WP_UnitTestCase {

	/**
	 * A real, well-formed rkey.
	 *
	 * @var string
	 */
	private const TID = '3mn3kzvtns72d';

	/**
	 * Pretty permalinks, so a path that matches nothing actually 404s.
	 *
	 * With the plain structure the test suite starts from, every unknown
	 * path lands on the home page instead, and the 404 this feature hangs
	 * off never happens.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		\remove_all_filters( 'wp_redirect' );
		\remove_all_filters( 'atmosphere_shortlink' );
		\remove_all_filters( 'pre_get_shortlink' );

		parent::tear_down();
	}

	/**
	 * Capture the redirect `maybe_redirect()` issues, instead of exiting.
	 *
	 * @return string The redirect target, or '' when none was issued.
	 */
	private function capture_redirect(): string {
		$captured = '';

		/*
		 * `maybe_redirect()` exits after redirecting, as it must in
		 * production. Throwing from the filter unwinds before that,
		 * leaving the captured target behind.
		 */
		\add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;

				throw new \RuntimeException();
			}
		);

		try {
			Shortlink::maybe_redirect();
		} catch ( \RuntimeException $e ) {
			// Expected: the redirect fired.
			unset( $e );
		}

		return $captured;
	}

	/**
	 * The Bluesky rkey resolves to the post that owns it.
	 */
	public function test_bluesky_rkey_resolves_to_its_post() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		$this->assertSame( $post_id, Shortlink::resolve( self::TID ) );
	}

	/**
	 * A document-only site still gets working short links: the
	 * `site.standard.document` rkey resolves too.
	 */
	public function test_document_rkey_resolves_to_its_post() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Document::META_TID, self::TID );

		$this->assertSame( $post_id, Shortlink::resolve( self::TID ) );
	}

	/**
	 * An rkey nothing owns resolves to nothing.
	 */
	public function test_unknown_rkey_resolves_to_null() {
		$this->assertNull( Shortlink::resolve( self::TID ) );
	}

	/**
	 * Anything that is not a TID is rejected before it reaches the database.
	 */
	public function test_non_tid_is_rejected() {
		$this->assertNull( Shortlink::resolve( 'hello' ) );
		$this->assertNull( Shortlink::resolve( '0000000000000' ), 'The charset excludes 0, 1, 8 and 9.' );
		$this->assertNull( Shortlink::resolve( '3mn3kzvtns72' ), 'A TID is exactly thirteen characters.' );
	}

	/**
	 * A visit to the short link redirects to the post, permanently.
	 */
	public function test_shortlink_redirects_to_the_permalink() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		$this->go_to( \home_url( '/post/' . self::TID ) );

		$this->assertSame( self::TID, \get_query_var( 'atmosphere_shortlink' ), 'The rewrite rule must match.' );
		$this->assertSame( \get_permalink( $post_id ), $this->capture_redirect() );
	}

	/**
	 * The path mirrors Bluesky's own, which is the whole point: strip
	 * `/profile/<handle>` off an app URL, swap the host, and the rkey is
	 * already in the right place.
	 */
	public function test_path_mirrors_the_bluesky_app_url() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		$app_url  = 'https://bsky.app/profile/example.com/post/' . self::TID;
		$expected = \home_url( \str_replace( '/profile/example.com', '', \wp_parse_url( $app_url, \PHP_URL_PATH ) ) );

		$this->assertSame( $expected, Shortlink::get( $post_id ) );
	}

	/**
	 * A short link nothing owns is a 404, not the blog index.
	 *
	 * The rewrite rule matches on shape alone, so an rkey that was never
	 * ours still gets routed here. Left alone, the query var would leave
	 * WordPress with no constraints and it would render the home page at
	 * a URL that means nothing.
	 */
	public function test_unknown_shortlink_is_a_404() {
		$this->go_to( \home_url( '/post/' . self::TID ) );

		$this->assertSame( '', $this->capture_redirect(), 'Nothing owns this rkey, so nothing to redirect to.' );
		$this->assertTrue( \is_404() );
	}

	/**
	 * The rule is scoped to `post/` plus exactly thirteen characters from
	 * the rkey charset, so ordinary content is untouched.
	 *
	 * `wordpressblog` is thirteen characters drawn entirely from that
	 * charset — a rule matching a bare path would have swallowed a page
	 * slugged that way. Under `post/` it cannot.
	 */
	public function test_ordinary_paths_are_not_claimed() {
		$this->assertTrue( TID::is_valid( 'wordpressblog' ), 'Precondition: the slug really is rkey-shaped.' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'wordpressblog',
			)
		);

		$this->go_to( \home_url( '/wordpressblog' ) );

		$this->assertSame( '', \get_query_var( 'atmosphere_shortlink' ), 'A bare path must not route to the short link.' );
		$this->assertTrue( \is_page( $page_id ) );
	}

	/**
	 * A shared post advertises the rkey short link as its `rel=shortlink`.
	 */
	public function test_shared_post_advertises_the_rkey_shortlink() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		$this->assertSame( \home_url( '/post/' . self::TID ), \wp_get_shortlink( $post_id ) );
	}

	/**
	 * A post ATmosphere never shared keeps WordPress's own short link.
	 */
	public function test_unshared_post_keeps_the_core_shortlink() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( \home_url( '/?p=' . $post_id ), \wp_get_shortlink( $post_id ) );
	}

	/**
	 * A site running its own shortener can take the field back.
	 */
	public function test_the_filter_can_hand_the_shortlink_back() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		\add_filter( 'atmosphere_shortlink', '__return_empty_string' );

		$this->assertSame(
			\home_url( '/?p=' . $post_id ),
			\wp_get_shortlink( $post_id ),
			'An empty filter return must fall through to the core short link.'
		);
	}

	/**
	 * Another plugin that answered first is not overridden.
	 */
	public function test_a_prior_short_circuit_is_respected() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		\add_filter( 'pre_get_shortlink', static fn() => 'https://example.com/hum', 5 );

		$this->assertSame( 'https://example.com/hum', \wp_get_shortlink( $post_id ) );
	}

	/**
	 * An archive must not advertise some other post's short link.
	 *
	 * This is the shape every real caller uses: `wp_shortlink_wp_head()`,
	 * `wp_shortlink_header()`, and the admin bar all call
	 * `wp_get_shortlink( 0, 'query' )`. On a home page or archive,
	 * WordPress has already set `$GLOBALS['post']` to the first post in
	 * the loop, so resolving "the current post" without checking
	 * `is_singular()` first silently points the whole archive at whichever
	 * post happens to be at the top of it.
	 */
	public function test_archive_does_not_advertise_a_shortlink() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		$this->go_to( \home_url( '/' ) );

		$this->assertFalse( \is_singular(), 'Precondition: this is an archive.' );
		$this->assertNotEmpty( $GLOBALS['post'], 'Precondition: the loop has primed a global post.' );

		$this->assertSame(
			'',
			\wp_get_shortlink( 0, 'query' ),
			'An archive has no single post to advertise, so it must advertise nothing.'
		);
	}

	/**
	 * On the post's own page, the query context does resolve it.
	 */
	public function test_singular_query_context_resolves_the_shortlink() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		$this->go_to( \get_permalink( $post_id ) );

		$this->assertTrue( \is_singular(), 'Precondition: this is the post.' );
		$this->assertSame( \home_url( '/post/' . self::TID ), \wp_get_shortlink( 0, 'query' ) );
	}

	/**
	 * A post that is no longer public does not resolve.
	 *
	 * The publisher clears both record ids when a post leaves public
	 * visibility, so this should never come up — but the query is the last
	 * line of that defence, and a short link must never be the thing that
	 * confirms a draft exists.
	 */
	public function test_non_public_post_does_not_resolve() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		foreach ( array( 'draft', 'private', 'pending', 'trash' ) as $status ) {
			\wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $status,
				)
			);

			// Re-stamp: the status change clears the record meta.
			\update_post_meta( $post_id, Post::META_TID, self::TID );

			$this->assertNull(
				Shortlink::resolve( self::TID ),
				\sprintf( 'A %s post must not resolve.', $status )
			);
		}
	}
}
