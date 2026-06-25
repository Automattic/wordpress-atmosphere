<?php
/**
 * Tests for the per-post custom Bluesky text override.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

use WP_UnitTestCase;
use Atmosphere\Transformer\Post;

/**
 * Custom-text override tests.
 *
 * @coversDefaultClass \Atmosphere\Transformer\Post
 */
class Test_Post_Custom_Text extends WP_UnitTestCase {

	/**
	 * Tear down filters between tests so overrides don't leak.
	 */
	public function tear_down() {
		\remove_all_filters( 'atmosphere_long_form_composition' );
		\remove_all_filters( 'atmosphere_is_short_form_post' );
		\remove_all_filters( 'atmosphere_transform_bsky_post' );
		\remove_all_filters( 'pre_http_request' );

		$cache = new \ReflectionProperty( \Atmosphere\Transformer\Facet::class, 'resolution_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, array() );

		parent::tear_down();
	}

	/**
	 * Stub AT Protocol handle resolution over the HTTPS well-known endpoint so
	 * mention tests stay offline and deterministic. A handle absent from the
	 * map resolves to nothing.
	 *
	 * @param array<string,string> $map Handle => DID to return.
	 */
	private function mock_handle_resolution( array $map ) {
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $map ) {
				foreach ( $map as $handle => $did ) {
					if ( 'https://' . $handle . '/.well-known/atproto-did' === $url ) {
						return array(
							'body'     => $did,
							'response' => array(
								'code'    => '' === $did ? 404 : 200,
								'message' => '' === $did ? 'Not Found' : 'OK',
							),
						);
					}
				}
				return $pre;
			},
			10,
			3
		);
	}

	/**
	 * Custom text replaces the composed body and still attaches a link card
	 * back to the post — the link-card strategy with author-supplied prose.
	 *
	 * @covers ::transform
	 */
	public function test_custom_text_becomes_the_record_text_with_link_card() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'The full blog body that would normally be summarised.',
				'post_excerpt' => 'Teaser excerpt.',
			)
		);
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'My own take, just for Bluesky. #blogging' );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'My own take, just for Bluesky. #blogging', $record['text'] );
		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
		$this->assertSame( \get_permalink( $post ), $record['embed']['external']['uri'] );
	}

	/**
	 * The `atmosphere_transform_bsky_post` filter context flags a custom-text
	 * record with `is_custom_text => true` (while still reporting the
	 * structural `link-card` strategy), so hook authors can tell author-written
	 * text apart from the automatic link card.
	 *
	 * @covers ::transform
	 */
	public function test_filter_context_flags_custom_text() {
		$captured = array();
		\add_filter(
			'atmosphere_transform_bsky_post',
			static function ( $record, $post, $context ) use ( &$captured ) {
				$captured = $context;
				return $record;
			},
			10,
			3
		);

		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Titled' ) );
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'My words.' );

		( new Post( $post ) )->transform();

		$this->assertTrue( $captured['is_custom_text'] );
		$this->assertSame( 'link-card', $captured['strategy'] );
	}

	/**
	 * A post without custom text reports `is_custom_text => false`.
	 *
	 * @covers ::transform
	 */
	public function test_filter_context_not_flagged_without_custom_text() {
		$captured = array();
		\add_filter(
			'atmosphere_transform_bsky_post',
			static function ( $record, $post, $context ) use ( &$captured ) {
				$captured = $context;
				return $record;
			},
			10,
			3
		);

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body.',
				'post_excerpt' => 'Excerpt.',
			)
		);

		( new Post( $post ) )->transform();

		$this->assertFalse( $captured['is_custom_text'] );
	}

	/**
	 * Custom text overrides the short-form path too: an untitled post that
	 * would normally publish its body natively instead posts the custom text
	 * with a link card.
	 *
	 * @covers ::transform
	 */
	public function test_custom_text_overrides_short_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'A quick untitled thought.',
			)
		);
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'Hand-written note.' );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'Hand-written note.', $record['text'] );
		$this->assertArrayHasKey( 'embed', $record );
		$this->assertSame( 'app.bsky.embed.external', $record['embed']['$type'] );
	}

	/**
	 * Custom text bypasses the long-form composition strategy: even with
	 * `teaser-thread` configured, the post publishes as a single record.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_custom_text_collapses_teaser_thread_to_single_record() {
		\add_filter( 'atmosphere_long_form_composition', static fn () => 'teaser-thread' );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => \str_repeat( 'Body sentence that is long enough to thread. ', 20 ),
			)
		);
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'One post, my words.' );

		$records = ( new Post( $post ) )->build_long_form_records();

		$this->assertCount( 1, $records );
		$this->assertSame( 'One post, my words.', $records[0]['text'] );
	}

	/**
	 * Over-long custom text is clamped to the Bluesky limit rather than
	 * rejected, matching the short-form truncation contract.
	 *
	 * @covers ::transform
	 */
	public function test_custom_text_is_truncated_to_the_limit() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Titled' ) );
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, \str_repeat( 'word ', 100 ) );

		$record = ( new Post( $post ) )->transform();

		$this->assertLessThanOrEqual( Post::BLUESKY_MAX_GRAPHEMES, \mb_strlen( $record['text'] ) );
	}

	/**
	 * The author's line breaks survive — a Bluesky post can span lines, so
	 * custom text must not be flattened the way `sanitize_text()` would.
	 *
	 * @covers ::transform
	 */
	public function test_custom_text_preserves_line_breaks() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Titled' ) );
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, "Line one.\nLine two." );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( "Line one.\nLine two.", $record['text'] );
	}

	/**
	 * A literal "<" in the custom text survives to the record. The meta
	 * sanitizer escapes a stray "<" to "&lt;" on save; the transformer must
	 * decode it back to the author's text rather than running strip_tags (which
	 * would treat "<3 ..." as an unclosed tag and drop the rest).
	 *
	 * @covers ::transform
	 */
	public function test_custom_text_preserves_literal_less_than() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Titled' ) );
		// Mirrors what sanitize_textarea_field stores for "I <3 WordPress & cats".
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'I &lt;3 WordPress &amp; cats' );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'I <3 WordPress & cats', $record['text'] );
	}

	/**
	 * A custom-text post is treated as long-form by the publish-routing
	 * discriminator, even when its body would otherwise be short-form. This
	 * puts it on the path that precomputes the standard.site document and
	 * attaches the associatedRef to the link card at create time.
	 *
	 * @covers ::is_short_form_post
	 */
	public function test_custom_text_post_is_not_short_form() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'A quick untitled thought.',
			)
		);

		$transformer = new Post( $post );
		$this->assertTrue( $transformer->is_short_form_post(), 'Untitled short note is short-form without custom text.' );

		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'My words.' );

		$this->assertFalse(
			( new Post( $post ) )->is_short_form_post(),
			'A post with custom text routes through the long-form publish path.'
		);
	}

	/**
	 * A redacted (password-protected) post never leaks author-written custom
	 * text into a record.
	 *
	 * @covers ::transform
	 */
	public function test_custom_text_not_leaked_for_redacted_post() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'    => 'Secret',
				'post_password' => 'hunter2',
			)
		);
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'Should never ship.' );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( '', $record['text'] );
		$this->assertArrayNotHasKey( 'embed', $record );
	}

	/**
	 * Projection reports the `custom-text` strategy and the author's
	 * untruncated length so the panel can warn before publishing.
	 *
	 * @covers ::project
	 */
	public function test_project_reports_custom_text_strategy_and_real_length() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Titled' ) );
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, \str_repeat( 'word ', 100 ) );

		$projection = ( new Post( $post ) )->project();

		$this->assertSame( 'custom-text', $projection['strategy'] );
		$this->assertFalse( $projection['is_short_form'] );
		$this->assertCount( 1, $projection['records'] );
		$this->assertGreaterThan( Post::BLUESKY_MAX_GRAPHEMES, $projection['records'][0]['characters'] );
		$this->assertTrue( $projection['records'][0]['over_limit'] );
	}

	/**
	 * The projection override wins over saved meta, so the preview tracks the
	 * unsaved textarea: a set override projects custom text; an empty-string
	 * override forces the default composition even when meta is non-empty.
	 *
	 * @covers ::set_custom_text_override
	 * @covers ::project
	 */
	public function test_override_drives_projection_independently_of_meta() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Titled',
				'post_content' => 'Body.',
				'post_excerpt' => 'Excerpt.',
			)
		);

		// No saved meta, but an override set: projects as custom text.
		$with_override = new Post( $post );
		$with_override->set_custom_text_override( 'Typed but not yet saved.' );
		$this->assertSame( 'custom-text', $with_override->project()['strategy'] );

		// Saved meta present, but the override blanks it: default composition.
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'Saved custom text.' );
		$blanked = new Post( $post );
		$blanked->set_custom_text_override( '' );
		$this->assertSame( 'link-card', $blanked->project()['strategy'] );
	}

	/**
	 * Custom text takes precedence over the automatic composition, so a
	 * mention buried only in the post body is NOT carried into a custom-text
	 * record. Injecting a handle the author chose to omit would violate the
	 * "post exactly what I wrote" contract — and the body mention is never
	 * even resolved (a tripwire on outbound HTTP asserts no lookup happens).
	 *
	 * @covers ::transform
	 */
	public function test_custom_text_does_not_carry_body_only_mention() {
		// Any handle-resolution lookup here is a failure: the custom-text path
		// must not run the body-mention carry-over.
		\add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'Unexpected handle resolution lookup on the custom-text path.' );
			},
			1
		);

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Body that quietly names @alice.bsky.social deep inside.',
			)
		);
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'My own words, no handles.' );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'My own words, no handles.', $record['text'] );
		$this->assertStringNotContainsString( '@alice.bsky.social', $record['text'] );

		$mention_facets = \array_filter(
			$record['facets'] ?? array(),
			static fn( $facet ) => 'app.bsky.richtext.facet#mention' === ( $facet['features'][0]['$type'] ?? '' )
		);
		$this->assertCount( 0, $mention_facets, 'A body-only mention must not be carried into custom text.' );
	}

	/**
	 * A mention the author types directly into the custom Bluesky text still
	 * produces a `#mention` facet and notifies the account: facet extraction
	 * runs on the final record text whichever branch composed it.
	 *
	 * @covers ::transform
	 */
	public function test_custom_text_mention_still_produces_facet() {
		$this->mock_handle_resolution( array( 'alice.bsky.social' => 'did:plc:alice' ) );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'The full blog body.',
			)
		);
		\update_post_meta( $post->ID, ATMOSPHERE_META_CUSTOM_TEXT, 'Big thanks to @alice.bsky.social for this!' );

		$record = ( new Post( $post ) )->transform();

		$this->assertSame( 'Big thanks to @alice.bsky.social for this!', $record['text'] );

		$mention_facets = \array_values(
			\array_filter(
				$record['facets'] ?? array(),
				static fn( $facet ) => 'app.bsky.richtext.facet#mention' === ( $facet['features'][0]['$type'] ?? '' )
			)
		);
		$this->assertCount( 1, $mention_facets, 'A handle typed into custom text must still notify.' );
		$this->assertSame( 'did:plc:alice', $mention_facets[0]['features'][0]['did'] );
	}
}
