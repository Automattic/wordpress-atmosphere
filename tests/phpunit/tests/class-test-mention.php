<?php
/**
 * Tests for the display-side @mention linkifier.
 *
 * @package Atmosphere
 */

declare( strict_types = 1 );

namespace Atmosphere\Tests;

use Atmosphere\Mention;
use WP_UnitTestCase;

/**
 * Display-side @mention linkifier tests.
 *
 * @group atmosphere
 */
class Test_Mention extends WP_UnitTestCase {

	/**
	 * A bare @handle.tld is linked to its Bluesky profile.
	 */
	public function test_links_bare_handle() {
		$out = Mention::the_content( '<p>Hello @alice.bsky.social!</p>' );

		$this->assertStringContainsString(
			'<a class="atmosphere-mention" href="https://bsky.app/profile/alice.bsky.social">@alice.bsky.social</a>',
			$out
		);
	}

	/**
	 * The profile link honours the `atmosphere_appview_host` filter so a
	 * self-hosted appview rewrites the mention target.
	 */
	public function test_links_honour_appview_host_filter() {
		$filter = static fn() => 'deer.social';
		\add_filter( 'atmosphere_appview_host', $filter );

		$out = Mention::the_content( '<p>Hello @alice.bsky.social!</p>' );

		\remove_filter( 'atmosphere_appview_host', $filter );

		$this->assertStringContainsString(
			'<a class="atmosphere-mention" href="https://deer.social/profile/alice.bsky.social">@alice.bsky.social</a>',
			$out
		);
	}

	/**
	 * A @mention already inside an anchor is left alone (no double-link).
	 */
	public function test_skips_existing_anchor() {
		$html = '<p><a href="https://example.com">@alice.bsky.social</a></p>';

		$this->assertSame( $html, Mention::the_content( $html ) );
	}

	/**
	 * A @mention inside <code> is left alone.
	 */
	public function test_skips_code() {
		$html = '<p><code>@alice.bsky.social</code></p>';

		$this->assertSame( $html, Mention::the_content( $html ) );
	}

	/**
	 * A @mention inside a raw-text / non-rendered element (e.g. <script>,
	 * <svg>) is left alone — linkifying it would corrupt the element.
	 *
	 * @dataProvider data_non_rendered_tags
	 *
	 * @param string $tag Protected tag name.
	 */
	public function test_skips_non_rendered_tags( string $tag ) {
		$html = \sprintf( '<p><%1$s>@alice.bsky.social</%1$s></p>', $tag );

		$this->assertSame( $html, Mention::the_content( $html ) );
	}

	/**
	 * Raw-text / non-rendered elements whose contents must never be linkified.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function data_non_rendered_tags(): array {
		return array(
			'script'   => array( 'script' ),
			'noscript' => array( 'noscript' ),
			'svg'      => array( 'svg' ),
			'iframe'   => array( 'iframe' ),
			'title'    => array( 'title' ),
		);
	}

	/**
	 * Nested same-name protected tags unwind one level at a time, so a handle
	 * still inside the outer tag stays unlinked once the inner tag closes.
	 */
	public function test_skips_nested_same_name_protected_tags() {
		$html = '<p><code>a<code>b</code>@alice.bsky.social</code></p>';

		$this->assertSame( $html, Mention::the_content( $html ) );
	}

	/**
	 * The domain half of an ActivityPub @user@domain.tld handle is not linked.
	 */
	public function test_skips_activitypub_webfinger_form() {
		$out = Mention::the_content( '<p>Hi @pfefferle@notiz.blog there</p>' );

		$this->assertStringNotContainsString( '<a', $out );
	}

	/**
	 * A WebFinger handle whose user half is itself domain-shaped
	 * (`@notiz.blog@notiz.blog`) must not have its first half mistaken for a
	 * standalone Bluesky handle. Pins the trailing boundary on the shared
	 * mention pattern.
	 */
	public function test_skips_webfinger_with_domain_shaped_username() {
		$out = Mention::the_content( '<p>Follow @notiz.blog@notiz.blog please</p>' );

		$this->assertStringNotContainsString( '<a', $out );
	}

	/**
	 * The `atmosphere_link_mention` filter can veto a specific handle so it
	 * renders as plain text rather than a profile link — the seam a site uses
	 * to gate display links on a cached existence check or an allowlist.
	 */
	public function test_filter_can_veto_a_handle_link() {
		$filter = static fn( $link, $handle ) => 'ghost.example' === $handle ? false : $link;
		\add_filter( 'atmosphere_link_mention', $filter, 10, 2 );

		$out = Mention::the_content( '<p>Hi @ghost.example and @alice.bsky.social</p>' );

		\remove_filter( 'atmosphere_link_mention', $filter, 10 );

		// The vetoed handle stays as plain text...
		$this->assertStringContainsString( '@ghost.example', $out );
		$this->assertStringNotContainsString( 'profile/ghost.example', $out );
		// ...while a handle the filter leaves alone is still linked.
		$this->assertStringContainsString(
			'<a class="atmosphere-mention" href="https://bsky.app/profile/alice.bsky.social">@alice.bsky.social</a>',
			$out
		);
	}

	/**
	 * An email address is not linkified.
	 */
	public function test_skips_email() {
		$out = Mention::the_content( '<p>Mail me at bob@example.com today</p>' );

		$this->assertStringNotContainsString( '<a', $out );
	}

	/**
	 * A single-label @bareword is not a handle.
	 */
	public function test_skips_single_label() {
		$out = Mention::the_content( '<p>Just @someone here</p>' );

		$this->assertStringNotContainsString( '<a', $out );
	}

	/**
	 * The guard is scoped: a normal the_content render (front-end / document
	 * content parser) still linkifies after a guarded transformer render.
	 */
	public function test_guard_is_scoped_to_callback() {
		// Inside the guard, the linkifier is suppressed.
		$inside = Mention::without_links( static fn() => Mention::the_content( '<p>@alice.bsky.social</p>' ) );
		$this->assertStringNotContainsString( '<a', $inside );

		// Outside the guard, linking resumes.
		$outside = Mention::the_content( '<p>@alice.bsky.social</p>' );
		$this->assertStringContainsString( '<a class="atmosphere-mention"', $outside );
	}
}
