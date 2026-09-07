<?php
/**
 * Content parser interface for AT Protocol content formats.
 *
 * Plugins can implement this interface to provide custom content
 * parsers for the site.standard.document content union field.
 * Extend Parser_Base when possible: it adds shared WordPress helpers
 * and an optional applies_to() hook the registry understands.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Content_Parser;

\defined( 'ABSPATH' ) || exit;

/**
 * Content parser contract.
 *
 * This interface intentionally stays small for third-party
 * compatibility. Parsers that need post-specific applicability can
 * extend Parser_Base or define an applies_to( \WP_Post $post ): bool
 * method; Registry treats parsers without that method as applicable.
 */
interface Content_Parser {

	/**
	 * Parse WordPress post content into an AT Protocol content object.
	 *
	 * The returned array must include a '$type' key identifying the
	 * lexicon type (e.g. 'at.markpub.markdown'). Return null to signal
	 * that the parser produced no usable output — Document will then
	 * omit the content field — which is preferable to shipping an
	 * empty-text record.
	 *
	 * Receives the post's *publishable* content — the body already narrowed by
	 * membership/paywall integrations (see
	 * `Atmosphere\get_publishable_content()`), never the raw `post_content`.
	 * Parsers choose their own strategy over it: parse_blocks() for block-aware
	 * parsing, or the render helper for rendered HTML.
	 *
	 * IMPORTANT: do not re-render this string with a bare
	 * apply_filters( 'the_content', ... ). A membership plugin's own
	 * `the_content` gate would re-read the *global* post and could put the
	 * gated body — or a "subscribe to keep reading" form — straight back into
	 * the record. Use `Atmosphere\render_publishable_content( $post )`, which
	 * suspends those gates for the duration of the render.
	 *
	 * @param string   $content Publishable post content (gated portions removed).
	 * @param \WP_Post $post    The WordPress post object.
	 * @return array|null AT Protocol content object, or null to omit.
	 */
	public function parse( string $content, \WP_Post $post ): ?array;

	/**
	 * The lexicon NSID this parser produces.
	 *
	 * @return string e.g. 'at.markpub.markdown'.
	 */
	public function get_type(): string;
}
