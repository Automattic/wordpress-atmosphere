<?php
/**
 * Auto-links Bluesky `@handle.tld` mentions in rendered content.
 *
 * @package Atmosphere
 */

declare( strict_types = 1 );

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * Display-side mention linkifier.
 */
class Mention {

	/**
	 * Tags whose text content must never be linkified.
	 *
	 * Mirrors the ActivityPub plugin's protected-tag set so a mention inside
	 * an existing link, code sample, or preformatted block is left alone.
	 *
	 * @var string[]
	 */
	private const PROTECTED_TAGS = array( 'a', 'code', 'pre', 'textarea', 'style' );

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

		$tag_stack = array();
		$out       = '';

		foreach ( \wp_html_split( $content ) as $chunk ) {
			// HTML comment: copy through untouched.
			if ( \preg_match( '#^<!--[\s\S]*-->$#', $chunk ) ) {
				$out .= $chunk;
				continue;
			}

			// Opening / closing tag: maintain the stack, never linkify a tag.
			if ( \preg_match( '#^<(/)?([a-z0-9]+)\b[^>]*>$#i', $chunk, $m ) ) {
				$tag = \strtolower( $m[2] );
				if ( '/' === $m[1] ) {
					$i = \array_search( $tag, $tag_stack, true );
					if ( false !== $i ) {
						$tag_stack = \array_slice( $tag_stack, 0, $i );
					}
				} else {
					$tag_stack[] = $tag;
				}
				$out .= $chunk;
				continue;
			}

			// Text chunk: linkify only when no protected tag is open.
			if ( \array_intersect( $tag_stack, self::PROTECTED_TAGS ) ) {
				$out .= $chunk;
				continue;
			}

			$out .= self::linkify( $chunk );
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
	 * resolves the handle itself. A negative lookbehind on the `@` skips the
	 * domain half of an ActivityPub `@user@domain.tld` handle (and ordinary
	 * email addresses) — a preceding word char, `@`, or `.` disqualifies the
	 * match.
	 *
	 * @param string $text Plain-text chunk (no tags).
	 * @return string
	 */
	private static function linkify( string $text ): string {
		$pattern = '/(?<![\w@.])@([a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+)/u';

		$replaced = \preg_replace_callback(
			$pattern,
			static function ( array $m ): string {
				$handle = $m[1];
				$url    = appview_url(
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
