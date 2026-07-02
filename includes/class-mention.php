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
			static fn( string $text, bool $in_protected ): string => $in_protected ? $text : self::linkify( $text )
		);
	}

	/**
	 * Return HTML with the text inside protected tags removed.
	 *
	 * Shares the tokenizer (and therefore the exact protected-tag rules) with
	 * {@see self::the_content()}, so the publish path can find the same set of
	 * "linkable" mentions the front end would render. A `@handle` living inside
	 * a `<code>` sample or an existing `<a href>` link is dropped here, so it is
	 * never carried into the Bluesky post text nor minted into a `#mention`
	 * facet the display side would have left as plain text.
	 *
	 * Tags themselves are preserved so a downstream {@see \Atmosphere\sanitize_text()}
	 * still yields clean plain text; only the protected regions' text is elided.
	 *
	 * @since unreleased
	 *
	 * @param string $content Rendered HTML.
	 * @return string
	 */
	public static function strip_protected( string $content ): string {
		if ( '' === $content ) {
			return $content;
		}

		// Same pathological-input guard as the linkifier.
		if ( \strlen( $content ) > MB_IN_BYTES ) {
			return $content;
		}

		return self::walk(
			$content,
			// Keep unprotected text; drop the text inside protected tags.
			static fn( string $text, bool $in_protected ): string => $in_protected ? '' : $text
		);
	}

	/**
	 * Walk rendered HTML chunk by chunk, tracking the open-tag stack.
	 *
	 * Tags and comments pass through untouched; each text chunk is handed to
	 * `$on_text( $text, $protected )`, where `$protected` is true when a
	 * {@see self::PROTECTED_TAGS} element is currently open, and the callback's
	 * return value is emitted in its place.
	 *
	 * @param string   $content Rendered HTML.
	 * @param callable $on_text fn(string $text, bool $in_protected): string.
	 * @return string
	 */
	private static function walk( string $content, callable $on_text ): string {
		$tag_stack = array();
		$out       = '';

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
				} elseif ( ! \preg_match( '#/\s*>$#', $chunk ) ) {
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
			$out         .= $on_text( $chunk, $in_protected );
		}

		return $out;
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
	 * @param string $text Plain-text chunk (no tags).
	 * @return string
	 */
	private static function linkify( string $text ): string {
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
			$text
		);

		// preg_replace_callback returns null on PCRE failure; keep the text.
		return \is_string( $replaced ) ? $replaced : $text;
	}
}
