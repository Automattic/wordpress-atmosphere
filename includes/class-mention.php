<?php
/**
 * Auto-links Bluesky `@handle.tld` mentions in rendered content.
 *
 * @package Atmosphere
 */

declare( strict_types = 1 );

namespace Atmosphere;

use Atmosphere\Transformer\Facet;

\defined( 'ABSPATH' ) || exit;

/**
 * Display-side mention linkifier.
 *
 * @since unreleased
 */
class Mention {

	/**
	 * Tags whose text content must never be linkified.
	 *
	 * Mirrors the ActivityPub plugin's protected-tag set so a mention inside
	 * an existing link, code sample, or preformatted block is left alone, plus
	 * raw-text / non-rendered elements (`script`, `noscript`, `svg`, `iframe`,
	 * `title`) whose text must never become an `<a>` — that would corrupt the
	 * element rather than render a link.
	 *
	 * @var string[]
	 */
	private const PROTECTED_TAGS = array( 'a', 'code', 'pre', 'textarea', 'style', 'script', 'noscript', 'svg', 'iframe', 'title' );

	/**
	 * Whether linkification is currently suppressed.
	 *
	 * The transformer renders post content through `the_content` to compose
	 * the Bluesky post text. Linkifying there would turn a plain `@handle`
	 * into an `<a>`, which the post-text builder records as a `#link` facet
	 * (no notification) instead of a `#mention` facet (notifies). The builders
	 * wrap their `the_content` calls in {@see self::without_links()} so this
	 * guard short-circuits the filter for that path only.
	 *
	 * @var bool
	 */
	private static bool $suppressed = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		/*
		 * Priority 100: after the ActivityPub plugin's mention filter (99).
		 * A `@user@domain.tld` webfinger handle it has already wrapped in an
		 * anchor is then skipped here (protected `<a>` tag) rather than
		 * double-linked. The negative lookbehind in self::linkify() makes the
		 * coexistence robust even when ActivityPub is not installed.
		 */
		\add_filter( 'the_content', array( self::class, 'the_content' ), 100 );
	}

	/**
	 * Linkify bare `@handle.tld` mentions in rendered HTML.
	 *
	 * @param string $content Rendered HTML.
	 * @return string
	 */
	public static function the_content( string $content ): string {
		if ( self::$suppressed || '' === $content ) {
			return $content;
		}

		// Bound work on pathological input, mirroring ActivityPub's guard.
		if ( \strlen( $content ) > MB_IN_BYTES ) {
			return $content;
		}

		return self::walk(
			$content,
			// Linkify a text chunk only when no protected tag is open.
			static fn( string $text, bool $in_protected, string $prev_char ): string
				=> $in_protected ? $text : self::linkify( $text, $prev_char )
		);
	}

	/**
	 * Classify the `@handle.tld` mentions in rendered HTML by whether the
	 * front end would linkify them.
	 *
	 * Returns `array( 'linkable' => …, 'protected' => … )`:
	 *
	 * - `linkable`: a map of lowercased handle => first-seen handle for every
	 *   mention the display linkifier ({@see self::the_content()}) would turn
	 *   into a profile link, in first-appearance order. The publish path resolves
	 *   and carries exactly this set, so a Bluesky `#mention` (and its
	 *   notification) is only ever minted for a handle the site itself links.
	 * - `protected`: the set of lowercased handles that appear *only* inside a
	 *   protected region (a `<code>`/`<pre>` sample or an existing `<a href>`).
	 *   The transformer subtracts `linkable` from this to build the deny-set it
	 *   passes to {@see Facet::extract()}, so a handle buried in a code sample
	 *   never mints a `#mention` facet the front end leaves as plain text — even
	 *   when it leaks into the excerpt.
	 *
	 * Shares the {@see self::walk()} tokenizer (and therefore the exact
	 * protected-tag rules and cross-tag boundary handling) with the display
	 * linkifier, so publish and display can never disagree about what is a
	 * mention. Returns empty sets for empty or pathologically large content,
	 * mirroring {@see self::the_content()}, which linkifies neither.
	 *
	 * @since unreleased
	 *
	 * @param string $content Rendered HTML.
	 * @return array{linkable:array<string,string>,protected:array<string,true>}
	 */
	public static function classify_handles( string $content ): array {
		$result = array(
			'linkable'  => array(),
			'protected' => array(),
		);

		// Same empty / pathological-input guards as the linkifier: when it
		// would linkify nothing, the publish path must mint nothing.
		if ( '' === $content || \strlen( $content ) > MB_IN_BYTES ) {
			return $result;
		}

		self::walk(
			$content,
			static function ( string $text, bool $in_protected, string $prev_char ) use ( &$result ): string {
				if ( $in_protected ) {
					/*
					 * Collect protected-region handles greedily (no boundary
					 * prefix): over-collecting only ever *blocks* a mint, which
					 * is the safe direction. `linkable` is subtracted from this,
					 * so a handle that also appears in linkable text stays
					 * mintable.
					 */
					if ( \preg_match_all( Facet::MENTION_PATTERN, $text, $matches ) ) {
						foreach ( $matches[1] as $handle ) {
							$result['protected'][ \strtolower( $handle ) ] = true;
						}
					}
					return $text;
				}

				// Mirror self::linkify()'s cross-tag boundary so this scan and
				// the display linkifier classify a handle the same way.
				$prefix = self::boundary_prefix( $prev_char );
				if ( \preg_match_all( Facet::MENTION_PATTERN, $prefix . $text, $matches ) ) {
					foreach ( $matches[1] as $handle ) {
						$key = \strtolower( $handle );
						if ( ! isset( $result['linkable'][ $key ] ) ) {
							$result['linkable'][ $key ] = $handle;
						}
					}
				}
				return $text;
			}
		);

		return $result;
	}

	/**
	 * Walk rendered HTML chunk by chunk, tracking the open-tag stack.
	 *
	 * Tags and comments pass through untouched; each text chunk is handed to
	 * `$on_text( $text, $protected, $prev_char )`, where `$protected` is true
	 * when a {@see self::PROTECTED_TAGS} element is currently open and
	 * `$prev_char` is the last plain-text character emitted before this chunk
	 * (see below). The callback's return value is emitted in its place.
	 *
	 * `$prev_char` carries the boundary across inline markup: text on either
	 * side of a non-protected tag (`<b>bob</b>@example.com`) is glued, so the
	 * mention boundary check sees the `@handle` as the tail of the preceding
	 * word (an email) rather than a standalone handle — matching how the
	 * publish path's flattened plain text reads it. Protected regions are elided
	 * from the linkified stream, so their text does not advance the boundary.
	 *
	 * @param string   $content Rendered HTML.
	 * @param callable $on_text fn(string $text, bool $in_protected, string $prev_char): string.
	 * @return string
	 */
	private static function walk( string $content, callable $on_text ): string {
		$tag_stack = array();
		$out       = '';
		$prev_char = '';

		foreach ( \wp_html_split( $content ) as $chunk ) {
			// HTML comment: copy through untouched.
			if ( \preg_match( '#^<!--[\s\S]*-->$#', $chunk ) ) {
				$out .= $chunk;
				continue;
			}

			// Opening / closing tag: maintain the stack, never transform a tag.
			if ( \preg_match( '#^<(/)?([a-z][a-z0-9-]*)\b[^>]*>$#i', $chunk, $m ) ) {
				$tag = \strtolower( $m[2] );
				if ( '/' === $m[1] ) {
					/*
					 * Unwind to the *most recently* opened tag of this name,
					 * not the first. For well-formed nesting the match is the
					 * stack top, so only it is popped; with same-name nesting
					 * (e.g. `<code><code>…</code>…</code>`) popping the first
					 * match would drop the still-open outer tag and linkify
					 * text that is in fact still protected.
					 */
					$keys = \array_keys( $tag_stack, $tag, true );
					if ( ! empty( $keys ) ) {
						$tag_stack = \array_slice( $tag_stack, 0, \end( $keys ) );
					}
				} elseif ( ! self::is_self_closing( $chunk ) ) {
					/*
					 * Push opening tags only. A self-closed tag (`<svg/>`,
					 * `<iframe … />`) opens and closes in a single chunk, so
					 * pushing it would leave a phantom protected tag on the
					 * stack that is never popped — suppressing linkification for
					 * every mention after it in the render.
					 */
					$tag_stack[] = $tag;
				}
				$out .= $chunk;
				continue;
			}

			$in_protected = (bool) \array_intersect( $tag_stack, self::PROTECTED_TAGS );
			$out         .= $on_text( $chunk, $in_protected, $prev_char );

			// Advance the boundary past unprotected text only; protected text
			// is elided from the linkified stream, so a handle right after a
			// protected region still sees the character before that region.
			if ( ! $in_protected && '' !== $chunk ) {
				$prev_char = \mb_substr( $chunk, -1 );
			}
		}

		return $out;
	}

	/**
	 * The synthetic prefix that reproduces a cross-tag mention boundary.
	 *
	 * {@see Facet::MENTION_PATTERN}'s leading `(?<![\w@])` lookbehind can only
	 * see characters inside the string it runs against, so a per-chunk match
	 * can't tell that the previous chunk ended in a word character. Prepending
	 * a single word character when the boundary is "glued" makes the lookbehind
	 * reject a leading `@handle`, exactly as it would on the joined plain text.
	 * A boundary character (whitespace, punctuation, `.`, or start-of-content)
	 * needs no prefix, so a legitimate leading handle still matches.
	 *
	 * @param string $prev_char Character emitted immediately before the chunk.
	 * @return string `'x'` when the boundary is glued, `''` otherwise.
	 */
	private static function boundary_prefix( string $prev_char ): string {
		return ( '' !== $prev_char && \preg_match( '/[\w@]/u', $prev_char ) ) ? 'x' : '';
	}

	/**
	 * Whether a start-tag chunk is self-closing.
	 *
	 * Quoted attribute values are stripped first so a value that ends in `/`
	 * (or contains `>`) can't be mistaken for the tag's own self-closing slash.
	 * The tag then counts as self-closing only when the trailing `/` sits right
	 * after the tag name (`<svg/>`) or is separated from the last attribute by
	 * whitespace, a quote, or `=` (`<svg />`, `<iframe src="x"/>`). A `/` glued
	 * to an *unquoted* attribute value (`<a href=https://example.com/>`) is part
	 * of that value, so the element actually stays open and its `@handle`
	 * content remains protected.
	 *
	 * @param string $tag Full start-tag chunk, e.g. `<a href="…">`.
	 * @return bool
	 */
	private static function is_self_closing( string $tag ): bool {
		$bare = (string) \preg_replace( '/"[^"]*"|\'[^\']*\'/', '', $tag );

		return (bool) \preg_match( '#(?:^<[a-z][a-z0-9-]*|[\s"\'=])/\s*>$#i', $bare );
	}

	/**
	 * Run a callback with mention linkification suppressed.
	 *
	 * @param callable $callback Callback to run.
	 * @return mixed Callback return value.
	 */
	public static function without_links( callable $callback ): mixed {
		$previous         = self::$suppressed;
		self::$suppressed = true;

		try {
			return $callback();
		} finally {
			self::$suppressed = $previous;
		}
	}

	/**
	 * Replace `@handle.tld` with a link to the appview profile.
	 *
	 * No DNS: the handle goes straight into the appview `profile/<handle>`
	 * URL (via {@see appview_url()}, so self-hosted appviews configured
	 * through the `atmosphere_appview_host` filter are honoured), which
	 * resolves the handle itself. The shared {@see Facet::MENTION_PATTERN}
	 * skips the domain half of an ActivityPub `@user@domain.tld` handle (and
	 * ordinary email addresses) and rejects a domain-shaped WebFinger user
	 * half (`@notiz.blog@notiz.blog`).
	 *
	 * Existence is intentionally not checked here: a per-render lookup would
	 * turn every front-end page view into an outbound DNS/HTTP request to
	 * each mentioned domain. The publish path already gates `#mention` facets
	 * on real resolution ({@see Facet::resolve_mention()}), so a non-existent
	 * `@example.com` never notifies anyone; the display link merely points at
	 * the appview, which renders an unknown handle gracefully. A site that
	 * wants stricter display links can veto a handle through the
	 * `atmosphere_link_mention` filter.
	 *
	 * @param string $text      Plain-text chunk (no tags).
	 * @param string $prev_char Last plain-text character before this chunk, used
	 *                          to reproduce a mention boundary that falls across
	 *                          an inline-tag split. See {@see self::boundary_prefix()}.
	 * @return string
	 */
	private static function linkify( string $text, string $prev_char = '' ): string {
		$prefix   = self::boundary_prefix( $prev_char );
		$replaced = \preg_replace_callback(
			Facet::MENTION_PATTERN,
			static function ( array $m ): string {
				$handle = $m[1];

				/**
				 * Filters whether a bare `@handle` in rendered content is linked.
				 *
				 * The display linkifier does no network lookup, so by default
				 * every syntactically valid handle is linked and the appview
				 * resolves it. Return false to leave a specific handle as plain
				 * text — e.g. to gate on a cached existence check or an
				 * allowlist of known accounts.
				 *
				 * @since unreleased
				 *
				 * @param bool   $should_link Whether to link the handle. Default true.
				 * @param string $handle      The bare handle (no leading `@`).
				 */
				if ( ! \apply_filters( 'atmosphere_link_mention', true, $handle ) ) {
					return $m[0];
				}

				$url = appview_url(
					'profile/' . $handle,
					array(
						'type'   => 'mention',
						'handle' => $handle,
					)
				);

				return \sprintf(
					'<a class="atmosphere-mention" href="%s">@%s</a>',
					\esc_url( $url ),
					\esc_html( $handle )
				);
			},
			$prefix . $text
		);

		// preg_replace_callback returns null on PCRE failure; keep the text.
		if ( ! \is_string( $replaced ) ) {
			return $text;
		}

		// Drop the synthetic boundary prefix; it can never be part of a match.
		return '' === $prefix ? $replaced : \substr( $replaced, \strlen( $prefix ) );
	}
}
