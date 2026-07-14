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
	 * A self-closed protected tag (`<svg/>`, `<iframe … />`) opens and closes in
	 * one chunk, so it must not be pushed onto the tag stack. Otherwise it would
	 * stay "open" forever and suppress every mention after it in the render.
	 */
	public function test_self_closed_protected_tag_does_not_swallow_later_mentions() {
		$html = '<p><svg/> then hi @alice.bsky.social</p>';

		$out = Mention::the_content( $html );

		$this->assertStringContainsString(
			'<a class="atmosphere-mention" href="https://bsky.app/profile/alice.bsky.social">@alice.bsky.social</a>',
			$out
		);
	}

	/**
	 * The classify_handles() helper splits the body's mentions into the set the
	 * front end would link (`linkable`) and the set that lives only inside a protected
	 * region (`protected`), so the publish path resolves exactly what display
	 * links and blocks the buried ones from minting a facet.
	 */
	public function test_classify_handles_splits_linkable_from_protected() {
		$html = '<p>hi @alice.bsky.social <code>@buried.example.com</code> '
			. '<a href="https://x.test">@linked.example.com</a> bye @carol.example.com</p>';

		$classified = Mention::classify_handles( $html );

		$this->assertSame(
			array( 'alice.bsky.social', 'carol.example.com' ),
			\array_values( $classified['linkable'] ),
			'Only the non-protected handles are linkable, in first-appearance order.'
		);
		$this->assertArrayHasKey( 'buried.example.com', $classified['protected'] );
		$this->assertArrayHasKey( 'linked.example.com', $classified['protected'] );
		$this->assertArrayNotHasKey( 'buried.example.com', $classified['linkable'] );
		$this->assertArrayNotHasKey( 'linked.example.com', $classified['linkable'] );
	}

	/**
	 * A handle appearing both in a protected region and in linkable text stays
	 * linkable — the protected occurrence must not veto the visible one.
	 */
	public function test_classify_handles_keeps_handle_linked_when_also_visible() {
		$html = '<p>see <code>@alice.bsky.social</code> and also @alice.bsky.social here</p>';

		$classified = Mention::classify_handles( $html );

		$this->assertArrayHasKey( 'alice.bsky.social', $classified['linkable'] );
	}

	/**
	 * The classify_handles() helper bails on pathologically large content,
	 * exactly as the linkifier does, so publish and display agree to link nothing.
	 */
	public function test_classify_handles_bails_on_huge_content() {
		$html = '<p>@alice.bsky.social</p>' . \str_repeat( 'x', MB_IN_BYTES );

		$classified = Mention::classify_handles( $html );

		$this->assertSame( array(), $classified['linkable'] );
		$this->assertSame( array(), $classified['protected'] );
	}

	/**
	 * A handle glued to a preceding word only by inline markup
	 * (`<b>bob</b>@example.com`) is the tail of an email address, not a
	 * standalone handle: the cross-tag boundary must keep it unlinked, matching
	 * how the publish path reads the flattened plain text.
	 */
	public function test_skips_handle_glued_across_inline_tag() {
		$html = '<p><b>bob</b>@example.com writes</p>';
		$out  = Mention::the_content( $html );

		$this->assertStringNotContainsString( '<a class="atmosphere-mention"', $out );

		// The same input yields no linkable handle on the publish side either.
		$this->assertSame( array(), Mention::classify_handles( $html )['linkable'] );
	}

	/**
	 * A clean handle after a space that follows inline markup still links — the
	 * boundary only glues when there is no separating whitespace.
	 */
	public function test_links_handle_after_space_following_inline_tag() {
		$out = Mention::the_content( '<p><b>hi</b> @alice.bsky.social</p>' );

		$this->assertStringContainsString(
			'<a class="atmosphere-mention" href="https://bsky.app/profile/alice.bsky.social">@alice.bsky.social</a>',
			$out
		);
	}

	/**
	 * A start tag whose unquoted attribute value ends in `/`
	 * (`<a href=https://example.com/>`) is NOT self-closing, so a mention inside
	 * that still-open anchor stays protected and is never double-linked.
	 */
	public function test_unquoted_slash_attribute_does_not_self_close() {
		$html = '<p><a href=https://example.com/>see @alice.bsky.social</a></p>';

		$this->assertSame( $html, Mention::the_content( $html ) );
	}

	/**
	 * A mention immediately after a period — the dot-mention idiom — still
	 * links. Regression against a leading-boundary class that swallowed the `.`.
	 */
	public function test_links_dot_mention() {
		$out = Mention::the_content( '<p>.@alice.bsky.social check this out</p>' );

		$this->assertStringContainsString(
			'<a class="atmosphere-mention" href="https://bsky.app/profile/alice.bsky.social">@alice.bsky.social</a>',
			$out
		);
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
