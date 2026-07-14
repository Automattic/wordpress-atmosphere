<?php
/**
 * Leaflet (pub.leaflet.content) content parser.
 *
 * Translates the WordPress block tree into a single Leaflet
 * `linearDocument` page. Covers the common 1:1 blocks (paragraph,
 * heading, image, list, quote, code); container blocks (group/columns)
 * are flattened; everything else is skipped. Rich-text facets are not
 * emitted yet, so text-bearing blocks carry plain text only, and
 * per-field grapheme caps are deferred to that same follow-up.
 *
 * @see https://github.com/hyperlink-academy/leaflet/tree/main/lexicons/pub/leaflet
 *
 * @package Atmosphere
 */

namespace Atmosphere\Content_Parser;

\defined( 'ABSPATH' ) || exit;

/**
 * Leaflet content parser.
 */
class Leaflet extends Parser_Base {

	/**
	 * The lexicon NSID this parser produces.
	 */
	const TYPE = 'pub.leaflet.content';

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * Block-tree formats need real Gutenberg blocks.
	 *
	 * @param \WP_Post $post The WordPress post object.
	 * @return bool
	 */
	public function applies_to( \WP_Post $post ): bool {
		return $this->has_blocks( $post ) && $this->saved_content_survives_rendering( $post );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $content Raw post content (unused; uses the block tree).
	 * @param \WP_Post $post    The WordPress post object.
	 * @return array|null
	 */
	public function parse( string $content, \WP_Post $post ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$blocks = $this->map_blocks( $this->get_blocks( $post ) );

		if ( empty( $blocks ) ) {
			return null;
		}

		return array(
			'$type' => $this->get_type(),
			'pages' => array(
				array(
					'$type'  => 'pub.leaflet.pages.linearDocument',
					'blocks' => $blocks,
				),
			),
		);
	}

	/**
	 * Map a list of WordPress blocks to Leaflet `#block` wrappers.
	 *
	 * @param array $blocks Parsed WordPress blocks.
	 * @return array Leaflet block wrappers ({ block: <typed> }).
	 */
	private function map_blocks( array $blocks ): array {
		$out = array();

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';

			// Flatten container blocks into their inner blocks.
			if ( \in_array( $name, array( 'core/group', 'core/columns', 'core/column' ), true ) ) {
				$out = \array_merge( $out, $this->map_blocks( $block['innerBlocks'] ?? array() ) );
				continue;
			}

			$mapped = $this->map_block( $block );
			if ( null !== $mapped ) {
				$out[] = array( 'block' => $mapped );
			}
		}

		return $out;
	}

	/**
	 * Map a single WordPress block to a Leaflet block union object.
	 *
	 * @param array $block Parsed WordPress block.
	 * @return array|null Typed Leaflet block, or null to skip.
	 */
	private function map_block( array $block ): ?array {
		switch ( $block['blockName'] ?? '' ) {
			case 'core/paragraph':
				$text = $this->block_plaintext( $block );
				return '' === $text
					? null
					: array(
						'$type'     => 'pub.leaflet.blocks.text',
						'plaintext' => $text,
					);

			case 'core/heading':
				$text = $this->block_plaintext( $block );
				return '' === $text
					? null
					: array(
						'$type'     => 'pub.leaflet.blocks.header',
						'level'     => $this->heading_level( $block ),
						'plaintext' => $text,
					);

			case 'core/quote':
			case 'core/pullquote':
				$text = $this->block_plaintext( $block );
				return '' === $text
					? null
					: array(
						'$type'     => 'pub.leaflet.blocks.blockquote',
						'plaintext' => $text,
					);

			case 'core/code':
			case 'core/preformatted':
				$text = $this->block_plaintext( $block );
				if ( '' === $text ) {
					return null;
				}
				$code = array(
					'$type'     => 'pub.leaflet.blocks.code',
					'plaintext' => $text,
				);
				$lang = (string) ( $block['attrs']['language'] ?? '' );
				if ( '' !== $lang ) {
					$code['language'] = $lang;
				}
				return $code;

			case 'core/list':
				return $this->map_list( $block );

			case 'core/image':
				return $this->map_image( $block );

			default:
				return null;
		}
	}

	/**
	 * Map a `core/list` block to a Leaflet ordered/unordered list.
	 *
	 * @param array $block Parsed list block.
	 * @return array|null
	 */
	private function map_list( array $block ): ?array {
		$children = array();

		foreach ( $this->list_item_texts( $block ) as $text ) {
			$children[] = array(
				'content' => array(
					'$type'     => 'pub.leaflet.blocks.text',
					'plaintext' => $text,
				),
			);
		}

		if ( empty( $children ) ) {
			return null;
		}

		$type = $this->is_ordered_list( $block )
			? 'pub.leaflet.blocks.orderedList'
			: 'pub.leaflet.blocks.unorderedList';

		return array(
			'$type'    => $type,
			'children' => $children,
		);
	}

	/**
	 * Map a `core/image` block to a Leaflet image block.
	 *
	 * The lexicon requires both the image blob and an aspect ratio, so
	 * an image whose blob can't be uploaded or whose dimensions are
	 * unknown is skipped rather than emitted in an invalid shape.
	 *
	 * @param array $block Parsed image block.
	 * @return array|null
	 */
	private function map_image( array $block ): ?array {
		$attachment_id = $this->image_attachment_id( $block );
		if ( null === $attachment_id ) {
			return null;
		}

		$blob   = $this->build_image_ref( $attachment_id );
		$aspect = $this->image_aspect_ratio( $attachment_id );

		if ( null === $blob || null === $aspect ) {
			return null;
		}

		$image = array(
			'$type'       => 'pub.leaflet.blocks.image',
			'image'       => $blob,
			'aspectRatio' => $aspect,
		);

		$alt = $this->image_alt_text( $attachment_id );
		if ( '' !== $alt ) {
			$image['alt'] = $alt;
		}

		return $image;
	}
}
