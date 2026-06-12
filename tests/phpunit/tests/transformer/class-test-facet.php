<?php
/**
 * Tests for rich-text facet extraction.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests\Transformer;

use WP_UnitTestCase;
use Atmosphere\Transformer\Facet;

/**
 * Facet tests.
 */
class Test_Facet extends WP_UnitTestCase {

	/**
	 * Test extracting a URL link facet.
	 */
	public function test_extract_links() {
		$text   = 'Check out https://example.com for more.';
		$facets = Facet::extract( $text );

		$this->assertCount( 1, $facets );
		$this->assertSame( 'app.bsky.richtext.facet#link', $facets[0]['features'][0]['$type'] );
		$this->assertSame( 'https://example.com', $facets[0]['features'][0]['uri'] );
	}

	/**
	 * Test extracting a hashtag facet.
	 */
	public function test_extract_hashtags() {
		$text   = 'Hello #WordPress world';
		$facets = Facet::extract( $text );

		$this->assertCount( 1, $facets );
		$this->assertSame( 'app.bsky.richtext.facet#tag', $facets[0]['features'][0]['$type'] );
		$this->assertSame( 'WordPress', $facets[0]['features'][0]['tag'] );
	}

	/**
	 * Test extracting a mention facet.
	 */
	public function test_extract_mentions() {
		$text   = 'Hello @alice.bsky.social!';
		$facets = Facet::extract( $text );

		$this->assertCount( 1, $facets );
		$this->assertSame( 'app.bsky.richtext.facet#mention', $facets[0]['features'][0]['$type'] );
	}

	/**
	 * A `@bareword` with no dot is not a valid AT Protocol handle
	 * and must not produce a mention facet. The regex in
	 * `Facet::mentions()` already requires ≥2 labels; this test
	 * pins that user-visible behaviour so a future loosening of
	 * the regex doesn't accidentally start emitting mention facets
	 * with empty DIDs (or trigger DNS lookups on bareword handles).
	 */
	public function test_single_label_mention_produces_no_facet() {
		$text   = 'Hello @notadomain over there';
		$facets = Facet::extract( $text );

		$mention_facets = \array_filter(
			$facets,
			static fn( $facet ) => 'app.bsky.richtext.facet#mention' === ( $facet['features'][0]['$type'] ?? '' )
		);

		$this->assertCount( 0, $mention_facets );
	}

	/**
	 * Test that trailing punctuation is stripped from URLs.
	 */
	public function test_link_strips_trailing_punctuation() {
		$text   = 'Visit https://example.com.';
		$facets = Facet::extract( $text );

		$this->assertSame( 'https://example.com', $facets[0]['features'][0]['uri'] );
	}

	/**
	 * Test for_urls creates facets for specific URLs.
	 */
	public function test_for_urls() {
		$text   = 'Read more at https://example.com/post today.';
		$facets = Facet::for_urls( $text, array( 'https://example.com/post' ) );

		$this->assertCount( 1, $facets );
		$this->assertSame( 'https://example.com/post', $facets[0]['features'][0]['uri'] );
	}

	/**
	 * Test empty text returns no facets.
	 */
	public function test_extract_empty_text() {
		$this->assertSame( array(), Facet::extract( '' ) );
	}

	/**
	 * Test facets are sorted by byte offset.
	 */
	public function test_facets_sorted_by_position() {
		$text   = '#first https://example.com #last';
		$facets = Facet::extract( $text );

		$facet_count = \count( $facets );
		$this->assertGreaterThanOrEqual( 2, $facet_count );

		for ( $i = 1; $i < $facet_count; $i++ ) {
			$this->assertGreaterThanOrEqual(
				$facets[ $i - 1 ]['index']['byteStart'],
				$facets[ $i ]['index']['byteStart']
			);
		}
	}

	/**
	 * Applying a link facet must resolve Bluesky's truncated display
	 * string back to the real URL. This is the exact record reported in
	 * issue #132: the post `text` stores `bsky.app/profile/jere...` and
	 * the full URL lives only in the facet's `uri`.
	 */
	public function test_apply_resolves_truncated_link() {
		$text   = "And to put the new editor to the test, here is an exact copy of my post, just copied / pasted into SkyPress :)\n\nbsky.app/profile/jere...";
		$facets = array(
			array(
				'index'    => array(
					'byteStart' => 112,
					'byteEnd'   => 136,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => 'https://bsky.app/profile/jeremy.herve.bzh/post/3mnzu7nvcss2e',
					),
				),
			),
		);

		$result = Facet::apply( $text, $facets );

		$this->assertStringContainsString(
			'<a href="https://bsky.app/profile/jeremy.herve.bzh/post/3mnzu7nvcss2e">bsky.app/profile/jere...</a>',
			$result
		);
		// Surrounding plain text is preserved verbatim.
		$this->assertStringContainsString( 'just copied / pasted into SkyPress :)', $result );
	}

	/**
	 * Text with no facets must come back byte-for-byte identical.
	 */
	public function test_apply_without_facets_is_identity() {
		$text = 'Plain reply, no links here.';
		$this->assertSame( $text, Facet::apply( $text, array() ) );
	}

	/**
	 * Mention facets link to the author's profile by DID.
	 */
	public function test_apply_links_mention() {
		$text   = 'Hi @alice.bsky.social!';
		$facets = array(
			array(
				'index'    => array(
					'byteStart' => 3,
					'byteEnd'   => 21,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#mention',
						'did'   => 'did:plc:abc123',
					),
				),
			),
		);

		$result = Facet::apply( $text, $facets );

		$this->assertStringContainsString(
			'<a href="https://bsky.app/profile/did:plc:abc123">@alice.bsky.social</a>',
			$result
		);
	}

	/**
	 * Facet indexes are UTF-8 byte ranges. A multibyte character before
	 * the facet must not shift the splice — reassembly is byte-based.
	 */
	public function test_apply_respects_byte_offsets_with_multibyte() {
		// "héllo " is 7 bytes (é is 2 bytes); the link starts at byte 7.
		$text = 'héllo https://example.com end';
		$url  = 'https://example.com';

		$facets = array(
			array(
				'index'    => array(
					'byteStart' => 7,
					'byteEnd'   => 7 + \strlen( $url ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => $url,
					),
				),
			),
		);

		$result = Facet::apply( $text, $facets );

		$this->assertStringContainsString( '<a href="https://example.com">https://example.com</a>', $result );
		$this->assertStringContainsString( 'héllo ', $result );
		$this->assertStringContainsString( ' end', $result );
	}

	/**
	 * Display text comes from the remote record, so HTML-significant
	 * characters in it must be escaped — otherwise a crafted `text` could
	 * break out of the anchor or inject markup before KSES runs.
	 */
	public function test_apply_escapes_anchor_display_text() {
		$text   = 'evil</a><script>alert(1)</script>';
		$facets = array(
			array(
				'index'    => array(
					'byteStart' => 0,
					'byteEnd'   => \strlen( $text ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => 'https://example.com',
					),
				),
			),
		);

		$result = Facet::apply( $text, $facets );

		// The raw closing tag and script must not survive verbatim.
		$this->assertStringNotContainsString( '</a><script>', $result );
		$this->assertStringContainsString( '&lt;/a&gt;&lt;script&gt;', $result );
		// Exactly one real anchor, opened and closed by us.
		$this->assertSame( 1, \substr_count( $result, '<a href=' ) );
		$this->assertSame( 1, \substr_count( $result, '</a>' ) );
	}

	/**
	 * Malformed or out-of-bounds facet ranges are skipped without
	 * corrupting the surrounding text.
	 */
	public function test_apply_skips_invalid_ranges() {
		$text   = 'Short text';
		$facets = array(
			// byteEnd past the end of the string.
			array(
				'index'    => array(
					'byteStart' => 6,
					'byteEnd'   => 999,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => 'https://example.com',
					),
				),
			),
		);

		$this->assertSame( $text, Facet::apply( $text, $facets ) );
	}

	/**
	 * Tag facets link to the Bluesky hashtag-search page.
	 */
	public function test_apply_links_hashtag() {
		$text   = 'Love #WordPress here';
		$facets = array(
			array(
				'index'    => array(
					'byteStart' => 5,
					'byteEnd'   => 15,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#tag',
						'tag'   => 'WordPress',
					),
				),
			),
		);

		$result = Facet::apply( $text, $facets );

		$this->assertStringContainsString(
			'<a href="https://bsky.app/hashtag/WordPress">#WordPress</a>',
			$result
		);
	}

	/**
	 * Two facets that touch (the second starts exactly where the first
	 * ends) must both render — the boundary is inclusive on neither side.
	 */
	public function test_apply_handles_adjacent_facets() {
		$text   = 'ab';
		$facets = array(
			array(
				'index'    => array(
					'byteStart' => 0,
					'byteEnd'   => 1,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => 'https://a.example',
					),
				),
			),
			array(
				'index'    => array(
					'byteStart' => 1,
					'byteEnd'   => 2,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => 'https://b.example',
					),
				),
			),
		);

		$result = Facet::apply( $text, $facets );

		$this->assertSame( 2, \substr_count( $result, '<a href=' ) );
		$this->assertStringContainsString( '<a href="https://a.example">a</a>', $result );
		$this->assertStringContainsString( '<a href="https://b.example">b</a>', $result );
	}

	/**
	 * An unrecognised feature type keeps its display text rather than
	 * dropping the bytes it annotated.
	 */
	public function test_apply_unknown_feature_type_keeps_text() {
		$text   = 'hello world';
		$facets = array(
			array(
				'index'    => array(
					'byteStart' => 0,
					'byteEnd'   => 5,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#unknownFuture',
					),
				),
			),
		);

		$result = Facet::apply( $text, $facets );

		$this->assertSame( 'hello world', $result );
		$this->assertStringNotContainsString( '<a', $result );
	}

	/**
	 * A link whose scheme `esc_url()` strips (e.g. `javascript:`) must fall
	 * back to bare display text, never an empty `href` to the current page.
	 */
	public function test_apply_unsafe_scheme_falls_back_to_text() {
		$text   = 'click me';
		$facets = array(
			array(
				'index'    => array(
					'byteStart' => 0,
					'byteEnd'   => 8,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => 'javascript:alert(1)',
					),
				),
			),
		);

		$result = Facet::apply( $text, $facets );

		$this->assertStringNotContainsString( '<a', $result );
		$this->assertStringNotContainsString( 'href=""', $result );
		$this->assertStringContainsString( 'click me', $result );
	}

	/**
	 * Facets are untrusted PDS JSON. A facet entry that is a scalar rather
	 * than an array must be skipped, not fatal with a TypeError.
	 */
	public function test_apply_tolerates_non_array_facet_entry() {
		$text   = 'plain text';
		$facets = array( 'not-an-array' );

		$this->assertSame( $text, Facet::apply( $text, $facets ) );
	}

	/**
	 * A present-but-non-array `features` value must not reach the
	 * array-typed renderer — the facet is rendered as plain display text.
	 * Regression test for the TypeError flagged on PR #134.
	 */
	public function test_apply_tolerates_non_array_features() {
		$text   = 'hello world';
		$facets = array(
			array(
				'index'    => array(
					'byteStart' => 0,
					'byteEnd'   => 5,
				),
				'features' => 'oops-a-string',
			),
		);

		$result = Facet::apply( $text, $facets );

		$this->assertSame( 'hello world', $result );
		$this->assertStringNotContainsString( '<a', $result );
	}

	/**
	 * A non-array `index` value must be skipped rather than fataling on
	 * array access during the sort or the splice.
	 */
	public function test_apply_tolerates_non_array_index() {
		$text   = 'hello world';
		$facets = array(
			array(
				'index'    => 'oops',
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => 'https://example.com',
					),
				),
			),
		);

		$this->assertSame( $text, Facet::apply( $text, $facets ) );
	}
}
