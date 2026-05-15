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
}
