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

		$this->assertCount( 2, $records );
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

		$this->assertCount( 2, $seen );
		$this->assertNotEmpty( $seen[0]['createdAt'] );
		$this->assertNotEmpty( $seen[1]['createdAt'] );
		$this->assertSame( 'teaser-thread', $seen[0]['context']['strategy'] ?? '' );
		$this->assertSame( 0, $seen[0]['context']['thread_index'] ?? null );
		$this->assertFalse( $seen[0]['context']['is_thread_reply'] ?? true );
		$this->assertSame( 1, $seen[1]['context']['thread_index'] ?? null );
		$this->assertTrue( $seen[1]['context']['is_thread_reply'] ?? false );
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
	 * Teaser-thread default: 2 entries, hook cut at sentence punctuation,
	 * CTA starts with `Continue reading: <https?://...>`.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_default_two_entries() {
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

		$this->assertCount( 2, $records );

		// Hook.
		$hook = $records[0]['text'];
		$this->assertLessThanOrEqual( 280, \mb_strlen( $hook ) );
		$this->assertContains( \substr( $hook, -1 ), array( '.', '!', '?' ), 'Hook should end at sentence punctuation.' );
		$this->assertStringNotContainsString( \get_permalink( $post ), $hook );
		$this->assertArrayNotHasKey( 'embed', $records[0] );

		// CTA.
		$cta = $records[1]['text'];
		$this->assertMatchesRegularExpression( '~^Continue reading: https?://~', $cta );

		$has_cta_link_facet = false;
		foreach ( $records[1]['facets'] ?? array() as $facet ) {
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

		$this->assertSame( 'Custom-curated hook copy.', $records[0]['text'] );
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
	 * Downstream filters may extend the thread to 3 posts.
	 *
	 * @covers ::build_long_form_records
	 */
	public function test_build_long_form_records_teaser_thread_filter_extends_to_three() {
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
	}

	/**
	 * Filter that returns fewer than 2 entries should trigger
	 * _doing_it_wrong and fall back to the default hook + CTA pair —
	 * a 1-entry return would silently route to publish_single() and
	 * drop the CTA.
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

		$this->assertCount( 2, $records );
		$this->assertNotSame( 'Just one entry', $records[0]['text'] );
		$this->assertMatchesRegularExpression( '~^Continue reading: ~', $records[1]['text'] );
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
				'post_content' => 'Read about #testing sensors in this detailed write-up on instrumentation.',
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

		$cta_has_link = false;
		foreach ( $records[1]['facets'] ?? array() as $facet ) {
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
