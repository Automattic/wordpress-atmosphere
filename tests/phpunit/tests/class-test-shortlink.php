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
	 * A visit to a bare rkey redirects to the post, permanently.
	 */
	public function test_bare_rkey_redirects_to_the_permalink() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		$this->go_to( \home_url( '/' . self::TID ) );

		$this->assertTrue( \is_404(), 'WordPress cannot resolve a bare rkey on its own.' );
		$this->assertSame( \get_permalink( $post_id ), $this->capture_redirect() );
	}

	/**
	 * The collision case, which is why there is no rewrite rule.
	 *
	 * `wordpressblog` is thirteen characters drawn entirely from the TID
	 * charset, so a top-priority rewrite rule for a bare thirteen-character
	 * path would swallow this page and make it unreachable. Resolving after
	 * the 404 means the page is served normally and the short link never
	 * gets a look in.
	 */
	public function test_a_real_page_at_a_tid_shaped_slug_still_wins() {
		$this->assertTrue( TID::is_valid( 'wordpressblog' ), 'Precondition: the slug really is TID-shaped.' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'wordpressblog',
			)
		);

		/*
		 * A post that would answer to the same path, so the test fails if
		 * the short link ever starts outranking real content.
		 */
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, 'wordpressblog' );

		$this->go_to( \home_url( '/wordpressblog' ) );

		$this->assertFalse( \is_404(), 'The page must resolve normally.' );
		$this->assertTrue( \is_page( $page_id ) );
		$this->assertSame( '', $this->capture_redirect(), 'The short link must not fire over real content.' );
	}

	/**
	 * A 404 that is not rkey-shaped is left alone for the theme to render.
	 */
	public function test_ordinary_404_is_left_alone() {
		$this->go_to( \home_url( '/no-such-thing' ) );

		$this->assertTrue( \is_404() );
		$this->assertSame( '', $this->capture_redirect() );
	}

	/**
	 * A shared post advertises the rkey short link as its `rel=shortlink`.
	 */
	public function test_shared_post_advertises_the_rkey_shortlink() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Post::META_TID, self::TID );

		$this->assertSame( \home_url( '/' . self::TID ), \wp_get_shortlink( $post_id ) );
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
}
