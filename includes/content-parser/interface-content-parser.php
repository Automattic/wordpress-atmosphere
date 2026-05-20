<?php
/**
 * Content parser interface for AT Protocol content formats.
 *
 * Plugins can implement this interface to provide custom content
 * parsers for the site.standard.document content union field.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Content_Parser;

\defined( 'ABSPATH' ) || exit;

/**
 * Content parser contract.
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
	 * Receives raw post content so parsers can choose their own
	 * strategy: parse_blocks() for block-aware parsing, or
	 * apply_filters( 'the_content', ... ) for rendered HTML.
	 *
	 * @param string   $content Raw post content (post_content).
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
