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
}
