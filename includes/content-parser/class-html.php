<?php
/**
 * WordPress HTML content parser.
 *
 * Emits the rendered post HTML — the output of the_content, analogous
 * to RSS `content:encoded`. This is the universal default format: it
 * works for any post (block or classic editor) and carries no blob
 * dependencies because media stays referenced by absolute URL.
 *
 * The lexicon is documented in docs/ alongside the other content formats.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Content_Parser;

\defined( 'ABSPATH' ) || exit;

/**
 * WordPress HTML content parser.
 */
class Html extends Parser_Base {

	/**
	 * The lexicon NSID this parser produces.
	 */
	const TYPE = 'org.wordpress.html'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText

	/**
	 * Lexicon grapheme cap on the `html` field.
	 */
	const MAX_GRAPHEMES = 100000;

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $content Raw post content (unused; rendered via the_content).
	 * @param \WP_Post $post    The WordPress post object.
	 * @return array|null
	 */
	public function parse( string $content, \WP_Post $post ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$html = $this->get_rendered_html( $post );

		if ( '' === \trim( $html ) ) {
			return null;
		}

		return array(
			'$type' => self::TYPE,
			'html'  => $this->truncate_graphemes( $html, self::MAX_GRAPHEMES ),
		);
	}
}
