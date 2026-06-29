<?php
/**
 * Tests for the Post transformer (bsky.app record composition).
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

use WP_UnitTestCase;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Publication;

/**
 * Post transformer tests.
 *
 * @coversDefaultClass \Atmosphere\Transformer\Post
 */
class Test_Post extends WP_UnitTestCase {

	/**
	 * Tear down filters between tests so overrides don't leak.
	 */
	public function tear_down() {
		\remove_all_filters( 'atmosphere_is_short_form_post' );
		\remove_all_filters( 'atmosphere_long_form_composition' );
		\remove_all_filters( 'atmosphere_teaser_thread_posts' );
		\remove_all_filters( 'atmosphere_transform_bsky_post' );
		\remove_all_filters( 'atmosphere_post_embed' );
		\remove_all_actions( 'atmosphere_long_form_strategy_downgraded' );
		parent::tear_down();
	}

	/**
	 * Encode a tiny but genuinely valid image for HTTP-fetch test bodies.
	 *
	 * The remote-fetch path validates fetched bytes with `wp_getimagesize()`,
	 * so test responses must carry real image data, not placeholder strings.
	 *
	 * @param string $format One of `jpeg`, `png`, `gif`, `webp`.
	 * @return string Encoded image bytes.
	 */
	private function image_bytes( string $format = 'jpeg' ): string {
		if ( ! \function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available in this environment.' );
		}

		$encoder = "image{$format}";
		if ( ! \function_exists( $encoder ) ) {
			$this->markTestSkipped( \sprintf( 'GD support for %s is not available.', $format ) );
		}

		$image = \imagecreatetruecolor( 4, 4 );

		\ob_start();
		$encoder( $image );
		$bytes = (string) \ob_get_clean();

		\imagedestroy( $image );

		return $bytes;
	}

	/**
	 * Invoke `Post::truncate_to_budget()` via reflection.
	 *
	 * The helper is private because it's an implementation detail of
	 * composition; tests exercise it directly to lock in the
	 * sentence / word / hard-cap contract the hook builders depend on.
	 *
	 * @param string $text            Input text.
	 * @param int    $max             Budget.
	 * @param bool   $prefer_sentence Whether to prefer a sentence break.
	 * @return string
	 */
	private function truncate( string $text, int $max, bool $prefer_sentence = true ): string {
		$post   = self::factory()->post->create_and_get();
		$method = new \ReflectionMethod( Post::class, 'truncate_to_budget' );

		return $method->invoke( new Post( $post ), $text, $max, $prefer_sentence );
	}

	/**
	 * A titled post with no post format uses the long-form path:
	 * title + excerpt + permalink as text, plus an external embed card.
	 *
	 * @covers ::transform
	 */
	public function test_long_form_titled_no_format_has_external_embed() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Long-form blog body.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'app.bsky.feed.post', $record['$type'] );
		$this->assertStringContainsString( 'A Titled Post', $record['text'] );
		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
	}

	/**
	 * When the composed title + excerpt + permalink overflows 300 characters,
	 * the link-card text is truncated to fit and still ends with the full
	 * permalink. Exercises `build_text()`'s overflow/reservation branch.
	 *
	 * @covers ::transform
	 */
	public function test_long_form_link_card_text_truncates_within_limit() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => \str_repeat( 'word ', 80 ), // ~400 chars, overflows on its own.
				'post_content' => '',
				'post_excerpt' => '',
			)
		);

		$record    = ( new Post( $post ) )->transform();
		$permalink = \get_permalink( $post );

		// ASCII body, so code points and graphemes coincide.
		$this->assertLessThanOrEqual( 300, \mb_strlen( $record['text'] ) );
		$this->assertStringEndsWith( $permalink, $record['text'] );
	}

	/**
	 * An untitled post is short-form: body becomes the text, no embed.
	 *
	 * @covers ::transform
	 */
	public function test_untitled_post_is_short_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'A quick untitled thought.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'A quick untitled thought.', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * A short-form post's inline links survive as Bluesky link facets,
	 * keeping the human-readable anchor text (not the raw URL) in the post
	 * text. Without this, `sanitize_text()` strips the `<a>` tags before
	 * facet extraction and the links are silently dropped.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_preserves_inline_link_as_facet() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'Read the linked article from <a href="https://example.com/page">the source</a> now.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		// The anchor text stays visible; the raw URL does not leak into text.
		$this->assertStringContainsString( 'the source', $record['text'] );
		$this->assertStringNotContainsString( 'https://example.com/page', $record['text'] );

		$this->assertArrayHasKey( 'facets', $record );

		$link_facet = null;
		foreach ( $record['facets'] as $facet ) {
			foreach ( $facet['features'] as $feature ) {
				if ( 'app.bsky.richtext.facet#link' === $feature['$type'] ) {
					$link_facet = $facet;
				}
			}
		}

		$this->assertNotNull( $link_facet, 'A link facet should be emitted for the inline link.' );
		$this->assertSame( 'https://example.com/page', $link_facet['features'][0]['uri'] );

		// The facet's byte range covers exactly the anchor text.
		$slice = \substr(
			$record['text'],
			$link_facet['index']['byteStart'],
			$link_facet['index']['byteEnd'] - $link_facet['index']['byteStart']
		);
		$this->assertSame( 'the source', $slice );
	}

	/**
	 * The same anchor text linked twice produces two distinct facets, each
	 * pointing at its own URL — the marker swap keeps the byte ranges from
	 * collapsing onto the first occurrence.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_same_word_linked_twice_keeps_distinct_facets() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'Read the announcement <a href="https://example.org/">here</a>, or grab the source <a href="https://example.com/">here</a>.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$links = array();
		foreach ( $record['facets'] as $facet ) {
			foreach ( $facet['features'] as $feature ) {
				if ( 'app.bsky.richtext.facet#link' === $feature['$type'] ) {
					$links[] = array(
						'uri'   => $feature['uri'],
						'slice' => \substr(
							$record['text'],
							$facet['index']['byteStart'],
							$facet['index']['byteEnd'] - $facet['index']['byteStart']
						),
					);
				}
			}
		}

		$this->assertCount( 2, $links, 'Both links should produce facets.' );
		$this->assertSame( 'here', $links[0]['slice'] );
		$this->assertSame( 'here', $links[1]['slice'] );
		$this->assertSame( 'https://example.org/', $links[0]['uri'] );
		$this->assertSame( 'https://example.com/', $links[1]['uri'] );
		// The two facets cover different byte ranges (first vs. second word).
		$this->assertNotSame(
			$record['facets'][0]['index']['byteStart'],
			$record['facets'][1]['index']['byteStart']
		);
	}

	/**
	 * A relative anchor href does not become a link facet: it has no
	 * explicit http(s) scheme, so the anchor text is kept but no (bogus,
	 * `http://`-prefixed) external link is emitted.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_relative_link_is_not_faceted() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'See the <a href="relative/page">other page</a> for details.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertStringContainsString( 'other page', $record['text'] );

		foreach ( $record['facets'] ?? array() as $facet ) {
			foreach ( $facet['features'] as $feature ) {
				$this->assertNotSame(
					'app.bsky.richtext.facet#link',
					$feature['$type'],
					'A relative link must not produce a link facet.'
				);
			}
		}
	}

	/**
	 * A link whose anchor text falls past the 300-character truncation point
	 * is dropped rather than emitting a facet with an out-of-range offset.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_drops_link_facet_past_truncation() {
		$filler = \str_repeat( 'word ', 70 ); // ~350 chars before the link.
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => $filler . ' <a href="https://example.com/late">late link</a>.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertLessThanOrEqual( 300, \mb_strlen( $record['text'] ) );
		$this->assertNotContains( 'https://example.com/late', $this->facet_link_uris( $record ), 'The past-cut link must be dropped.' );
		$this->assertFacetsWithinText( $record );
	}

	/**
	 * A link that straddles the truncation boundary — its anchor text starts
	 * before the cut but runs past it — is dropped entirely rather than
	 * emitting a facet whose range extends beyond the text.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_drops_link_facet_straddling_truncation() {
		// ~295 chars of filler, then a long-anchor link that crosses 300.
		$filler = \str_repeat( 'word ', 59 );
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => $filler . '<a href="https://example.com/x">' . \str_repeat( 'long', 10 ) . '</a> tail.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertLessThanOrEqual( 300, \mb_strlen( $record['text'] ) );
		$this->assertNotContains( 'https://example.com/x', $this->facet_link_uris( $record ), 'The straddling link must be dropped, not clipped.' );
		$this->assertFacetsWithinText( $record );
	}

	/**
	 * An emoji-heavy short-form body whose code-point count exceeds 300 but
	 * whose grapheme count stays under it is published whole — Bluesky's cap
	 * is 300 graphemes, so a ZWJ family emoji counts as one, not five.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_keeps_emoji_body_within_grapheme_limit() {
		if ( ! \function_exists( 'grapheme_strlen' ) ) {
			$this->markTestSkipped( 'intl extension required for grapheme counting.' );
		}

		// 70 family emoji: 70 graphemes, but 350 code points.
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => \str_repeat( $family, 70 ),
			)
		);

		$record = ( new Post( $post ) )->transform();

		// Whole body survives: 70 graphemes, no ellipsis, no cluster split.
		$this->assertSame( 70, \grapheme_strlen( $record['text'] ) );
		$this->assertStringNotContainsString( '...', $record['text'] );
	}

	/**
	 * An inline link preceded by enough emoji to push the code-point count
	 * past 300 — but not the grapheme count — keeps its facet. Counting code
	 * points here would truncate the body and drop the link.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_keeps_link_facet_after_emoji_overflow() {
		if ( ! \function_exists( 'grapheme_strlen' ) ) {
			$this->markTestSkipped( 'intl extension required for grapheme counting.' );
		}

		// 70 family emoji (350 code points / 70 graphemes), then a link.
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => \str_repeat( $family, 70 ) . ' Read <a href="https://example.com/page">the source</a>.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertLessThanOrEqual( 300, \grapheme_strlen( $record['text'] ) );
		$this->assertContains( 'https://example.com/page', $this->facet_link_uris( $record ), 'The link must survive emoji overflow.' );
		$this->assertFacetsWithinText( $record );
	}

	/**
	 * The pre-publish projection counts graphemes, so its "X / 300" matches
	 * the number Bluesky's own composer shows — a family emoji is one, not
	 * five code points.
	 *
	 * @covers ::project
	 */
	public function test_project_counts_emoji_body_as_graphemes() {
		if ( ! \function_exists( 'grapheme_strlen' ) ) {
			$this->markTestSkipped( 'intl extension required for grapheme counting.' );
		}

		// 50 family emoji: 50 graphemes, 250 code points.
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => \str_repeat( $family, 50 ),
			)
		);

		$projection = ( new Post( $post ) )->project();

		$this->assertSame( 50, $projection['records'][0]['characters'] );
		$this->assertFalse( $projection['records'][0]['over_limit'] );
	}

	/**
	 * An `href`-like attribute on another key (e.g. `data-href`) is not
	 * treated as the link target, so non-link markup never becomes a facet.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_data_href_is_not_faceted() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'See <a data-href="https://example.com/tracked">the widget</a> below.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertStringContainsString( 'the widget', $record['text'] );
		$this->assertNotContains( 'https://example.com/tracked', $this->facet_link_uris( $record ) );
	}

	/**
	 * Whitespace that sits just inside the anchor tags is preserved, so the
	 * words around the link don't fuse together.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_preserves_whitespace_inside_link() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'Click<a href="https://example.com/here"> here</a> now.',
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertStringContainsString( 'Click here now', $record['text'] );
		$this->assertStringNotContainsString( 'Clickhere', $record['text'] );
	}

	/**
	 * Collect every link-facet URI from a record.
	 *
	 * @param array $record Bsky post record.
	 * @return string[]
	 */
	private function facet_link_uris( array $record ): array {
		$uris = array();
		foreach ( $record['facets'] ?? array() as $facet ) {
			foreach ( $facet['features'] as $feature ) {
				if ( 'app.bsky.richtext.facet#link' === $feature['$type'] ) {
					$uris[] = $feature['uri'];
				}
			}
		}
		return $uris;
	}

	/**
	 * Assert no facet references a byte offset past the record text.
	 *
	 * @param array $record Bsky post record.
	 */
	private function assertFacetsWithinText( array $record ): void {
		$length = \strlen( $record['text'] );
		foreach ( $record['facets'] ?? array() as $facet ) {
			$this->assertGreaterThanOrEqual( 0, $facet['index']['byteStart'] );
			$this->assertLessThanOrEqual(
				$length,
				$facet['index']['byteEnd'],
				'A facet byteEnd must not exceed the text length.'
			);
		}
	}

	/**
	 * A titled post with post_format=status is short-form.
	 *
	 * @covers ::transform
	 */
	public function test_titled_post_with_status_format_is_short_form() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Has a title but also a format',
				'post_content' => 'Short-form body despite the title.',
			)
		);
		\set_post_format( $post_id, 'status' );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'Short-form body despite the title.', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * Any post format triggers short-form, not just status.
	 *
	 * @covers ::transform
	 */
	public function test_titled_post_with_aside_format_is_short_form() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Has a title',
				'post_content' => 'An aside.',
			)
		);
		\set_post_format( $post_id, 'aside' );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'An aside.', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * A titleless post whose body overflows the 300-char native cap is not
	 * really short-form: it falls back to the long-form link-card so the
	 * reader gets a teaser plus a route back to the original, instead of a
	 * sentence fragment with no link home.
	 *
	 * @covers ::transform
	 * @covers ::is_short_form_post
	 */
	public function test_long_titleless_post_falls_back_to_link_card() {
		$long_body = \str_repeat( 'Lorem ipsum dolor sit amet. ', 50 );
		$post      = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => $long_body,
			)
		);

		$transformer = new Post( $post );

		$this->assertFalse(
			$transformer->is_short_form_post(),
			'An overflowing titleless post should be treated as long-form.'
		);

		$record = $transformer->transform();

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
		$this->assertSame( \get_permalink( $post ), $record['embed']['external']['uri'] );
		$this->assertStringContainsString( \get_permalink( $post ), $record['text'] );
	}

	/**
	 * The overflow gate also applies to post-format posts, not just
	 * empty-title ones: a titled `aside` whose body exceeds the cap falls
	 * back to the long-form link-card.
	 *
	 * @covers ::transform
	 * @covers ::is_short_form_post
	 */
	public function test_long_format_post_falls_back_to_link_card() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Has a title but also an aside format',
				'post_content' => \str_repeat( 'Lorem ipsum dolor sit amet. ', 50 ),
			)
		);
		\set_post_format( $post_id, 'aside' );
		$post = \get_post( $post_id );

		$transformer = new Post( $post );

		$this->assertFalse( $transformer->is_short_form_post() );

		$record = $transformer->transform();

		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
	}

	/**
	 * A titleless post that fits within the native cap stays short-form:
	 * the body ships verbatim with no embed and no permalink.
	 *
	 * @covers ::transform
	 * @covers ::is_short_form_post
	 */
	public function test_short_titleless_post_stays_short_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'A quick untitled thought that fits.',
			)
		);

		$transformer = new Post( $post );

		$this->assertTrue( $transformer->is_short_form_post() );

		$record = $transformer->transform();

		$this->assertSame( 'A quick untitled thought that fits.', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * The overflow gate is at exactly 300 characters: a 300-char titleless
	 * body stays short-form, a 301-char one becomes long-form.
	 *
	 * @covers ::is_short_form_post
	 */
	public function test_short_form_overflow_boundary() {
		$at_limit = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => \str_repeat( 'a', 300 ),
			)
		);
		$this->assertTrue(
			( new Post( $at_limit ) )->is_short_form_post(),
			'A 300-character body is at the cap and stays short-form.'
		);

		$over_limit = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => \str_repeat( 'a', 301 ),
			)
		);
		$this->assertFalse(
			( new Post( $over_limit ) )->is_short_form_post(),
			'A 301-character body overflows and becomes long-form.'
		);
	}

	/**
	 * `build_short_form_text()` still defensively clamps to the cap when the
	 * filter forces an overflowing body to stay short-form.
	 *
	 * @covers ::transform
	 */
	public function test_forced_short_form_over_limit_is_clamped() {
		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => \str_repeat( 'word ', 100 ), // 500 characters.
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayNotHasKey( 'embed', $record, 'Forced short-form must not attach a link card.' );
		$this->assertLessThanOrEqual( 300, \mb_strlen( $record['text'] ) );
	}

	/**
	 * The short-form verdict is memoized per transformer instance, so a
	 * stateful `atmosphere_is_short_form_post` filter is consulted exactly
	 * once. Publisher evaluates the verdict several times per publish (the
	 * document-strongRef precompute, the short/long routing, and transform());
	 * without memoization a filter that returns a different value across those
	 * calls could precompute for short-form and then publish a link card.
	 *
	 * @covers ::is_short_form_post
	 */
	public function test_short_form_verdict_is_memoized() {
		$calls  = 0;
		$filter = function () use ( &$calls ) {
			++$calls;
			// Flip the answer after the first call to simulate a stateful
			// subscriber that would otherwise split the publish decision.
			return 1 === $calls;
		};
		\add_filter( 'atmosphere_is_short_form_post', $filter );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'A short untitled thought.',
			)
		);

		$transformer = new Post( $post );

		$first  = $transformer->is_short_form_post();
		$second = $transformer->is_short_form_post();

		\remove_filter( 'atmosphere_is_short_form_post', $filter );

		$this->assertTrue( $first, 'First call should reflect the filter result.' );
		$this->assertSame( $first, $second, 'Repeated calls must return the cached verdict.' );
		$this->assertSame( 1, $calls, 'The filter must be consulted only once per instance.' );
	}

	/**
	 * The atmosphere_is_short_form_post filter can force short-form on a
	 * titled-no-format post that would otherwise be long-form.
	 *
	 * @covers ::transform
	 */
	public function test_filter_can_force_short_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body overridden to short-form.',
			)
		);

		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'Body overridden to short-form.', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * The filter can force long-form on an untitled post that would
	 * otherwise be short-form.
	 *
	 * @covers ::transform
	 */
	public function test_filter_can_force_long_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'Would be short-form by default.',
			)
		);

		\add_filter( 'atmosphere_is_short_form_post', '__return_false' );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
	}

	/**
	 * The filter receives the computed default and the post.
	 *
	 * @covers ::transform
	 */
	public function test_filter_receives_computed_default_and_post() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body.',
			)
		);

		$received_default = null;
		$received_post_id = null;
		$callback         = function ( $is_short, $filter_post ) use ( &$received_default, &$received_post_id ) {
			$received_default = $is_short;
			$received_post_id = $filter_post->ID;
			return $is_short;
		};

		\add_filter( 'atmosphere_is_short_form_post', $callback, 10, 2 );

		( new Post( $post ) )->transform();

		$this->assertFalse( $received_default, 'Default for titled-no-format post should be false (long-form).' );
		$this->assertSame( $post->ID, $received_post_id, 'Filter should receive the post being transformed.' );
	}

	/**
	 * Password-protected posts must not expose protected fields through
	 * the Bluesky transformer, even when called directly.
	 *
	 * @covers ::transform
	 */
	public function test_password_protected_transform_is_redacted() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'CONFIDENTIAL-TITLE',
				'post_content'  => 'CONFIDENTIAL-BODY',
				'post_excerpt'  => 'CONFIDENTIAL-EXCERPT',
				'post_password' => 'secret',
			)
		);
		\wp_set_post_tags( $post->ID, array( 'CONFIDENTIAL-TAG' ) );

		$record = ( new Post( $post ) )->transform();
		$json   = (string) \wp_json_encode( $record );

		$this->assertSame( '', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
		$this->assertArrayNotHasKey( 'tags', $record );
		$this->assertStringNotContainsString( 'CONFIDENTIAL', $json );
	}

	/**
	 * A literal password value of "0" is still an intentional password.
	 *
	 * @covers ::transform
	 */
	public function test_zero_string_password_transform_is_redacted() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'CONFIDENTIAL-TITLE',
				'post_content'  => 'CONFIDENTIAL-BODY',
				'post_password' => '0',
			)
		);

		$record = ( new Post( $post ) )->transform();
		$json   = (string) \wp_json_encode( $record );

		$this->assertSame( '', $record['text'] );
		$this->assertStringNotContainsString( 'CONFIDENTIAL', $json );
	}

	/**
	 * Non-published posts redact the same fields as password-protected posts.
	 *
	 * @covers ::transform
	 */
	public function test_draft_transform_is_redacted() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'CONFIDENTIAL-TITLE',
				'post_content' => 'CONFIDENTIAL-BODY',
				'post_excerpt' => 'CONFIDENTIAL-EXCERPT',
			)
		);

		$record = ( new Post( $post ) )->transform();
		$json   = (string) \wp_json_encode( $record );

		$this->assertSame( '', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
		$this->assertStringNotContainsString( 'CONFIDENTIAL', $json );
	}

	/**
	 * Redacted transforms must not expose the raw post object to record filters.
	 *
	 * @covers ::transform
	 */
	public function test_password_protected_transform_does_not_fire_bsky_record_filter() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'CONFIDENTIAL-TITLE',
				'post_content'  => 'CONFIDENTIAL-BODY',
				'post_password' => 'secret',
			)
		);

		$called = false;
		\add_filter(
			'atmosphere_transform_bsky_post',
			static function ( array $record ) use ( &$called ): array {
				$called         = true;
				$record['text'] = 'CONFIDENTIAL-REINJECTED';
				return $record;
			}
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( '', $record['text'] );
		$this->assertFalse( $called, 'Redacted transforms must not expose the post object to bsky record filters.' );
	}

	/**
	 * The short-form discriminator filter receives the raw post object,
	 * so redacted transforms must not call it.
	 *
	 * @covers ::transform
	 */
	public function test_password_protected_transform_does_not_fire_short_form_filter() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'CONFIDENTIAL-TITLE',
				'post_content'  => 'CONFIDENTIAL-BODY',
				'post_password' => 'secret',
			)
		);

		$called = false;
		\add_filter(
			'atmosphere_is_short_form_post',
			static function () use ( &$called ): bool {
				$called = true;
				return false;
			}
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( '', $record['text'] );
		$this->assertFalse( $called, 'Redacted transforms must not expose the post object to short-form filters.' );
	}

	/**
	 * Long-form composition paths also redact protected fields.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_password_protected_long_form_records_are_redacted() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'CONFIDENTIAL-TITLE',
				'post_content'  => 'CONFIDENTIAL-BODY',
				'post_excerpt'  => 'CONFIDENTIAL-EXCERPT',
				'post_password' => 'secret',
			)
		);
		\wp_set_post_tags( $post->ID, array( 'CONFIDENTIAL-TAG' ) );

		\add_filter(
			'atmosphere_long_form_composition',
			static fn() => 'teaser-thread'
		);

		$records = ( new Post( $post ) )->build_long_form_records();
		$json    = (string) \wp_json_encode( $records );

		$this->assertCount( 1, $records );
		$this->assertSame( '', $records[0]['text'] );
		$this->assertArrayNotHasKey( 'embed', $records[0] );
		$this->assertArrayNotHasKey( 'tags', $records[0] );
		$this->assertStringNotContainsString( 'CONFIDENTIAL', $json );
	}

	/*
	 * -----------------------------------------------------------------
	 * truncate_to_budget() — private helper covered via reflection.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Text under budget returns unchanged.
	 */
	public function test_truncate_to_budget_returns_unchanged_when_under_budget() {
		$this->assertSame( 'Hello world.', $this->truncate( 'Hello world.', 100 ) );
	}

	/**
	 * Prefers a sentence boundary inside the budget over a word boundary later.
	 */
	public function test_truncate_to_budget_prefers_sentence_when_enabled() {
		$text = \str_repeat( 'Hi there. ', 35 ); // 350 chars, sentence every 10.
		$cut  = $this->truncate( $text, 280, true );
		$last = \substr( $cut, -1 );
		$this->assertLessThanOrEqual( 280, \mb_strlen( $cut ) );
		$this->assertSame( '.', $last, 'Sentence-preferred cut must end at sentence punctuation.' );
		// The text at the boundary is `"Hi there. " x N`, cut after the 28th period (byte 279).
		$this->assertSame( 279, \strlen( $cut ) );
	}

	/**
	 * Cut includes optional trailing close-punctuation after the sentence stop.
	 */
	public function test_truncate_to_budget_allows_trailing_close_punctuation() {
		// Clamp to 5 chars: `Hi!" ` — regex matches `!"` (close-quote allowed). Cut = `Hi!"`.
		$cut = $this->truncate( 'Hi!" Then I left.', 5, true );
		$this->assertSame( 'Hi!"', $cut );
	}

	/**
	 * Falls back to the last word boundary when no sentence break is in range.
	 */
	public function test_truncate_to_budget_falls_back_to_word_boundary_when_no_sentence() {
		$text = 'The quick brown fox jumps over the lazy dog';
		$cut  = $this->truncate( $text, 20, true );
		// mb_substr 0,20 = "The quick brown fox ", word cut strips trailing space+token → "The quick brown fox".
		$this->assertSame( 'The quick brown fox', $cut );
	}

	/**
	 * With prefer_sentence=false, ignores sentence breaks and uses word boundary.
	 */
	public function test_truncate_to_budget_word_boundary_only_when_prefer_sentence_false() {
		// Sentence break at char 3 (`.`) would dominate if prefer_sentence were true.
		$text = 'Hi. Then hello world goodbye.';
		$cut  = $this->truncate( $text, 12, false );
		// Clamp "Hi. Then hel", word-cut strips " hel" → "Hi. Then".
		$this->assertSame( 'Hi. Then', $cut );
	}

	/**
	 * Single token longer than budget: hard-cap with a trailing ellipsis.
	 */
	public function test_truncate_to_budget_hard_cap_for_single_long_word() {
		$cut = $this->truncate( 'Supercalifragilisticexpialidocious', 10, true );
		$this->assertSame( 10, \mb_strlen( $cut ) );
		$this->assertSame( '…', \mb_substr( $cut, -1 ) );
		$this->assertNotSame( '', $cut );
	}

	/**
	 * The hard-cap path measures in graphemes: an unbroken run of ZWJ family
	 * emoji past the budget is clamped to whole clusters plus an ellipsis,
	 * never split into mojibake.
	 */
	public function test_truncate_to_budget_hard_cap_counts_graphemes() {
		if ( ! \function_exists( 'grapheme_strlen' ) ) {
			$this->markTestSkipped( 'intl extension required for grapheme counting.' );
		}

		// A single unbroken token (no spaces): 50 family emoji, 250 code points.
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
		$cut    = $this->truncate( \str_repeat( $family, 50 ), 10, true );

		// 9 family clusters + the ellipsis: 10 graphemes, no split cluster.
		$this->assertSame( 10, \grapheme_strlen( $cut ) );
		$this->assertSame( '…', \mb_substr( $cut, -1 ) );
		$this->assertSame( \str_repeat( $family, 9 ) . '…', $cut );
	}

	/*
	 * -----------------------------------------------------------------
	 * build_long_form_records() — strategy branches.
	 * -----------------------------------------------------------------
	 */

	/**
	 * No filter: long-form default is link-card. Single record, text and embed
	 * match today's transform() output byte-for-byte on the relevant fields.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_default_is_link_card() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		$transformer = new Post( $post );
		$records     = $transformer->build_long_form_records();
		$oracle      = $transformer->transform();

		$this->assertCount( 1, $records );
		$this->assertSame( $oracle['text'], $records[0]['text'] );
		$this->assertArrayHasKey( 'embed', $records[0] );
		$this->assertSame( $oracle['embed'], $records[0]['embed'] );
	}

	/**
	 * The `atmosphere_transform_bsky_post` filter fires once per record
	 * in thread strategies — not once per WP post.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_applies_atmosphere_transform_bsky_post_per_entry() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body sentence one. Body sentence two.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter(
			'atmosphere_transform_bsky_post',
			static function ( $record ) {
				$record['text'] .= ' __transformed__';
				return $record;
			}
		);

		$records = ( new Post( $post ) )->build_long_form_records();

		// Default thread shape is hook + body chunk + CTA.
		$this->assertCount( 3, $records );
		foreach ( $records as $record ) {
			$this->assertStringEndsWith( ' __transformed__', $record['text'] );
		}
	}

	/**
	 * Long-form filters receive records with `createdAt` plus context for
	 * distinguishing thread entries before Publisher adds final reply refs.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_filter_receives_created_at_and_context() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body sentence one. Body sentence two.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$seen = array();
		\add_filter(
			'atmosphere_transform_bsky_post',
			static function ( $record, $filtered_post, $context = array() ) use ( &$seen ) {
				$seen[] = array(
					'createdAt' => $record['createdAt'] ?? '',
					'context'   => $context,
				);

				return $record;
			},
			10,
			3
		);

		( new Post( $post ) )->build_long_form_records();

		// Default thread shape is hook + body chunk + CTA.
		$this->assertCount( 3, $seen );
		foreach ( $seen as $entry ) {
			$this->assertNotEmpty( $entry['createdAt'] );
			$this->assertSame( 'teaser-thread', $entry['context']['strategy'] ?? '' );
		}
		$this->assertSame( 0, $seen[0]['context']['thread_index'] ?? null );
		$this->assertFalse( $seen[0]['context']['is_thread_reply'] ?? true );
		$this->assertSame( 1, $seen[1]['context']['thread_index'] ?? null );
		$this->assertTrue( $seen[1]['context']['is_thread_reply'] ?? false );
		$this->assertSame( 2, $seen[2]['context']['thread_index'] ?? null );
		$this->assertTrue( $seen[2]['context']['is_thread_reply'] ?? false );
	}

	/**
	 * Truncate-link branch: single record, no embed, text ends with permalink,
	 * and facets include a link covering the permalink.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_truncate_link_branch() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => \str_repeat( 'Some body content. ', 20 ),
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'truncate-link' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 1, $records );
		$this->assertArrayNotHasKey( 'embed', $records[0] );

		$permalink = \get_permalink( $post );
		$this->assertStringEndsWith( "\n\n" . $permalink, $records[0]['text'] );

		$has_link_facet = false;
		foreach ( $records[0]['facets'] ?? array() as $facet ) {
			foreach ( $facet['features'] as $feature ) {
				if ( 'app.bsky.richtext.facet#link' === ( $feature['$type'] ?? '' )
					&& ( $feature['uri'] ?? '' ) === $permalink
				) {
					$has_link_facet = true;
				}
			}
		}
		$this->assertTrue( $has_link_facet, 'Permalink should be captured by a link facet.' );
	}

	/**
	 * Truncate-link branch: an unusually long permalink must not push the
	 * final post text over Bluesky's 300-character limit.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_truncate_link_long_permalink_stays_under_limit() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => \str_repeat( 'Some body content. ', 20 ),
			)
		);

		$permalink_filter = static fn() => 'https://example.com/' . \str_repeat( 'a', 320 );

		\add_filter( 'atmosphere_long_form_composition', fn() => 'truncate-link' );
		\add_filter( 'post_link', $permalink_filter );

		try {
			$records = ( new Post( $post ) )->build_long_form_records();
		} finally {
			\remove_filter( 'post_link', $permalink_filter );
		}

		$this->assertCount( 1, $records );
		$this->assertLessThanOrEqual( 300, \mb_strlen( $records[0]['text'] ) );
		$this->assertArrayHasKey( 'embed', $records[0], 'Overlong inline permalinks should fall back to a link card.' );
	}

	/**
	 * Filtered teaser-thread entries are sanitized and clamped before
	 * they are turned into Bluesky records.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_filter_entries_are_sanitized_and_clamped() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body content with enough prose to form a hook.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter(
			'atmosphere_teaser_thread_posts',
			fn() => array(
				'<strong>' . \str_repeat( 'A', 400 ) . '</strong>',
				\str_repeat( 'B', 400 ),
			)
		);

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 2, $records );
		foreach ( $records as $record ) {
			$this->assertLessThanOrEqual( 300, \mb_strlen( $record['text'] ) );
			$this->assertStringNotContainsString( '<strong>', $record['text'] );
		}
	}

	/**
	 * Teaser-thread default: 3 entries — hook (sentence-cut), body chunk
	 * continuing the prose, and CTA `Continue reading: <https?://...>`.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_default_three_entries() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Long Post',
				// 35 sentences × 10 chars = 350 chars; body exceeds the 280 hook budget.
				'post_content' => \str_repeat( 'Hi there. ', 35 ),
				// Force body-path hook; factory auto-fills "Post excerpt NNN" otherwise.
				'post_excerpt' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 3, $records );

		// Hook.
		$hook = $records[0]['text'];
		$this->assertLessThanOrEqual( 280, \mb_strlen( $hook ) );
		$this->assertContains( \substr( $hook, -1 ), array( '.', '!', '?' ), 'Hook should end at sentence punctuation.' );
		$this->assertStringNotContainsString( \get_permalink( $post ), $hook );
		$this->assertArrayNotHasKey( 'embed', $records[0] );

		// Body chunk: non-empty, sentence-bounded, distinct prose from the hook.
		$chunk = $records[1]['text'];
		$this->assertNotEmpty( \trim( $chunk ) );
		$this->assertLessThanOrEqual( 280, \mb_strlen( $chunk ) );
		$this->assertContains(
			\substr( \rtrim( $chunk ), -1 ),
			array( '.', '!', '?' ),
			'Body chunk should end at sentence punctuation when one is in budget.'
		);
		$this->assertArrayNotHasKey( 'embed', $records[1] );

		// CTA.
		$cta = $records[2]['text'];
		$this->assertMatchesRegularExpression( '~^Continue reading: https?://~', $cta );

		$has_cta_link_facet = false;
		foreach ( $records[2]['facets'] ?? array() as $facet ) {
			foreach ( $facet['features'] as $feature ) {
				if ( 'app.bsky.richtext.facet#link' === ( $feature['$type'] ?? '' ) ) {
					$has_cta_link_facet = true;
				}
			}
		}
		$this->assertTrue( $has_cta_link_facet, 'CTA permalink should produce a link facet.' );
	}

	/**
	 * When no sentence boundary exists inside 280 chars the hook falls back
	 * to a word boundary — never ends mid-word.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_hook_falls_back_to_word_boundary_when_no_sentence() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Unpunctuated',
				// 36 repetitions × 18 chars = 648 chars, no `.`/`!`/`?`.
				'post_content' => \str_repeat( 'abcdefgh ijklmnop ', 36 ),
				// Force body-path hook; factory auto-fills "Post excerpt NNN" otherwise.
				'post_excerpt' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$hook = ( new Post( $post ) )->build_long_form_records()[0]['text'];

		$this->assertLessThanOrEqual( 280, \mb_strlen( $hook ) );
		// Body is built of 8-char words, so a word-boundary cut must not
		// leave a trailing run shorter than 8 chars.
		$this->assertDoesNotMatchRegularExpression(
			'~\s\S{1,7}$~',
			$hook,
			'Hook should end at a complete word, not mid-word.'
		);
	}

	/**
	 * Post excerpt, when set, takes precedence over body-derived hooks.
	 *
	 * The body chunk continues from the start of the body, not from where
	 * the excerpt would have ended in the body — the excerpt is curated
	 * copy, not a sliding window over the body.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_uses_excerpt_when_set() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body sentence one. Body sentence two.',
				'post_excerpt' => 'Custom-curated hook copy.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 3, $records );
		$this->assertSame( 'Custom-curated hook copy.', $records[0]['text'] );
		$this->assertStringContainsString( 'Body sentence one.', $records[1]['text'] );
		$this->assertStringNotContainsString( 'Custom-curated', $records[1]['text'] );
	}

	/**
	 * Empty body + empty excerpt: strategy silently degrades to link-card
	 * and fires the observability action so ops can distinguish fallback
	 * from intentional configuration.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_degrades_to_link_card_when_body_and_excerpt_empty() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Almost Empty Post',
				'post_content' => 'Hi',  // 2 chars — below the 10-char floor.
				'post_excerpt' => '',
			)
		);

		$events = array();
		\add_action(
			'atmosphere_long_form_strategy_downgraded',
			function ( $downgrade_post, $requested, $effective ) use ( &$events ) {
				$events[] = array( $downgrade_post->ID, $requested, $effective );
			},
			10,
			3
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 1, $records );
		$this->assertArrayHasKey( 'embed', $records[0] );
		$this->assertSame( 'app.bsky.embed.external', $records[0]['embed']['$type'] );

		$this->assertCount( 1, $events, 'Downgrade action should fire exactly once.' );
		$this->assertSame( array( $post->ID, 'teaser-thread', 'link-card' ), $events[0] );
	}

	/**
	 * Teaser-thread downgrades to link-card whenever the localized CTA
	 * (`Continue reading: <permalink>`) exceeds 300 chars — even when
	 * the bare permalink is below the 300-char limit. Otherwise the CTA
	 * gets word-truncated and the URL fragment is dropped, shipping a
	 * thread whose call-to-action has no link.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_downgrades_when_cta_overflows() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => \str_repeat( 'Some body content. ', 20 ),
			)
		);

		// Bare permalink under 300 chars but CTA "Continue reading: <permalink>"
		// pushes the composed text past the 300-char limit.
		$permalink_filter = static fn() => 'https://example.com/' . \str_repeat( 'a', 280 );

		$events = array();
		\add_action(
			'atmosphere_long_form_strategy_downgraded',
			function ( $downgrade_post, $requested, $effective ) use ( &$events ) {
				$events[] = array( $downgrade_post->ID, $requested, $effective );
			},
			10,
			3
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter( 'post_link', $permalink_filter );

		try {
			$records = ( new Post( $post ) )->build_long_form_records();
		} finally {
			\remove_filter( 'post_link', $permalink_filter );
		}

		$this->assertCount( 1, $records );
		$this->assertArrayHasKey( 'embed', $records[0] );
		$this->assertCount( 1, $events );
		$this->assertSame( array( $post->ID, 'teaser-thread', 'link-card' ), $events[0] );
	}

	/**
	 * Long-permalink fallback: when the permalink alone is >= 300 chars,
	 * teaser-thread / truncate-link both fall back to link-card and fire
	 * the observability action so the downgrade is distinguishable from
	 * an intentional link-card configuration.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_long_permalink_fires_downgrade_action() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => \str_repeat( 'Some body content. ', 20 ),
			)
		);

		$permalink_filter = static fn() => 'https://example.com/' . \str_repeat( 'a', 320 );

		$events = array();
		\add_action(
			'atmosphere_long_form_strategy_downgraded',
			function ( $downgrade_post, $requested, $effective ) use ( &$events ) {
				$events[] = array( $downgrade_post->ID, $requested, $effective );
			},
			10,
			3
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter( 'post_link', $permalink_filter );

		try {
			$records = ( new Post( $post ) )->build_long_form_records();
		} finally {
			\remove_filter( 'post_link', $permalink_filter );
		}

		$this->assertCount( 1, $records );
		$this->assertCount( 1, $events );
		$this->assertSame( array( $post->ID, 'teaser-thread', 'link-card' ), $events[0] );
	}

	/**
	 * Downstream filters can swap the default 3-entry shape for any 2..5
	 * string array; the link-card embed still attaches to whatever entry
	 * is last.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_filter_replaces_text_keeping_terminal_embed() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body content.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter(
			'atmosphere_teaser_thread_posts',
			fn() => array( 'Hook post', 'Key takeaway', 'Call to action link' )
		);

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 3, $records );
		$this->assertSame( 'Hook post', $records[0]['text'] );
		$this->assertSame( 'Key takeaway', $records[1]['text'] );
		$this->assertSame( 'Call to action link', $records[2]['text'] );

		$this->assertArrayNotHasKey( 'embed', $records[0] );
		$this->assertArrayNotHasKey( 'embed', $records[1] );
		$this->assertArrayHasKey( 'embed', $records[2] );
		$this->assertSame( 'app.bsky.embed.external', $records[2]['embed']['$type'] );
	}

	/**
	 * Filter that returns fewer than 2 entries should trigger
	 * _doing_it_wrong and fall back to the default hook + body chunk + CTA
	 * shape — a 1-entry return would silently route to publish_single()
	 * and drop the CTA.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_filter_under_two_falls_back() {
		$this->setExpectedIncorrectUsage( 'atmosphere_teaser_thread_posts' );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body content with enough prose to form a hook.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter( 'atmosphere_teaser_thread_posts', fn() => array( 'Just one entry' ) );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 3, $records );
		$this->assertNotSame( 'Just one entry', $records[0]['text'] );
		$this->assertMatchesRegularExpression( '~^Continue reading: ~', $records[2]['text'] );
	}

	/**
	 * Body-path hook: body chunk continues from where the hook cut off
	 * — the hook and the chunk are non-overlapping windows over the same
	 * plain-text body.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_body_chunk_continues_after_hook_cut() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				// 35 sentences × 10 chars = 350 chars; first 28 land in the hook.
				'post_content' => \str_repeat( 'Hi there. ', 35 ),
				'post_excerpt' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 3, $records );

		$hook  = $records[0]['text'];
		$chunk = $records[1]['text'];

		// The plain text is "Hi there. " repeated; sentence-cut at byte 279
		// produces a 279-char hook ("Hi there. " * 27 + "Hi there.") and a
		// chunk continuing with the remaining 7 sentences.
		$this->assertSame( 279, \mb_strlen( $hook ) );
		$this->assertNotEmpty( \trim( $chunk ) );
		$this->assertNotSame( $hook, $chunk, 'Body chunk must not duplicate the hook text.' );

		// Reconstructing hook + chunk in order should yield a prefix of the
		// underlying plain body — proving non-overlap.
		$reconstructed = \rtrim( $hook ) . ' ' . \ltrim( $chunk );
		$this->assertStringStartsWith( $reconstructed, \str_repeat( 'Hi there. ', 35 ) . ' ' );
	}

	/**
	 * Excerpt-path hook: body chunk comes from the start of the body, not
	 * from where the excerpt would have ended in the body. Curated
	 * excerpts are not sliding windows over the body.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_excerpt_hook_chunk_starts_from_body() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'First body sentence. Second body sentence. Third body sentence.',
				'post_excerpt' => 'A curated standalone teaser.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 3, $records );
		$this->assertSame( 'A curated standalone teaser.', $records[0]['text'] );

		// Body chunk begins with the first body sentence — not a slice that
		// skipped past the excerpt's char-count.
		$this->assertStringStartsWith( 'First body sentence.', $records[1]['text'] );
	}

	/**
	 * Short post with no excerpt: the 2-entry `[ body, default CTA ]`
	 * fallback collapses to a single record with the body as text and a
	 * link-card embed. The CTA reply is dropped because it's redundant
	 * — there's nothing past the hook to "continue reading" to. The
	 * link-back is preserved via the embed card on the same record.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_short_body_collapses_to_single_record() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'A single short sentence.',
				'post_excerpt' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 1, $records );
		$this->assertSame( 'A single short sentence.', $records[0]['text'] );

		// Link-back lives on the embed of the same record now, not on a
		// separate CTA reply.
		$this->assertArrayHasKey( 'embed', $records[0] );
		$this->assertSame( 'app.bsky.embed.external', $records[0]['embed']['$type'] );
		$this->assertSame( \get_permalink( $post ), $records[0]['embed']['external']['uri'] );
	}

	/**
	 * An emoji-only body within the 280-grapheme hook budget — but well over
	 * it in code points — still collapses to a single record. The hook is the
	 * whole body, so the redundancy gate, which measures in graphemes, fires
	 * just as it does for an equivalent ASCII body.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_emoji_body_collapses_to_single_record() {
		if ( ! \function_exists( 'grapheme_strlen' ) ) {
			$this->markTestSkipped( 'intl extension required for grapheme counting.' );
		}

		// 200 family emoji: 200 graphemes (under the 280 hook budget) but
		// 1,000 code points (over it). Counting code points here would read
		// the hook as a truncated prefix and ship a needless 2-entry thread.
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => \str_repeat( $family, 200 ),
				'post_excerpt' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 1, $records );
		$this->assertSame( 200, \grapheme_strlen( $records[0]['text'] ) );
		$this->assertSame( 'app.bsky.embed.external', $records[0]['embed']['$type'] );
	}

	/**
	 * Body slightly over the 280-char hook budget with a tail too
	 * short to form a body chunk: the 2-entry `[ truncated-hook, CTA ]`
	 * default stays — the hook is NOT the whole body (the trailing
	 * chars never made it in), so the CTA reply still adds value as
	 * the only in-text affordance to "there's more in the source." The
	 * collapse predicate must NOT fire here even though the default is
	 * 2-entry and there's no excerpt.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_truncated_hook_short_tail_does_not_collapse() {
		// 285-char body: hook truncates to ~280 (sentence-bounded),
		// chunk_source is the leftover ~5 chars, < 10 floor → 2-entry
		// fallback `[ truncated-hook, CTA ]`. Hook is NOT the entire
		// body, so the predicate must short-circuit on body length.
		$body = \str_repeat( 'word ', 56 ) . '. tail';

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => $body,
				'post_excerpt' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		// 2-entry shape preserved; collapse did not fire.
		$this->assertCount( 2, $records );
		$this->assertMatchesRegularExpression( '~^Continue reading: ~', $records[1]['text'] );
		$this->assertArrayNotHasKey( 'embed', $records[0] );
		$this->assertArrayHasKey( 'embed', $records[1] );
	}

	/**
	 * Excerpt-as-hook with a body too short to compose a chunk: the
	 * 2-entry `[ excerpt, CTA ]` fallback stays — the excerpt and the
	 * body are separate strings, so the CTA still carries the
	 * link-back to where the body lives. Only collapse when the hook
	 * IS the body.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_excerpt_with_short_body_stays_two_entries() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				// 3-char body so chunk_source < 10 char floor → 2-entry fallback.
				'post_content' => 'Hi.',
				'post_excerpt' => 'A curated excerpt of decent length.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 2, $records );
		$this->assertSame( 'A curated excerpt of decent length.', $records[0]['text'] );
		$this->assertMatchesRegularExpression( '~^Continue reading: ~', $records[1]['text'] );
		$this->assertArrayNotHasKey( 'embed', $records[0] );
		$this->assertArrayHasKey( 'embed', $records[1] );
	}

	/**
	 * Collapse decision is made on the unfiltered default — when the
	 * default would be the redundant `[ body, default CTA ]` shape, the
	 * `atmosphere_teaser_thread_posts` filter is never reached and the
	 * output is always a single record. A filter that wants to ship a
	 * 2-entry custom thread can only do so when the post has enough
	 * body (or an excerpt) to produce a non-redundant default shape;
	 * otherwise the collapse pre-empts the filter.
	 *
	 * This pins the design choice: the filter operates on the
	 * un-collapsed default shape only.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_short_body_collapse_pre_empts_filter() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'A single short sentence.',
				'post_excerpt' => '',
			)
		);

		$filter_ran = false;
		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter(
			'atmosphere_teaser_thread_posts',
			static function () use ( &$filter_ran ) {
				$filter_ran = true;
				return array( 'Custom hook', 'Custom second post' );
			}
		);

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 1, $records );
		$this->assertFalse( $filter_ran, 'Filter should not run when collapse fires on the default.' );
	}

	/**
	 * Filter override DOES run when the post has a non-redundant
	 * default (here, a usable excerpt forces the 3-entry shape) — the
	 * filter can then return any 2..5 entries and that ships verbatim.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_filter_runs_when_default_is_not_redundant() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				// Excerpt becomes the hook → default is 3-entry
				// (not the redundant 2-entry shape) → no collapse
				// → filter runs.
				'post_content' => 'Body content with enough prose to compose a hook from.',
				'post_excerpt' => 'Curated excerpt.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter(
			'atmosphere_teaser_thread_posts',
			fn() => array( 'Custom hook', 'Custom second post' )
		);

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 2, $records );
		$this->assertSame( 'Custom hook', $records[0]['text'] );
		$this->assertSame( 'Custom second post', $records[1]['text'] );
	}

	/**
	 * Backward-compat: when the post already has 2+ stored bsky records
	 * (passed via the `$stored_count` hint), the collapse is skipped so
	 * `Publisher::update_post` can take the in-place update path
	 * instead of falling through to a destructive `rewrite_thread()`
	 * that would re-mint the root URI and orphan external engagement.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_short_body_does_not_collapse_when_stored_count_two() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'A single short sentence.',
				'post_excerpt' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records( 2 );

		// Old shape preserved: hook + CTA, embed on terminal.
		$this->assertCount( 2, $records );
		$this->assertSame( 'A single short sentence.', $records[0]['text'] );
		$this->assertMatchesRegularExpression( '~^Continue reading: ~', $records[1]['text'] );
		$this->assertArrayNotHasKey( 'embed', $records[0] );
		$this->assertArrayHasKey( 'embed', $records[1] );
	}

	/**
	 * The terminal CTA record carries an `app.bsky.embed.external` link
	 * card pointing at the WP permalink, with the post title as `title`
	 * and the excerpt as `description`. Locks in the embed default so a
	 * future refactor that drops it surfaces immediately.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_cta_record_carries_link_card_embed() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Distinct Post Title',
				'post_content' => 'Body content with enough prose to compose a hook from.',
				'post_excerpt' => 'Distinct curated excerpt.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records  = ( new Post( $post ) )->build_long_form_records();
		$terminal = $records[ \count( $records ) - 1 ];

		$this->assertArrayHasKey( 'embed', $terminal );
		$this->assertSame( 'app.bsky.embed.external', $terminal['embed']['$type'] );

		$external = $terminal['embed']['external'];
		$this->assertSame( \get_permalink( $post ), $external['uri'] );
		$this->assertSame( 'Distinct Post Title', $external['title'] );
		$this->assertSame( 'Distinct curated excerpt.', $external['description'] );
	}

	/**
	 * The hook (root) record has no `embed` field — the link card lives
	 * only on the terminal CTA reply, where it's a useful affordance.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_root_record_has_no_embed() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body content with enough prose to compose a hook from.',
				'post_excerpt' => 'Curated excerpt.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertArrayNotHasKey( 'embed', $records[0] );
	}

	/**
	 * Filter override that returns 2 entries reduces the thread to 2
	 * records; the terminal entry still gets the link-card embed because
	 * the embed attaches to "last entry," not "index 2."
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_filter_two_entries_terminal_has_embed() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body content with enough prose to compose a hook from.',
				'post_excerpt' => 'Curated excerpt.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter(
			'atmosphere_teaser_thread_posts',
			fn() => array( 'Custom hook', 'Custom CTA' )
		);

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 2, $records );
		$this->assertArrayNotHasKey( 'embed', $records[0] );
		$this->assertArrayHasKey( 'embed', $records[1] );
		$this->assertSame( 'app.bsky.embed.external', $records[1]['embed']['$type'] );
	}

	/**
	 * Hard-cap multibyte path: a body of unbroken multibyte runs (no
	 * spaces, no sentence punctuation) forces `truncate_to_budget` into
	 * the hard-cap branch where the hook ends in `…`. The body chunk
	 * must continue from the next plain-text codepoint, not corrupt the
	 * trailing multibyte char of the hook (which `rtrim($hook, '…')`
	 * would do — this test pins the `mb_substr` safety the PR added).
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_hook_hard_cap_multibyte_chunk_offset() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				// 100 × `日本語` = 300 codepoints, no whitespace or sentence
				// punctuation, forcing the hook into the hard-cap path.
				'post_content' => \str_repeat( '日本語', 100 ),
				'post_excerpt' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 3, $records );

		$hook  = $records[0]['text'];
		$chunk = $records[1]['text'];

		$this->assertSame( '…', \mb_substr( $hook, -1 ), 'Hard-cap hook should end with the ellipsis marker.' );
		$this->assertSame( 280, \mb_strlen( $hook ) );

		// First codepoint of the chunk should be the next codepoint of
		// the original prose — no UTF-8 corruption from a byte-level
		// rtrim, no overlap with the hook's last consumed codepoint.
		$consumed = \mb_substr( $hook, 0, 279 );
		$this->assertSame(
			\mb_substr( \str_repeat( '日本語', 100 ), \mb_strlen( $consumed ), 1 ),
			\mb_substr( $chunk, 0, 1 )
		);
	}

	/**
	 * Body chunk falls back to a word boundary when its source has no
	 * sentence punctuation in the first 280 chars. Pins the chunk's
	 * truncation contract — the same sentence-preferred /
	 * word-fallback / hard-cap order as the hook.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_body_chunk_word_cut_fallback() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				// One sentence so the hook lands at the period, then a
				// long stream of 8-char words separated by spaces but
				// with no further punctuation — forces the chunk into
				// the word-boundary fallback branch.
				'post_content' => 'First sentence. ' . \str_repeat( 'abcdefgh ijklmnop ', 36 ),
				'post_excerpt' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 3, $records );

		$chunk = $records[1]['text'];

		$this->assertLessThanOrEqual( 280, \mb_strlen( $chunk ) );
		$this->assertDoesNotMatchRegularExpression(
			'~\s\S{1,7}$~',
			$chunk,
			'Word-cut chunk should end at a complete word, not mid-word.'
		);
		// No sentence punctuation in the chunk source means the chunk
		// itself should not contain `.`/`!`/`?` either.
		$this->assertDoesNotMatchRegularExpression( '~[.!?]~', $chunk );
	}

	/**
	 * Filter return is silently capped at 5 entries to bound the
	 * compensating-delete blast radius on a mid-thread publish failure.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_filter_caps_at_five_entries() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body content with enough prose to compose a hook from.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter(
			'atmosphere_teaser_thread_posts',
			fn() => array( 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven' )
		);

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 5, $records );
		$this->assertSame( 'Five', $records[4]['text'] );
		$this->assertArrayHasKey( 'embed', $records[4], 'Embed still attaches to the last entry after the cap.' );
	}

	/**
	 * Filter that returns a non-array value triggers `_doing_it_wrong`
	 * and falls back to the default — same treatment as the < 2 valid
	 * entries case, so filter authors get visibility into both misuse
	 * shapes.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_filter_non_array_falls_back() {
		$this->setExpectedIncorrectUsage( 'atmosphere_teaser_thread_posts' );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body content with enough prose to compose a hook from.',
				'post_excerpt' => 'Curated excerpt.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter( 'atmosphere_teaser_thread_posts', fn() => null );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertGreaterThanOrEqual( 2, \count( $records ) );
		$this->assertMatchesRegularExpression(
			'~^Continue reading: ~',
			$records[ \count( $records ) - 1 ]['text']
		);
	}

	/**
	 * Filter that returns only whitespace-equivalent entries (NBSP,
	 * ideographic space) is treated as < 2 valid entries after
	 * sanitisation. Locks in the Unicode-whitespace behavior of
	 * `sanitize_text` — without `/u` on its whitespace regex these
	 * would survive trim and ship as fake records.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_filter_whitespace_only_entries_fall_back() {
		$this->setExpectedIncorrectUsage( 'atmosphere_teaser_thread_posts' );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body content with enough prose to compose a hook from.',
				'post_excerpt' => 'Curated excerpt.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );
		\add_filter(
			'atmosphere_teaser_thread_posts',
			fn() => array( "\xC2\xA0\xC2\xA0", "\xE3\x80\x80\xE3\x80\x80" )
		);

		$records = ( new Post( $post ) )->build_long_form_records();

		// Default (excerpt + body) should resurface; the CTA stays terminal.
		$this->assertGreaterThanOrEqual( 2, \count( $records ) );
		$this->assertMatchesRegularExpression(
			'~^Continue reading: ~',
			$records[ \count( $records ) - 1 ]['text']
		);
	}

	/**
	 * Every record in a thread carries the same `langs` array.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_langs_consistent_across_thread() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body content with enough prose to form a hook.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertGreaterThanOrEqual( 2, \count( $records ) );
		$root_langs = $records[0]['langs'];
		$this->assertNotEmpty( $root_langs );
		foreach ( $records as $record ) {
			$this->assertSame( $root_langs, $record['langs'] );
		}
	}

	/**
	 * Facets are extracted against each record's own text — tag on the hook,
	 * link on the CTA.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_facets_extracted_per_entry() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				// Body long enough that the default is 3-entry (hook +
				// body chunk + CTA), so the redundant-2-entry collapse
				// in build_long_form_records() does not fire and the
				// CTA record exists for the link-facet assertion below.
				'post_content' => 'Read about #testing sensors in this detailed write-up on instrumentation. ' . \str_repeat( 'Additional analysis follows here. ', 12 ),
				// Force body-path hook; factory auto-fills "Post excerpt NNN" otherwise.
				'post_excerpt' => '',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$hook_has_tag = false;
		foreach ( $records[0]['facets'] ?? array() as $facet ) {
			foreach ( $facet['features'] as $feature ) {
				if ( 'app.bsky.richtext.facet#tag' === ( $feature['$type'] ?? '' )
					&& 'testing' === ( $feature['tag'] ?? '' )
				) {
					$hook_has_tag = true;
				}
			}
		}
		$this->assertTrue( $hook_has_tag, 'Hook text should have a #testing tag facet.' );

		// CTA is the terminal record, not necessarily index 1 — the
		// thread can be 2 or 3 entries depending on body length.
		$terminal     = $records[ \count( $records ) - 1 ];
		$cta_has_link = false;
		foreach ( $terminal['facets'] ?? array() as $facet ) {
			foreach ( $facet['features'] as $feature ) {
				if ( 'app.bsky.richtext.facet#link' === ( $feature['$type'] ?? '' ) ) {
					$cta_has_link = true;
				}
			}
		}
		$this->assertTrue( $cta_has_link, 'CTA text should have a link facet.' );
	}

	/**
	 * An unknown strategy value silently falls back to link-card.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_unknown_strategy_falls_back_to_link_card() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'nonsense' );

		$transformer = new Post( $post );
		$records     = $transformer->build_long_form_records();

		\remove_all_filters( 'atmosphere_long_form_composition' );
		$oracle = $transformer->transform();

		$this->assertCount( 1, $records );
		$this->assertSame( $oracle['text'], $records[0]['text'] );
		$this->assertSame( $oracle['embed'], $records[0]['embed'] );
	}

	/*
	 * -----------------------------------------------------------------
	 * atmosphere_post_embed filter — embed swap seam.
	 * -----------------------------------------------------------------
	 */

	/**
	 * The filter fires for a long-form post and the default external
	 * card is passed in as the default value.
	 *
	 * @covers ::transform
	 */
	public function test_post_embed_filter_receives_default_external_card_for_long_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Long-form body.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		$seen_default  = 'not-called';
		$seen_strategy = null;
		\add_filter(
			'atmosphere_post_embed',
			static function ( $embed, $filter_post, $strategy ) use ( &$seen_default, &$seen_strategy ) {
				$seen_default  = $embed;
				$seen_strategy = $strategy;
				return $embed;
			},
			10,
			3
		);

		( new Post( $post ) )->transform();

		$this->assertIsArray( $seen_default );
		$this->assertSame( 'app.bsky.embed.external', $seen_default['$type'] );
		$this->assertSame( 'link-card', $seen_strategy );
	}

	/**
	 * The filter fires for a short-form post with `null` as the default
	 * — short-form has no embed by default, but the seam is still open.
	 *
	 * @covers ::transform
	 */
	public function test_post_embed_filter_receives_null_default_for_short_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'Untitled body.',
			)
		);

		$seen_default  = 'not-called';
		$seen_strategy = null;
		\add_filter(
			'atmosphere_post_embed',
			static function ( $embed, $filter_post, $strategy ) use ( &$seen_default, &$seen_strategy ) {
				$seen_default  = $embed;
				$seen_strategy = $strategy;
				return $embed;
			},
			10,
			3
		);

		( new Post( $post ) )->transform();

		$this->assertNull( $seen_default );
		$this->assertSame( 'short-form', $seen_strategy );
	}

	/**
	 * Filter return replaces the embed assigned to the record.
	 *
	 * @covers ::transform
	 */
	public function test_post_embed_filter_can_replace_embed() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body.',
				'post_excerpt' => 'Excerpt.',
			)
		);

		$replacement = array(
			'$type'  => 'app.bsky.embed.images',
			'images' => array(
				array(
					'image' => array( 'fake' => 'blob' ),
					'alt'   => '',
				),
			),
		);

		\add_filter(
			'atmosphere_post_embed',
			static fn() => $replacement
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( $replacement, $record['embed'] );
	}

	/**
	 * Filter can attach an embed to a short-form post that would otherwise
	 * ship with none — the new seam beyond what
	 * `atmosphere_transform_bsky_post` could reach without rewriting the
	 * record.
	 *
	 * @covers ::transform
	 */
	public function test_post_embed_filter_can_attach_embed_to_short_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'Untitled body.',
			)
		);

		$attached = array(
			'$type'  => 'app.bsky.embed.images',
			'images' => array(
				array(
					'image' => array( 'fake' => 'blob' ),
					'alt'   => '',
				),
			),
		);

		\add_filter(
			'atmosphere_post_embed',
			static fn() => $attached
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( $attached, $record['embed'] );
	}

	/**
	 * Filter returning null suppresses the default external card.
	 *
	 * @covers ::transform
	 */
	public function test_post_embed_filter_returning_null_suppresses_embed() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body.',
				'post_excerpt' => 'Excerpt.',
			)
		);

		\add_filter( 'atmosphere_post_embed', '__return_null' );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * Non-array, non-null filter return is rejected and the pre-filter
	 * embed is preserved.
	 *
	 * @covers ::transform
	 */
	public function test_post_embed_filter_rejects_non_array_non_null() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\Transformer\Post::apply_post_embed_filter' );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body.',
				'post_excerpt' => 'Excerpt.',
			)
		);

		\add_filter( 'atmosphere_post_embed', static fn() => 'not-an-array' );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
	}

	/**
	 * Redacted (password-protected) transforms must not expose the post
	 * object to the embed filter, mirroring the short-form and record
	 * filters' redaction posture.
	 *
	 * @covers ::transform
	 */
	public function test_post_embed_filter_does_not_fire_on_redacted_transform() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'CONFIDENTIAL-TITLE',
				'post_content'  => 'CONFIDENTIAL-BODY',
				'post_password' => 'secret',
			)
		);

		$called = false;
		\add_filter(
			'atmosphere_post_embed',
			static function ( $embed ) use ( &$called ) {
				$called = true;
				return $embed;
			}
		);

		( new Post( $post ) )->transform();

		$this->assertFalse( $called, 'Redacted transforms must not expose the post object to embed filters.' );
	}

	/**
	 * The filter fires for the link-card record in
	 * `build_long_form_records()` with the 'link-card' strategy.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_post_embed_filter_fires_for_link_card_long_form_record() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body.',
				'post_excerpt' => 'Excerpt.',
			)
		);

		$seen_strategy = null;
		\add_filter(
			'atmosphere_post_embed',
			static function ( $embed, $filter_post, $strategy ) use ( &$seen_strategy ) {
				$seen_strategy = $strategy;
				return $embed;
			},
			10,
			3
		);

		( new Post( $post ) )->build_long_form_records();

		$this->assertSame( 'link-card', $seen_strategy );
	}

	/**
	 * The filter fires for the terminal CTA entry of a teaser-thread
	 * with the 'teaser-thread' strategy, and the filter return is what
	 * lands on the CTA record.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_post_embed_filter_fires_for_teaser_thread_terminal_record() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body sentence one. Body sentence two.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$replacement = array(
			'$type'  => 'app.bsky.embed.images',
			'images' => array(
				array(
					'image' => array( 'fake' => 'blob' ),
					'alt'   => '',
				),
			),
		);

		$seen_strategy = null;
		\add_filter(
			'atmosphere_post_embed',
			static function ( $embed, $filter_post, $strategy ) use ( &$seen_strategy, $replacement ) {
				$seen_strategy = $strategy;
				return $replacement;
			},
			10,
			3
		);

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertSame( 'teaser-thread', $seen_strategy );
		// Default thread shape is hook + body chunk + CTA; embed is on the terminal entry.
		$this->assertCount( 3, $records );
		$this->assertArrayNotHasKey( 'embed', $records[0] );
		$this->assertArrayNotHasKey( 'embed', $records[1] );
		$this->assertSame( $replacement, $records[2]['embed'] );
	}

	/*
	 * -----------------------------------------------------------------
	 * upload_thumbnail() backward-compat alias for upload_image_blob().
	 * -----------------------------------------------------------------
	 */

	/**
	 * The deprecated-name `upload_thumbnail()` and the new
	 * `upload_image_blob()` are the same code path — both must hit the
	 * cached postmeta short-circuit identically so existing callers
	 * (Publication, Document) keep working unchanged.
	 *
	 * @covers ::upload_thumbnail
	 * @covers ::upload_image_blob
	 */
	public function test_upload_thumbnail_aliases_upload_image_blob() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'fake.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array(
				'post_title' => 'Fake attachment',
			)
		);

		$cached_ref = array(
			'cid'      => 'bafyfake',
			'mimeType' => 'image/jpeg',
			'size'     => 123,
		);
		\update_post_meta( $attachment_id, '_atmosphere_blob_ref', $cached_ref );

		$this->assertSame( $cached_ref, Post::upload_image_blob( $attachment_id ) );
		$this->assertSame( $cached_ref, Post::upload_thumbnail( $attachment_id ) );
	}

	/**
	 * On offloaded-media hosts (WordPress.com / Atomic), intermediate
	 * image sizes are virtual — their files never land on local disk —
	 * so the local path is unreadable. `upload_image_blob()` must fall
	 * back to fetching the image over HTTP from its attachment URL and
	 * upload those bytes, rather than silently returning null. Regression
	 * test for the dropped document `coverImage` / Bluesky card thumbnail.
	 *
	 * @covers ::upload_image_blob
	 */
	public function test_upload_image_blob_fetches_remote_when_local_file_unreadable() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/06/offloaded.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Offloaded attachment' )
		);
		\update_post_meta( $attachment_id, '_wp_attached_file', '2026/06/offloaded.jpg' );
		\wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => '2026/06/offloaded.jpg',
				'width'  => 3000,
				'height' => 4000,
				'sizes'  => array(
					'large' => array(
						'file'      => 'offloaded-769x1024.jpg',
						'width'     => 769,
						'height'    => 1024,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);

		$image_bytes = $this->image_bytes( 'jpeg' );

		// Serve image bytes for the attachment URL fetch. No local file
		// exists, so this is the only way a blob can be produced.
		$serve_image = static function ( $preempt, $args, $url ) use ( $image_bytes ) {
			if ( false !== \strpos( $url, 'offloaded' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(
						array( 'content-type' => 'image/jpeg' )
					),
					'body'     => $image_bytes,
				);
			}
			return $preempt;
		};
		\add_filter( 'pre_http_request', $serve_image, 5, 3 );

		// Capture the bytes handed to the PDS and short-circuit the
		// DPoP-authenticated upload that can't run in the test harness.
		$uploaded     = null;
		$capture_blob = static function ( $short_circuit, $file_path, $mime ) use ( &$uploaded ) {
			$uploaded = array(
				'path'     => $file_path,
				'contents' => \file_get_contents( $file_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'mime'     => $mime,
			);
			return array(
				'blob' => array(
					'cid'      => 'bafyremote',
					'mimeType' => 'image/jpeg',
					'size'     => \strlen( $uploaded['contents'] ),
				),
			);
		};
		\add_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10, 3 );

		$blob = Post::upload_image_blob( $attachment_id );

		\remove_filter( 'pre_http_request', $serve_image, 5 );
		\remove_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10 );

		$this->assertIsArray( $blob, 'A blob ref should be produced from the fetched image.' );
		$this->assertSame( 'bafyremote', $blob['cid'] );
		$this->assertNotNull( $uploaded, 'The fetched bytes should have been uploaded.' );
		$this->assertSame( $image_bytes, $uploaded['contents'] );
		$this->assertSame( 'image/jpeg', $uploaded['mime'] );
		$this->assertFileDoesNotExist( $uploaded['path'], 'The fetched temp file must be deleted after a successful upload.' );
	}

	/**
	 * When the CDN transcodes an attachment to another image format, the
	 * blob upload must use the actual fetched format rather than the
	 * attachment's original MIME. Otherwise the PDS stores WebP/AVIF bytes
	 * behind a misleading `image/jpeg` blob.
	 *
	 * @covers ::upload_image_blob
	 */
	public function test_upload_image_blob_uses_remote_response_mime_type() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/06/transcoded.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Transcoded attachment' )
		);
		\update_post_meta( $attachment_id, '_wp_attached_file', '2026/06/transcoded.jpg' );

		$webp        = $this->image_bytes( 'webp' );
		$serve_image = static function ( $preempt, $args, $url ) use ( $webp ) {
			if ( false !== \strpos( $url, 'transcoded' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(
						array( 'content-type' => 'Image/WebP; charset=binary' )
					),
					'body'     => $webp,
				);
			}
			return $preempt;
		};
		\add_filter( 'pre_http_request', $serve_image, 5, 3 );

		$uploaded_mime = null;
		$capture_blob  = static function ( $short_circuit, $file_path, $mime ) use ( &$uploaded_mime ) {
			$uploaded_mime = $mime;
			return array( 'blob' => array( 'cid' => 'bafywebp' ) );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10, 3 );

		$blob = Post::upload_image_blob( $attachment_id );

		\remove_filter( 'pre_http_request', $serve_image, 5 );
		\remove_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10 );

		$this->assertSame( 'bafywebp', $blob['cid'] );
		$this->assertSame( 'image/webp', $uploaded_mime );
	}

	/**
	 * Attachment metadata can contain generated image sizes beyond
	 * WordPress core's fixed names. If the larger candidates are over the
	 * blob cap, `upload_image_blob()` should still find a custom generated
	 * size under the cap instead of giving up.
	 *
	 * @covers ::upload_image_blob
	 */
	public function test_upload_image_blob_uses_custom_local_size_under_cap() {
		$upload_dir = \wp_upload_dir();
		$original   = $upload_dir['basedir'] . '/atmosphere-original-over-cap.jpg';
		$large      = $upload_dir['basedir'] . '/atmosphere-large-over-cap.jpg';
		$custom     = $upload_dir['basedir'] . '/atmosphere-custom-under-cap.jpg';

		\file_put_contents( $original, \str_repeat( 'o', 1_000_001 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		\file_put_contents( $large, \str_repeat( 'l', 1_000_001 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		\file_put_contents( $custom, 'CUSTOM-IMAGE-BYTES' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$attachment_id = self::factory()->attachment->create_object(
			$original,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Custom size attachment',
			)
		);
		\wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => \basename( $original ),
				'width'  => 3000,
				'height' => 2200,
				'sizes'  => array(
					'large'       => array(
						'file'      => \basename( $large ),
						'width'     => 1600,
						'height'    => 1200,
						'mime-type' => 'image/jpeg',
					),
					'custom-card' => array(
						'file'      => \basename( $custom ),
						'width'     => 1200,
						'height'    => 800,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);

		$uploaded     = null;
		$capture_blob = static function ( $short_circuit, $file_path ) use ( &$uploaded ) {
			$uploaded = \file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return array( 'blob' => array( 'cid' => 'bafycustom' ) );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10, 2 );

		$blob = Post::upload_image_blob( $attachment_id );

		\remove_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10 );
		\wp_delete_file( $original );
		\wp_delete_file( $large );
		\wp_delete_file( $custom );

		$this->assertSame( 'bafycustom', $blob['cid'] );
		$this->assertSame( 'CUSTOM-IMAGE-BYTES', $uploaded );
	}

	/**
	 * When a plugin transcodes a generated sub-size to another format
	 * (e.g. the Modern Image Formats / WebP Uploads plugin emits an
	 * `image/webp` `large` from a JPEG original), the blob upload must use
	 * the sub-size's recorded MIME — not the attachment's original MIME —
	 * so the PDS doesn't store WebP bytes behind a misleading `image/jpeg`
	 * blob. Mirrors the remote-transcode case for the local fast path.
	 *
	 * @covers ::upload_image_blob
	 */
	public function test_upload_image_blob_uses_local_size_mime_type() {
		$upload_dir = \wp_upload_dir();
		$original   = $upload_dir['basedir'] . '/atmosphere-original-jpeg-over-cap.jpg';
		$webp       = $upload_dir['basedir'] . '/atmosphere-large-under-cap.webp';

		// Original is over the cap so resolution falls through to the
		// transcoded sub-size below.
		\file_put_contents( $original, \str_repeat( 'o', 1_000_001 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		\file_put_contents( $webp, 'WEBP-SUBSIZE-BYTES' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$attachment_id = self::factory()->attachment->create_object(
			$original,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Transcoded local sub-size attachment',
			)
		);
		\wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => \basename( $original ),
				'width'  => 3000,
				'height' => 2200,
				'sizes'  => array(
					'large' => array(
						'file'      => \basename( $webp ),
						'width'     => 1024,
						'height'    => 751,
						'mime-type' => 'image/webp',
					),
				),
			)
		);

		$uploaded_mime = null;
		$capture_blob  = static function ( $short_circuit, $file_path, $mime ) use ( &$uploaded_mime ) {
			$uploaded_mime = $mime;
			return array( 'blob' => array( 'cid' => 'bafylocalwebp' ) );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10, 3 );

		$blob = Post::upload_image_blob( $attachment_id );

		\remove_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10 );
		\wp_delete_file( $original );
		\wp_delete_file( $webp );

		$this->assertSame( 'bafylocalwebp', $blob['cid'] );
		$this->assertSame( 'image/webp', $uploaded_mime );
	}

	/**
	 * A readable local file under the 1 MB cap is uploaded directly,
	 * without any HTTP fetch — the offloaded-media fallback must not
	 * regress the common self-hosted fast path.
	 *
	 * @covers ::upload_image_blob
	 */
	public function test_upload_image_blob_uses_local_file_without_fetching() {
		$upload_dir = \wp_upload_dir();
		$path       = $upload_dir['basedir'] . '/atmosphere-local-test.jpg';
		\file_put_contents( $path, 'LOCAL-IMAGE-BYTES' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$attachment_id = self::factory()->attachment->create_object(
			$path,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Local attachment',
			)
		);

		// Any HTTP request would be a regression — fail loudly if one fires.
		$fetched  = false;
		$tripwire = static function ( $preempt ) use ( &$fetched ) {
			$fetched = true;
			return $preempt;
		};
		\add_filter( 'pre_http_request', $tripwire, 1, 1 );

		$uploaded     = null;
		$capture_blob = static function ( $short_circuit, $file_path ) use ( &$uploaded ) {
			$uploaded = \file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return array( 'blob' => array( 'cid' => 'bafylocal' ) );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10, 2 );

		$blob = Post::upload_image_blob( $attachment_id );

		\remove_filter( 'pre_http_request', $tripwire, 1 );
		\remove_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10 );
		\wp_delete_file( $path );

		$this->assertSame( 'bafylocal', $blob['cid'] );
		$this->assertSame( 'LOCAL-IMAGE-BYTES', $uploaded );
		$this->assertFalse( $fetched, 'No HTTP request should be made when a local file is available.' );
	}

	/**
	 * When no readable local file exists and every fetched candidate is
	 * still over the 1 MB cap, `upload_image_blob()` gives up and returns
	 * null rather than uploading an oversized blob. (Per design: no local
	 * downscaling as a last resort.)
	 *
	 * @covers ::upload_image_blob
	 */
	public function test_upload_image_blob_returns_null_when_all_candidates_over_cap() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/06/huge.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Huge attachment' )
		);
		\update_post_meta( $attachment_id, '_wp_attached_file', '2026/06/huge.jpg' );

		// Every fetched candidate comes back over the 1 MB cap.
		$oversized  = \str_repeat( 'x', 1_000_001 );
		$serve_huge = static function ( $preempt, $args, $url ) use ( $oversized ) {
			if ( false !== \strpos( $url, 'huge' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(
						array( 'content-type' => 'image/jpeg' )
					),
					'body'     => $oversized,
				);
			}
			return $preempt;
		};
		\add_filter( 'pre_http_request', $serve_huge, 5, 3 );

		$uploaded     = false;
		$capture_blob = static function () use ( &$uploaded ) {
			$uploaded = true;
			return array( 'blob' => array( 'cid' => 'bafyshouldnothappen' ) );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10, 0 );

		$blob = Post::upload_image_blob( $attachment_id );

		\remove_filter( 'pre_http_request', $serve_huge, 5 );
		\remove_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10 );

		$this->assertNull( $blob );
		$this->assertFalse( $uploaded, 'No oversized blob should ever be uploaded.' );
	}

	/**
	 * A response that claims `image/*` in its Content-Type but carries
	 * non-image bytes is rejected — the bytes are validated, not just the
	 * header — so a misconfigured/compromised CDN can't get arbitrary
	 * bytes stored as a blob and rendered by downstream clients.
	 *
	 * @covers ::upload_image_blob
	 */
	public function test_upload_image_blob_rejects_non_image_response_bytes() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/06/spoofed.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Spoofed attachment' )
		);
		\update_post_meta( $attachment_id, '_wp_attached_file', '2026/06/spoofed.jpg' );

		// 200 OK, image/jpeg header — but the body is not an image.
		$serve_junk = static function ( $preempt, $args, $url ) {
			if ( false !== \strpos( $url, 'spoofed' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(
						array( 'content-type' => 'image/jpeg' )
					),
					'body'     => 'this-is-definitely-not-an-image',
				);
			}
			return $preempt;
		};
		\add_filter( 'pre_http_request', $serve_junk, 5, 3 );

		$uploaded     = false;
		$capture_blob = static function () use ( &$uploaded ) {
			$uploaded = true;
			return array( 'blob' => array( 'cid' => 'bafyshouldnothappen' ) );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10, 0 );

		$blob = Post::upload_image_blob( $attachment_id );

		\remove_filter( 'pre_http_request', $serve_junk, 5 );
		\remove_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10 );

		$this->assertNull( $blob );
		$this->assertFalse( $uploaded, 'Non-image bytes must never be uploaded as a blob.' );
	}

	/**
	 * A CDN that returns a capitalised content-type (`Image/JPEG`) is
	 * still recognised as an image — the header value is not case-
	 * normalised by WordPress, so the fetch must compare case-insensitively.
	 *
	 * @covers ::upload_image_blob
	 */
	public function test_upload_image_blob_accepts_uppercase_content_type() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/06/caps.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Caps attachment' )
		);
		\update_post_meta( $attachment_id, '_wp_attached_file', '2026/06/caps.jpg' );

		$jpeg        = $this->image_bytes( 'jpeg' );
		$serve_image = static function ( $preempt, $args, $url ) use ( $jpeg ) {
			if ( false !== \strpos( $url, 'caps' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(
						array( 'content-type' => 'Image/JPEG' )
					),
					'body'     => $jpeg,
				);
			}
			return $preempt;
		};
		\add_filter( 'pre_http_request', $serve_image, 5, 3 );

		$capture_blob = static function () {
			return array( 'blob' => array( 'cid' => 'bafycaps' ) );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10, 0 );

		$blob = Post::upload_image_blob( $attachment_id );

		\remove_filter( 'pre_http_request', $serve_image, 5 );
		\remove_filter( 'atmosphere_pre_upload_blob', $capture_blob, 10 );

		$this->assertSame( 'bafycaps', $blob['cid'] );
	}

	/**
	 * When the fetched image uploads to a temp file but the PDS upload
	 * fails, the temp file is still deleted — no leak on the error path.
	 *
	 * @covers ::upload_image_blob
	 */
	public function test_upload_image_blob_cleans_up_temp_file_on_upload_error() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/06/leak.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Leak attachment' )
		);
		\update_post_meta( $attachment_id, '_wp_attached_file', '2026/06/leak.jpg' );

		$jpeg        = $this->image_bytes( 'jpeg' );
		$serve_image = static function ( $preempt, $args, $url ) use ( $jpeg ) {
			if ( false !== \strpos( $url, 'leak' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(
						array( 'content-type' => 'image/jpeg' )
					),
					'body'     => $jpeg,
				);
			}
			return $preempt;
		};
		\add_filter( 'pre_http_request', $serve_image, 5, 3 );

		// Capture the temp path, then fail the upload.
		$temp_path   = null;
		$fail_upload = static function ( $short_circuit, $file_path ) use ( &$temp_path ) {
			$temp_path = $file_path;
			return new \WP_Error( 'atmosphere_test_upload_failed', 'forced failure' );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $fail_upload, 10, 2 );

		$blob = Post::upload_image_blob( $attachment_id );

		\remove_filter( 'pre_http_request', $serve_image, 5 );
		\remove_filter( 'atmosphere_pre_upload_blob', $fail_upload, 10 );

		$this->assertNull( $blob );
		$this->assertNotNull( $temp_path, 'A temp file should have been created and passed to upload.' );
		$this->assertFileDoesNotExist( $temp_path, 'The temp file must be deleted even when the upload fails.' );
	}

	/*
	 * -----------------------------------------------------------------
	 * get_attachment_aspect_ratio() — pixel dimensions for embed.images.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Returns the integer width/height pair for an image attachment
	 * with valid metadata.
	 *
	 * @covers ::get_attachment_aspect_ratio
	 */
	public function test_get_attachment_aspect_ratio_returns_dimensions() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'fake.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array(
				'post_title' => 'Fake attachment',
			)
		);

		\wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 1600,
				'height' => 1200,
			)
		);

		$this->assertSame(
			array(
				'width'  => 1600,
				'height' => 1200,
			),
			Post::get_attachment_aspect_ratio( $attachment_id )
		);
	}

	/**
	 * Returns null when metadata is missing — the typical pre-image-sub-size
	 * state right after upload.
	 *
	 * @covers ::get_attachment_aspect_ratio
	 */
	public function test_get_attachment_aspect_ratio_returns_null_without_metadata() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'fake.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array(
				'post_title' => 'Fake attachment',
			)
		);

		$this->assertNull( Post::get_attachment_aspect_ratio( $attachment_id ) );
	}

	/**
	 * Returns null when either dimension is zero — guards against bogus
	 * metadata from broken image generators.
	 *
	 * @covers ::get_attachment_aspect_ratio
	 */
	public function test_get_attachment_aspect_ratio_returns_null_for_zero_dimensions() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'fake.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array(
				'post_title' => 'Fake attachment',
			)
		);

		\wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 0,
				'height' => 1200,
			)
		);

		$this->assertNull( Post::get_attachment_aspect_ratio( $attachment_id ) );
	}

	/*
	 * -----------------------------------------------------------------
	 * Short-form image embed — auto-extract from post content / featured image.
	 * -----------------------------------------------------------------
	 */

	/**
	 * A short-form post with no in-body images but with a featured image
	 * attaches an `app.bsky.embed.images` record with that single image.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_with_featured_image_attaches_images_embed() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'featured.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array(
				'post_title' => 'Featured attachment',
			)
		);
		\update_post_meta(
			$attachment_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafyfeatured',
				'mimeType' => 'image/jpeg',
				'size'     => 123,
			)
		);
		\update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'A featured image' );
		\wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 1600,
				'height' => 1200,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Aside with featured image',
				'post_content' => 'Just text in the body.',
			)
		);
		\set_post_format( $post_id, 'aside' );
		\set_post_thumbnail( $post_id, $attachment_id );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.images', $record['embed']['$type'] );
		$this->assertCount( 1, $record['embed']['images'] );
		$this->assertSame( 'A featured image', $record['embed']['images'][0]['alt'] );
		$this->assertSame(
			array(
				'cid'      => 'bafyfeatured',
				'mimeType' => 'image/jpeg',
				'size'     => 123,
			),
			$record['embed']['images'][0]['image']
		);
		$this->assertSame(
			array(
				'width'  => 1600,
				'height' => 1200,
			),
			$record['embed']['images'][0]['aspectRatio']
		);
	}

	/**
	 * A short-form post with an in-body `core/image` block uses that
	 * image — not the featured image — in the embed.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_with_inbody_image_block_uses_inbody_image() {
		// Featured image — should be ignored when an in-body image exists.
		$featured_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'featured.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Featured attachment' )
		);
		\update_post_meta(
			$featured_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafyfeatured',
				'mimeType' => 'image/jpeg',
				'size'     => 1,
			)
		);

		// In-body image.
		$inbody_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'inbody.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'In-body attachment' )
		);
		\update_post_meta(
			$inbody_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafyinbody',
				'mimeType' => 'image/jpeg',
				'size'     => 2,
			)
		);
		\update_post_meta( $inbody_id, '_wp_attachment_image_alt', 'An in-body image' );

		$content = '<!-- wp:paragraph --><p>Just an aside.</p><!-- /wp:paragraph -->'
			. "\n\n"
			. '<!-- wp:image {"id":' . $inbody_id . ',"sizeSlug":"large"} -->'
			. '<figure class="wp-block-image size-large"><img src="" alt="" class="wp-image-' . $inbody_id . '"/></figure>'
			. '<!-- /wp:image -->';

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Aside with inline image',
				'post_content' => $content,
			)
		);
		\set_post_format( $post_id, 'aside' );
		\set_post_thumbnail( $post_id, $featured_id );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.images', $record['embed']['$type'] );
		$this->assertCount( 1, $record['embed']['images'] );
		$this->assertSame( 'An in-body image', $record['embed']['images'][0]['alt'] );
		$this->assertSame( 'bafyinbody', $record['embed']['images'][0]['image']['cid'] );
	}

	/**
	 * Up to 4 in-body images attach; duplicates are removed in document
	 * order; the 5th and beyond are dropped.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_with_many_inbody_images_caps_at_four() {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$id = self::factory()->attachment->create_object(
				array(
					'file'           => "img{$i}.jpg",
					'post_mime_type' => 'image/jpeg',
				),
				0,
				array( 'post_title' => "Image {$i}" )
			);
			\update_post_meta(
				$id,
				'_atmosphere_blob_ref',
				array(
					'cid'      => "bafy{$i}",
					'mimeType' => 'image/jpeg',
					'size'     => $i + 1,
				)
			);
			$ids[] = $id;
		}

		// Sequence: id0, id1, id2, id0 (dup), id3, id4 — after dedup we
		// expect [ id0, id1, id2, id3, id4 ], capped to first 4.
		$sequence = array( $ids[0], $ids[1], $ids[2], $ids[0], $ids[3], $ids[4] );
		$content  = '<!-- wp:paragraph --><p>Look at these images.</p><!-- /wp:paragraph -->' . "\n\n";
		foreach ( $sequence as $id ) {
			$content .= '<!-- wp:image {"id":' . $id . '} -->'
				. '<figure class="wp-block-image"><img class="wp-image-' . $id . '"/></figure>'
				. "<!-- /wp:image -->\n";
		}

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Many images aside',
				'post_content' => $content,
			)
		);
		\set_post_format( $post_id, 'aside' );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertCount( 4, $record['embed']['images'] );
		$cids = \array_map( static fn( $img ) => $img['image']['cid'], $record['embed']['images'] );
		$this->assertSame( array( 'bafy0', 'bafy1', 'bafy2', 'bafy3' ), $cids );
	}

	/**
	 * `core/image` blocks nested inside `core/group` (and other container
	 * blocks via `innerBlocks`) are picked up by the recursive walk.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_collects_nested_image_blocks() {
		$inner_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'nested.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Nested attachment' )
		);
		\update_post_meta(
			$inner_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafynested',
				'mimeType' => 'image/jpeg',
				'size'     => 1,
			)
		);

		$content = '<!-- wp:paragraph --><p>Nested image aside.</p><!-- /wp:paragraph -->'
			. "\n\n"
			. '<!-- wp:group -->'
			. '<div class="wp-block-group">'
			. '<!-- wp:image {"id":' . $inner_id . '} -->'
			. '<figure class="wp-block-image"><img class="wp-image-' . $inner_id . '"/></figure>'
			. '<!-- /wp:image -->'
			. '</div>'
			. '<!-- /wp:group -->';

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Group with nested image',
				'post_content' => $content,
			)
		);
		\set_post_format( $post_id, 'aside' );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'bafynested', $record['embed']['images'][0]['image']['cid'] );
	}

	/**
	 * `atmosphere_post_embed` returning null suppresses the new default
	 * image embed on short-form posts — preserves the existing override
	 * contract.
	 *
	 * @covers ::transform
	 */
	public function test_post_embed_filter_returning_null_suppresses_short_form_images_embed() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'img.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Attachment' )
		);
		\update_post_meta(
			$attachment_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafy',
				'mimeType' => 'image/jpeg',
				'size'     => 1,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Aside with image',
				'post_content' => 'Body text.',
			)
		);
		\set_post_format( $post_id, 'aside' );
		\set_post_thumbnail( $post_id, $attachment_id );
		$post = \get_post( $post_id );

		\add_filter( 'atmosphere_post_embed', '__return_null' );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * The `atmosphere_post_embed` filter receives the new image embed as
	 * the default value (not `null`) on a short-form post with an image,
	 * so listeners that want to inspect / augment the default can.
	 *
	 * @covers ::transform
	 */
	public function test_post_embed_filter_receives_images_default_on_short_form() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'img.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Attachment' )
		);
		\update_post_meta(
			$attachment_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafy',
				'mimeType' => 'image/jpeg',
				'size'     => 1,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Aside with image',
				'post_content' => 'Body text.',
			)
		);
		\set_post_format( $post_id, 'aside' );
		\set_post_thumbnail( $post_id, $attachment_id );
		$post = \get_post( $post_id );

		$seen = null;
		\add_filter(
			'atmosphere_post_embed',
			static function ( $embed ) use ( &$seen ) {
				$seen = $embed;
				return $embed;
			}
		);

		( new Post( $post ) )->transform();

		$this->assertIsArray( $seen );
		$this->assertSame( 'app.bsky.embed.images', $seen['$type'] );
	}

	/**
	 * A short-form post with neither an in-body image block nor a
	 * featured image still ships without an embed — the default doesn't
	 * synthesize one out of nothing.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_without_any_image_has_no_embed() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Aside no image',
				'post_content' => 'Just words.',
			)
		);
		\set_post_format( $post_id, 'aside' );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * A redacted (password-protected) short-form post with a featured
	 * image must not ship the image — mirrors the existing redaction
	 * posture for text and tags. Protects against leaking protected
	 * attachments to Bluesky.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_with_password_does_not_attach_images_embed() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'protected.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Protected attachment' )
		);
		\update_post_meta(
			$attachment_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafyprotected',
				'mimeType' => 'image/jpeg',
				'size'     => 1,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'Protected aside',
				'post_content'  => 'Secret body.',
				'post_password' => 'secret',
			)
		);
		\set_post_format( $post_id, 'aside' );
		\set_post_thumbnail( $post_id, $attachment_id );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * `core/image` blocks with non-positive or missing IDs are skipped by
	 * the collector. Locks the `> 0` and `isset` guards so a future
	 * refactor that drops them surfaces in CI rather than shipping
	 * placeholder/template blocks to Bluesky.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_skips_image_blocks_with_invalid_ids() {
		$featured_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'fallback.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Fallback attachment' )
		);
		\update_post_meta(
			$featured_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafyfallback',
				'mimeType' => 'image/jpeg',
				'size'     => 1,
			)
		);

		/*
		 * Paragraph keeps the post on the short-form path (otherwise
		 * `build_short_form_text()` returns empty and we fall back to
		 * long-form). Three image blocks that should all be skipped:
		 * id=0 (non-positive), missing id, and id="foo" (cast to int
		 * yields 0).
		 */
		$content = '<!-- wp:paragraph --><p>Aside body.</p><!-- /wp:paragraph -->'
			. '<!-- wp:image {"id":0} --><figure></figure><!-- /wp:image -->'
			. '<!-- wp:image {"sizeSlug":"large"} --><figure></figure><!-- /wp:image -->'
			. '<!-- wp:image {"id":"foo"} --><figure></figure><!-- /wp:image -->';

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Aside with invalid ids',
				'post_content' => $content,
			)
		);
		\set_post_format( $post_id, 'aside' );
		\set_post_thumbnail( $post_id, $featured_id );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		// Featured-image fallback applies because no valid in-body IDs
		// were collected. The single image is the featured image, not
		// any of the placeholder blocks.
		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.images', $record['embed']['$type'] );
		$this->assertCount( 1, $record['embed']['images'] );
		$this->assertSame( 'bafyfallback', $record['embed']['images'][0]['image']['cid'] );
	}

	/**
	 * When the attachment has no `_wp_attachment_image_alt` meta set at
	 * all, the embed still includes an `alt` field with an empty string —
	 * the Lexicon requires `alt` to be present on every image entry.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_image_embed_alt_is_empty_string_when_meta_missing() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'no-alt.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'No-alt attachment' )
		);
		\update_post_meta(
			$attachment_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafynoalt',
				'mimeType' => 'image/jpeg',
				'size'     => 1,
			)
		);
		// Intentionally do not set `_wp_attachment_image_alt`.

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Aside no alt',
				'post_content' => 'Words.',
			)
		);
		\set_post_format( $post_id, 'aside' );
		\set_post_thumbnail( $post_id, $attachment_id );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.images', $record['embed']['$type'] );
		$this->assertCount( 1, $record['embed']['images'] );
		$this->assertArrayHasKey( 'alt', $record['embed']['images'][0] );
		$this->assertSame( '', $record['embed']['images'][0]['alt'] );
	}

	/**
	 * The walker stops collecting at 32 IDs even when the post contains
	 * far more `core/image` blocks. Locks the breadth ceiling that
	 * protects an attacker-controlled `post_content` from growing the
	 * working array linearly. The downstream 4-image cap then trims
	 * what ships in the embed, so this guards the intermediate working
	 * set rather than the public Bluesky record.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_image_collector_stops_at_breadth_cap() {
		$valid_ids = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$id = self::factory()->attachment->create_object(
				array(
					'file'           => "first{$i}.jpg",
					'post_mime_type' => 'image/jpeg',
				),
				0,
				array( 'post_title' => "First {$i}" )
			);
			\update_post_meta(
				$id,
				'_atmosphere_blob_ref',
				array(
					'cid'      => "bafyfirst{$i}",
					'mimeType' => 'image/jpeg',
					'size'     => $i + 1,
				)
			);
			$valid_ids[] = $id;
		}

		// Tail attachment that sits at position 33+ in document order
		// — past the breadth cap. Its blob ref is fully valid; what
		// proves the cap held is its absence from the working set.
		$tail_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'tail.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Tail attachment' )
		);
		\update_post_meta(
			$tail_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafytail',
				'mimeType' => 'image/jpeg',
				'size'     => 99,
			)
		);

		$content = '<!-- wp:paragraph --><p>Lots of images aside.</p><!-- /wp:paragraph -->' . "\n\n";

		// First four valid IDs at the head, then 30 placeholder blocks
		// with synthetic IDs so the breadth cap fires before the tail
		// attachment is seen. The tail then sits at position 35.
		foreach ( $valid_ids as $id ) {
			$content .= '<!-- wp:image {"id":' . $id . '} --><figure></figure><!-- /wp:image -->';
		}
		for ( $i = 0; $i < 30; $i++ ) {
			$synthetic_id = 90000 + $i;
			$content     .= '<!-- wp:image {"id":' . $synthetic_id . '} --><figure></figure><!-- /wp:image -->';
		}
		$content .= '<!-- wp:image {"id":' . $tail_id . '} --><figure></figure><!-- /wp:image -->';

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Aside with many images',
				'post_content' => $content,
			)
		);
		\set_post_format( $post_id, 'aside' );
		$post = \get_post( $post_id );

		$record = ( new Post( $post ) )->transform();

		// AT Protocol cap trims to 4. Order in the embed is the first
		// four document-order IDs we wrote — proves the head wasn't
		// displaced by anything past the cap.
		$this->assertCount( 4, $record['embed']['images'] );
		$cids = \array_map( static fn( $img ) => $img['image']['cid'], $record['embed']['images'] );
		$this->assertSame(
			array( 'bafyfirst0', 'bafyfirst1', 'bafyfirst2', 'bafyfirst3' ),
			$cids
		);

		// The tail attachment was never collected — its blob ref would
		// have surfaced as a fifth-position CID if the walker had
		// continued past 32.
		$this->assertNotContains( 'bafytail', $cids );
	}

	/**
	 * The walker is depth-capped so a pathologically nested block tree
	 * — many wrapper levels with no images — cannot blow PHP's stack
	 * before the breadth guard ever fires. We construct a tree past the
	 * 16-level cap with an image at the bottom; the image is dropped
	 * because the walker bails before reaching it, and the call returns
	 * cleanly without a fatal.
	 *
	 * Mirror test confirms an image at depth ≤ 16 IS still collected,
	 * so the cap is a true upper bound rather than a regression of the
	 * legitimate nested-image case already covered by
	 * `test_short_form_collects_nested_image_blocks`.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_image_collector_stops_at_depth_cap() {
		$deep_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'deep.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'Too-deep attachment' )
		);
		\update_post_meta(
			$deep_id,
			'_atmosphere_blob_ref',
			array(
				'cid'      => 'bafydeep',
				'mimeType' => 'image/jpeg',
				'size'     => 1,
			)
		);

		// Build a 30-level deep nesting of `core/group` blocks wrapping
		// a single `core/image` at the bottom. Anything beyond the
		// walker's 16-level cap should be invisible to the collector.
		$nesting = 30;
		$open    = '';
		$close   = '';
		for ( $i = 0; $i < $nesting; $i++ ) {
			$open  .= '<!-- wp:group --><div class="wp-block-group">';
			$close .= '</div><!-- /wp:group -->';
		}

		$content = '<!-- wp:paragraph --><p>Deeply nested aside.</p><!-- /wp:paragraph -->'
			. $open
			. '<!-- wp:image {"id":' . $deep_id . '} -->'
			. '<figure class="wp-block-image"><img class="wp-image-' . $deep_id . '"/></figure>'
			. '<!-- /wp:image -->'
			. $close;

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Deeply nested image aside',
				'post_content' => $content,
			)
		);
		\set_post_format( $post_id, 'aside' );
		$post = \get_post( $post_id );

		// Past-cap image must be silently dropped and the call must
		// return cleanly (no fatal, no PHP warning).
		$record = ( new Post( $post ) )->transform();

		$this->assertArrayNotHasKey(
			'embed',
			$record,
			'Image past the 16-level depth cap must not surface in the embed.'
		);
	}

	/*
	 * -----------------------------------------------------------------
	 * Link-card embed — associatedRefs for the standard.site strongRefs.
	 * -----------------------------------------------------------------
	 */

	/**
	 * `set_document_strong_ref()` plus a captured publication strongRef
	 * land both entries in `embed.external.associatedRefs` on a fresh
	 * transform. Order: publication first, document second.
	 *
	 * This is the production-target shape — the initial atomic
	 * applyWrites must carry both refs so Bluesky's AppView indexes
	 * the post with `source` / `associatedProfiles` enrichment from
	 * the start.
	 *
	 * @covers ::transform
	 */
	public function test_long_form_embed_carries_both_strong_refs_when_injected() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ), false );
		\update_option( Publication::OPTION_TID, '3kpub00000000', false );
		\update_option( Publication::OPTION_CID, 'bafyreipublication0000000000000000000000000000000000000000000', false );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Long-form blog body.',
			)
		);

		$transformer = new Post( $post );
		$transformer->set_document_strong_ref(
			array(
				'uri' => 'at://did:plc:test123/site.standard.document/3kdoc00000000',
				'cid' => 'bafyreidoc000000000000000000000000000000000000000000000000000',
			)
		);

		$record = $transformer->transform();

		$refs = $record['embed']['external']['associatedRefs'];
		$this->assertCount( 2, $refs );
		$this->assertSame( 'com.atproto.repo.strongRef', $refs[0]['$type'] );
		$this->assertSame( 'at://did:plc:test123/site.standard.publication/3kpub00000000', $refs[0]['uri'] );
		$this->assertSame( 'com.atproto.repo.strongRef', $refs[1]['$type'] );
		$this->assertSame( 'at://did:plc:test123/site.standard.document/3kdoc00000000', $refs[1]['uri'] );
		$this->assertSame( 'bafyreidoc000000000000000000000000000000000000000000000000000', $refs[1]['cid'] );

		\delete_option( 'atmosphere_identity' );
		\delete_option( Publication::OPTION_TID );
		\delete_option( Publication::OPTION_CID );
	}

	/**
	 * When the document strongRef is NOT injected (e.g. on an update
	 * publish where the Publisher trusts the meta layer instead),
	 * `build_embed()` falls back to reading `Document::META_URI` /
	 * `Document::META_CID` from post meta. This is the path taken by
	 * `Publisher::update_post()` after the initial publish has
	 * already populated the document meta.
	 *
	 * @covers ::transform
	 */
	public function test_long_form_embed_falls_back_to_document_meta_when_not_injected() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ), false );
		\update_option( Publication::OPTION_TID, '3kpub00000000', false );
		\update_option( Publication::OPTION_CID, 'bafyreipublication0000000000000000000000000000000000000000000', false );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Long-form blog body.',
			)
		);

		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/3kmeta00000000' );
		\update_post_meta( $post->ID, Document::META_CID, 'bafyreidocmeta00000000000000000000000000000000000000000000000', false );

		$record = ( new Post( $post ) )->transform();

		$refs = $record['embed']['external']['associatedRefs'];
		$this->assertCount( 2, $refs );
		$this->assertSame( 'at://did:plc:test123/site.standard.document/3kmeta00000000', $refs[1]['uri'] );
		$this->assertSame( 'bafyreidocmeta00000000000000000000000000000000000000000000000', $refs[1]['cid'] );

		\delete_option( 'atmosphere_identity' );
		\delete_option( Publication::OPTION_TID );
		\delete_option( Publication::OPTION_CID );
	}

	/**
	 * Injection wins over meta when both are present — reflects what
	 * the Publisher is about to write (the injected value) rather than
	 * what was written previously (the meta value).
	 *
	 * @covers ::transform
	 */
	public function test_long_form_embed_injection_wins_over_meta() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Long-form blog body.',
			)
		);

		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/old' );
		\update_post_meta( $post->ID, Document::META_CID, 'bafyreioldoldoldoldoldoldoldoldoldoldoldoldoldoldoldoldoldold' );

		$transformer = new Post( $post );
		$transformer->set_document_strong_ref(
			array(
				'uri' => 'at://did:plc:test123/site.standard.document/new',
				'cid' => 'bafyreinewnewnewnewnewnewnewnewnewnewnewnewnewnewnewnewnewnew',
			)
		);

		$record = $transformer->transform();

		$refs    = $record['embed']['external']['associatedRefs'];
		$doc_ref = end( $refs );
		$this->assertSame( 'at://did:plc:test123/site.standard.document/new', $doc_ref['uri'] );
		$this->assertSame( 'bafyreinewnewnewnewnewnewnewnewnewnewnewnewnewnewnewnewnewnew', $doc_ref['cid'] );
	}

	/**
	 * Passing null explicitly suppresses the document-meta fallback.
	 *
	 * @covers ::transform
	 */
	public function test_long_form_embed_can_suppress_document_meta_fallback() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ), false );
		\update_option( Publication::OPTION_TID, '3kpub00000000', false );
		\update_option( Publication::OPTION_CID, 'bafyreipublication0000000000000000000000000000000000000000000', false );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Long-form blog body.',
			)
		);

		\update_post_meta( $post->ID, Document::META_URI, 'at://did:plc:test123/site.standard.document/stale' );
		\update_post_meta( $post->ID, Document::META_CID, 'bafyreistalestalestalestalestalestalestalestalestalestalestale' );

		$transformer = new Post( $post );
		$transformer->set_document_strong_ref( null );

		$record = $transformer->transform();

		$refs = $record['embed']['external']['associatedRefs'];
		$this->assertCount( 1, $refs );
		$this->assertSame( 'at://did:plc:test123/site.standard.publication/3kpub00000000', $refs[0]['uri'] );

		\delete_option( 'atmosphere_identity' );
		\delete_option( Publication::OPTION_TID );
		\delete_option( Publication::OPTION_CID );
	}

	/**
	 * Short-form posts (`app.bsky.embed.images`, or no embed) never
	 * carry `associatedRefs` — that field is defined only on
	 * `app.bsky.embed.external#external`. Even with both publication
	 * state and an explicitly-injected document ref, the short-form
	 * code path bypasses `build_embed()` entirely.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_post_never_carries_associated_refs() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ), false );
		\update_option( Publication::OPTION_TID, '3kpub00000000', false );
		\update_option( Publication::OPTION_CID, 'bafyreipublication0000000000000000000000000000000000000000000', false );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'A quick untitled thought.',
			)
		);

		$transformer = new Post( $post );
		$transformer->set_document_strong_ref(
			array(
				'uri' => 'at://did:plc:test123/site.standard.document/3kdoc',
				'cid' => 'bafyreidoc0000000000000000000000000000000000000000000000000000',
			)
		);

		$record = $transformer->transform();

		$this->assertArrayNotHasKey( 'embed', $record );

		\delete_option( 'atmosphere_identity' );
		\delete_option( Publication::OPTION_TID );
		\delete_option( Publication::OPTION_CID );
	}

	/**
	 * A short-form post projects as one short-form record whose grapheme
	 * count reflects the post body.
	 *
	 * @covers ::project
	 */
	public function test_project_short_form_post() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'A quick untitled thought.',
			)
		);

		$projection = ( new Post( $post ) )->project();

		$this->assertTrue( $projection['is_short_form'] );
		$this->assertSame( 'short-form', $projection['strategy'] );
		$this->assertSame( 300, $projection['limit'] );
		$this->assertCount( 1, $projection['records'] );
		$this->assertSame( 25, $projection['records'][0]['characters'] );
		$this->assertFalse( $projection['records'][0]['over_limit'] );
	}

	/**
	 * A short-form body longer than the limit projects as over-limit even
	 * though the published record is clamped — the panel must show the
	 * author's real length, not the truncated one.
	 *
	 * By default an overflowing titleless post is now reclassified to
	 * long-form, so this exercises a post kept short-form via the
	 * `atmosphere_is_short_form_post` filter — the path where the panel's
	 * over-limit warning still matters.
	 *
	 * @covers ::project
	 */
	public function test_project_short_form_over_limit_reports_untruncated_count() {
		\add_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$long_body = \str_repeat( 'word ', 100 ); // 500 characters.
		$post      = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => $long_body,
			)
		);

		$projection = ( new Post( $post ) )->project();

		$this->assertTrue( $projection['is_short_form'] );
		$this->assertCount( 1, $projection['records'] );
		$this->assertGreaterThan( 300, $projection['records'][0]['characters'] );
		$this->assertTrue( $projection['records'][0]['over_limit'] );
	}

	/**
	 * An overflowing post that carries in-body images stays short-form, so
	 * its native image gallery is not silently dropped for a link card.
	 *
	 * @covers ::is_short_form_post
	 * @covers ::transform
	 */
	public function test_overflowing_post_with_inbody_images_stays_short_form() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'inbody.jpg',
				'post_mime_type' => 'image/jpeg',
			),
			0,
			array( 'post_title' => 'In-body image' )
		);

		$long_body = \str_repeat( 'word ', 100 ); // 500 characters.
		$post      = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => $long_body . \sprintf( '<!-- wp:image {"id":%1$d} --><figure class="wp-block-image"><img class="wp-image-%1$d"/></figure><!-- /wp:image -->', $attachment_id ),
			)
		);

		$this->assertTrue(
			( new Post( $post ) )->is_short_form_post(),
			'An overflowing post with in-body images must stay short-form.'
		);
	}

	/**
	 * A titled post with no format and the default composition projects as
	 * a single link-card record.
	 *
	 * @covers ::project
	 */
	public function test_project_long_form_link_card() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);

		$projection = ( new Post( $post ) )->project();

		$this->assertFalse( $projection['is_short_form'] );
		$this->assertSame( 'link-card', $projection['strategy'] );
		$this->assertCount( 1, $projection['records'] );
		$this->assertLessThanOrEqual( 300, $projection['records'][0]['characters'] );
	}

	/**
	 * A teaser-thread composition projects as multiple records and reports
	 * the teaser-thread strategy.
	 *
	 * @covers ::project
	 */
	public function test_project_teaser_thread() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body sentence one. Body sentence two. Body sentence three.',
			)
		);

		\add_filter( 'atmosphere_long_form_composition', fn() => 'teaser-thread' );

		$projection = ( new Post( $post ) )->project();

		$this->assertFalse( $projection['is_short_form'] );
		$this->assertSame( 'teaser-thread', $projection['strategy'] );
		$this->assertGreaterThan( 1, \count( $projection['records'] ) );
		foreach ( $projection['records'] as $record ) {
			$this->assertLessThanOrEqual( 300, $record['characters'] );
		}
	}

	/**
	 * Projection mode never uploads a blob: a short-form post with a
	 * featured image keeps its body text and does not cache a blob ref.
	 *
	 * @covers ::project
	 */
	public function test_project_does_not_upload_blobs() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'Body with an image attached.',
			)
		);

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'image.jpg',
				'post_parent'    => $post->ID,
				'post_mime_type' => 'image/jpeg',
			)
		);
		\set_post_thumbnail( $post->ID, $attachment_id );

		$http_calls = 0;
		$counter    = static function ( $pre ) use ( &$http_calls ) {
			++$http_calls;
			return $pre;
		};
		\add_filter( 'pre_http_request', $counter );

		$projection = ( new Post( $post ) )->project();

		\remove_filter( 'pre_http_request', $counter );

		$this->assertTrue( $projection['is_short_form'] );
		$this->assertSame( 'short-form', $projection['strategy'] );
		$this->assertSame( 0, $http_calls, 'Projection must not make HTTP requests.' );
		$this->assertSame( '', \get_post_meta( $attachment_id, '_atmosphere_blob_ref', true ) );
	}
}
