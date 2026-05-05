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
use Atmosphere\Transformer\Post;

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
		\remove_all_actions( 'atmosphere_long_form_strategy_downgraded' );
		parent::tear_down();
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
		$method->setAccessible( true );

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
	 * Short-form text over 300 graphemes is truncated.
	 *
	 * @covers ::transform
	 */
	public function test_short_form_truncates_over_cap() {
		$long_body = \str_repeat( 'Lorem ipsum dolor sit amet. ', 50 );
		$post      = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => $long_body,
			)
		);

		$record = ( new Post( $post ) )->transform();

		$this->assertLessThanOrEqual( 300, \mb_strlen( $record['text'] ) );
		$this->assertStringContainsString( 'Lorem', $record['text'] );
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
}
