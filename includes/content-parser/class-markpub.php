<?php
/**
 * Markpub content parser.
 *
 * Converts WordPress block content into the at.markpub.markdown format
 * by iterating Gutenberg blocks and converting each to its markdown
 * equivalent.
 *
 * @see https://markpub.at/
 *
 * @package Atmosphere
 */

namespace Atmosphere\Content_Parser;

\defined( 'ABSPATH' ) || exit;

/**
 * Markpub (at.markpub.markdown) content parser.
 */
class Markpub implements Content_Parser {

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'at.markpub.markdown';
	}

	/**
	 * {@inheritDoc}
	 *
	 * $post is required by the Content_Parser contract so parsers can
	 * access post metadata; Markpub only needs $content.
	 *
	 * @param string   $content Raw post content.
	 * @param \WP_Post $post    The WordPress post object.
	 */
	public function parse( string $content, \WP_Post $post ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$blocks = \parse_blocks( $content );
		$parts  = array();

		foreach ( $blocks as $block ) {
			$md = self::transform_block( $block );

			if ( null !== $md ) {
				$parts[] = $md;
			}
		}

		$markdown = \implode( "\n\n", $parts );

		/**
		 * Filters the markdown produced from post content.
		 *
		 * @param string $markdown Converted markdown.
		 * @param string $content  Raw post content.
		 */
		$markdown = \apply_filters( 'atmosphere_html_to_markdown', $markdown, $content );

		if ( '' === \trim( $markdown ) ) {
			return null;
		}

		return array(
			'$type'      => 'at.markpub.markdown',
			'text'       => array(
				'$type'    => 'at.markpub.text',
				'markdown' => $markdown,
			),
			'flavor'     => 'gfm',
			'extensions' => array( 'strikethrough' ),
		);
	}

	/**
	 * Convert a single WordPress block to markdown.
	 *
	 * @param array $block Parsed block from parse_blocks().
	 * @return string|null Markdown string or null to skip.
	 */
	private static function transform_block( array $block ): ?string {
		if ( empty( $block['blockName'] ) ) {
			// Classic (non-block) content or whitespace.
			$md = self::inline_html_to_markdown( $block['innerHTML'] ?? '' );

			return '' === $md ? null : $md;
		}

		return match ( $block['blockName'] ) {
			'core/paragraph'    => self::paragraph( $block ),
			'core/heading'      => self::heading( $block ),
			'core/image'        => self::image( $block ),
			'core/list'         => self::listing( $block ),
			'core/quote'        => self::quote( $block ),
			'core/code'         => self::code( $block ),
			'core/preformatted' => self::preformatted( $block ),
			'core/separator'    => '---',
			'core/spacer'       => null,
			'core/group',
			'core/columns',
			'core/column'       => self::container( $block ),
			default             => self::fallback( $block ),
		};
	}

	/**
	 * Paragraph block.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private static function paragraph( array $block ): ?string {
		$md = self::inline_html_to_markdown( $block['innerHTML'] ?? '' );

		return '' === $md ? null : $md;
	}

	/**
	 * Heading block.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private static function heading( array $block ): ?string {
		$level = $block['attrs']['level'] ?? 2;
		$text  = self::inline_html_to_markdown( $block['innerHTML'] ?? '' );

		if ( empty( \trim( $text ) ) ) {
			return null;
		}

		return \str_repeat( '#', (int) $level ) . ' ' . \trim( $text );
	}

	/**
	 * Image block.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private static function image( array $block ): ?string {
		$html = $block['innerHTML'] ?? '';
		$src  = '';
		$alt  = '';

		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( $processor->next_tag( 'IMG' ) ) {
			$src = $processor->get_attribute( 'src' ) ?? '';
			$alt = $processor->get_attribute( 'alt' ) ?? '';
		}

		if ( empty( $src ) ) {
			return null;
		}

		$md = '![' . $alt . '](' . $src . ')';

		// Check for a caption in figcaption.
		$caption_proc = new \WP_HTML_Tag_Processor( $html );
		if ( $caption_proc->next_tag( 'FIGCAPTION' ) ) {
			// Strip both ends of the figcaption tag BEFORE stripping
			// remaining tags, so sibling content after </figcaption>
			// (e.g. a trailing <p> inside the same <figure>) doesn't
			// bleed into the caption text.
			$caption = self::safe_replace( '#.*<figcaption[^>]*>#si', '', $html );
			$caption = self::safe_replace( '#</figcaption>.*#si', '', $caption );
			$caption = \trim( \wp_strip_all_tags( $caption ) );

			if ( ! empty( $caption ) ) {
				$md .= "\n" . $caption;
			}
		}

		return $md;
	}

	/**
	 * List block.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private static function listing( array $block ): ?string {
		$ordered = ! empty( $block['attrs']['ordered'] );
		$items   = array();
		$counter = 0;

		if ( ! empty( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner ) {
				$text = self::inline_html_to_markdown( $inner['innerHTML'] ?? '' );
				$text = \trim( $text );

				if ( empty( $text ) ) {
					continue;
				}

				++$counter;
				$prefix  = $ordered ? $counter . '. ' : '- ';
				$items[] = $prefix . $text;
			}
		}

		return empty( $items ) ? null : \implode( "\n", $items );
	}

	/**
	 * Quote block.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private static function quote( array $block ): ?string {
		$lines = array();

		if ( ! empty( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner ) {
				$md = self::transform_block( $inner );
				if ( null !== $md ) {
					$lines[] = $md;
				}
			}
		}

		if ( empty( $lines ) ) {
			$text = self::inline_html_to_markdown( $block['innerHTML'] ?? '' );
			if ( empty( \trim( $text ) ) ) {
				return null;
			}
			$lines = array( \trim( $text ) );
		}

		$quoted = \implode( "\n", $lines );

		// Prefix each line with >.
		return \implode(
			"\n",
			\array_map(
				static fn( $line ) => '> ' . $line,
				\explode( "\n", $quoted )
			)
		);
	}

	/**
	 * Code block.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private static function code( array $block ): ?string {
		$text = \wp_strip_all_tags( $block['innerHTML'] ?? '' );
		$text = \html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = \trim( $text );

		if ( empty( $text ) ) {
			return null;
		}

		$lang = $block['attrs']['language'] ?? '';

		return '```' . $lang . "\n" . $text . "\n```";
	}

	/**
	 * Preformatted block.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private static function preformatted( array $block ): ?string {
		return self::code( $block );
	}

	/**
	 * Container block — flatten inner blocks.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private static function container( array $block ): ?string {
		if ( empty( $block['innerBlocks'] ) ) {
			return null;
		}

		$parts = array();

		foreach ( $block['innerBlocks'] as $inner ) {
			$md = self::transform_block( $inner );
			if ( null !== $md ) {
				$parts[] = $md;
			}
		}

		return empty( $parts ) ? null : \implode( "\n\n", $parts );
	}

	/**
	 * Fallback for unknown block types.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private static function fallback( array $block ): ?string {
		if ( ! empty( $block['innerBlocks'] ) ) {
			return self::container( $block );
		}

		$md = self::inline_html_to_markdown( $block['innerHTML'] ?? '' );

		return '' === $md ? null : $md;
	}

	/**
	 * Convert inline HTML formatting to markdown.
	 *
	 * Handles links, bold, italic, strikethrough, inline code,
	 * images, and line breaks. Strips block-level wrappers and
	 * remaining tags.
	 *
	 * @param string $html HTML string.
	 * @return string Markdown string.
	 */
	private static function inline_html_to_markdown( string $html ): string {
		$html = \trim( $html );

		if ( empty( $html ) ) {
			return '';
		}

		$md = $html;

		// Inline images.
		$md = self::safe_replace_callback(
			'#<img[^>]+>#si',
			static function ( $m ) {
				$processor = new \WP_HTML_Tag_Processor( $m[0] );
				if ( $processor->next_tag( 'IMG' ) ) {
					$src = $processor->get_attribute( 'src' ) ?? '';
					$alt = $processor->get_attribute( 'alt' ) ?? '';
					return '![' . $alt . '](' . $src . ')';
				}
				return '';
			},
			$md
		);

		// Links — percent-encode parentheses to avoid breaking markdown syntax.
		$md = self::safe_replace_callback(
			'#<a[^>]+href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#si',
			static fn( $m ) => '[' . \wp_strip_all_tags( $m[2] ) . '](' . \str_replace( array( '(', ')' ), array( '%28', '%29' ), $m[1] ) . ')',
			$md
		);

		// Bold.
		$md = self::safe_replace( '#<(?:strong|b)>(.*?)</(?:strong|b)>#si', '**$1**', $md );

		// Italic.
		$md = self::safe_replace( '#<(?:em|i)>(.*?)</(?:em|i)>#si', '*$1*', $md );

		// Strikethrough.
		$md = self::safe_replace( '#<(?:s|del|strike)>(.*?)</(?:s|del|strike)>#si', '~~$1~~', $md );

		// Inline code.
		$md = self::safe_replace( '#<code>(.*?)</code>#si', '`$1`', $md );

		// Line breaks.
		$md = self::safe_replace( '#<br\s*/?\s*>#si', "  \n", $md );

		// Strip block-level wrappers and remaining tags.
		$md = \wp_strip_all_tags( $md );

		// Decode HTML entities.
		$md = \html_entity_decode( $md, ENT_QUOTES, 'UTF-8' );

		return \trim( $md );
	}

	/**
	 * Wraps preg_replace with a fallback that preserves the input on PCRE failure.
	 *
	 * The underlying preg_replace returns null on engine failure
	 * (e.g. backtrack or recursion limit hit on pathological input).
	 * Without a guard, null cascades through subsequent string
	 * operations and can erase the whole buffer with no signal.
	 *
	 * @param string $pattern     Pattern.
	 * @param string $replacement Replacement.
	 * @param string $subject     Input.
	 * @return string Replaced string, or the original on PCRE failure.
	 */
	private static function safe_replace( string $pattern, string $replacement, string $subject ): string {
		$result = \preg_replace( $pattern, $replacement, $subject );

		if ( null === $result ) {
			self::warn_pcre_failure( $pattern );
			return $subject;
		}

		return $result;
	}

	/**
	 * Wraps preg_replace_callback with the same failure guard as safe_replace().
	 *
	 * @param string   $pattern  Pattern.
	 * @param callable $callback Callback.
	 * @param string   $subject  Input.
	 * @return string Replaced string, or the original on PCRE failure.
	 */
	private static function safe_replace_callback( string $pattern, callable $callback, string $subject ): string {
		$result = \preg_replace_callback( $pattern, $callback, $subject );

		if ( null === $result ) {
			self::warn_pcre_failure( $pattern );
			return $subject;
		}

		return $result;
	}

	/**
	 * Emit a warning about a PCRE failure without hard-failing.
	 *
	 * @param string $pattern The pattern that failed.
	 */
	private static function warn_pcre_failure( string $pattern ): void {
		if ( \function_exists( 'wp_trigger_error' ) ) {
			\wp_trigger_error(
				__METHOD__,
				\sprintf( 'PCRE failure on pattern %s; preserving input.', $pattern )
			);
		}
	}
}
