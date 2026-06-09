<?php
/**
 * Pckt (blog.pckt.content) content parser.
 *
 * Translates the WordPress block tree into pckt's inline `items` block
 * list. Covers the common 1:1 blocks (paragraph, heading, image, list,
 * quote, code); container blocks (group/columns) are flattened;
 * everything else is skipped. Only the inline mode is emitted — the
 * >20KB blob/extended mode, richtext facets, and per-field grapheme
 * caps are follow-ups.
 *
 * @see did:plc:revjuqmkvrw6fnkxppqtszpv (com.atproto.lexicon.schema)
 *
 * @package Atmosphere
 */

namespace Atmosphere\Content_Parser;

\defined( 'ABSPATH' ) || exit;

/**
 * Pckt content parser.
 */
class Pckt extends Parser_Base {

	/**
	 * The lexicon NSID this parser produces.
	 */
	const TYPE = 'blog.pckt.content';

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
		$items = $this->map_blocks( $this->get_blocks( $post ) );

		if ( empty( $items ) ) {
			return null;
		}

		return array(
			'$type' => $this->get_type(),
			'items' => $items,
		);
	}

	/**
	 * Map a list of WordPress blocks to pckt inline block items.
	 *
	 * @param array $blocks Parsed WordPress blocks.
	 * @return array Typed pckt blocks.
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
				$out[] = $mapped;
			}
		}

		return $out;
	}

	/**
	 * Map a single WordPress block to a pckt block object.
	 *
	 * @param array $block Parsed WordPress block.
	 * @return array|null Typed pckt block, or null to skip.
	 */
	private function map_block( array $block ): ?array {
		switch ( $block['blockName'] ?? '' ) {
			case 'core/paragraph':
				$text = $this->block_plaintext( $block );
				return '' === $text ? null : $this->text_block( $text );

			case 'core/heading':
				$text = $this->block_plaintext( $block );
				return '' === $text
					? null
					: array(
						'$type'     => 'blog.pckt.block.heading',
						'level'     => $this->heading_level( $block ),
						'plaintext' => $text,
					);

			case 'core/quote':
			case 'core/pullquote':
				$text = $this->block_plaintext( $block );
				return '' === $text
					? null
					: array(
						'$type'   => 'blog.pckt.block.blockquote',
						'content' => array( $this->text_block( $text ) ),
					);

			case 'core/code':
			case 'core/preformatted':
				$text = $this->block_plaintext( $block );
				if ( '' === $text ) {
					return null;
				}
				$code = array(
					'$type'     => 'blog.pckt.block.codeBlock',
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
	 * A pckt text block.
	 *
	 * @param string $text Plain text.
	 * @return array
	 */
	private function text_block( string $text ): array {
		return array(
			'$type'     => 'blog.pckt.block.text',
			'plaintext' => $text,
		);
	}

	/**
	 * Map a `core/list` block to a pckt bullet/ordered list.
	 *
	 * Each item is a `listItem` wrapping a single text block, matching
	 * the lexicon nesting (list → listItem → text).
	 *
	 * @param array $block Parsed list block.
	 * @return array|null
	 */
	private function map_list( array $block ): ?array {
		$items = array();

		foreach ( $this->list_item_texts( $block ) as $text ) {
			$items[] = array(
				'$type'   => 'blog.pckt.block.listItem',
				'content' => array( $this->text_block( $text ) ),
			);
		}

		if ( empty( $items ) ) {
			return null;
		}

		$type = $this->is_ordered_list( $block )
			? 'blog.pckt.block.orderedList'
			: 'blog.pckt.block.bulletList';

		return array(
			'$type'   => $type,
			'content' => $items,
		);
	}

	/**
	 * Map a `core/image` block to a pckt image block.
	 *
	 * Pckt only requires `attrs.src`; the blob and aspect ratio are
	 * optional, so an image still maps even when the blob upload fails
	 * (carrying the external URL as `src`).
	 *
	 * @param array $block Parsed image block.
	 * @return array|null
	 */
	private function map_image( array $block ): ?array {
		$attachment_id = $this->image_attachment_id( $block );
		if ( null === $attachment_id ) {
			return null;
		}

		$src = (string) \wp_get_attachment_url( $attachment_id );
		if ( '' === $src ) {
			return null;
		}

		$attrs = array( 'src' => $src );

		$blob = $this->build_image_ref( $attachment_id );
		$link = '';
		if ( null !== $blob ) {
			$link = $blob['ref']['$link'] ?? '';
		}
		if ( null !== $blob && '' !== $link ) {
			$attrs['blob'] = $blob;
			$attrs['src']  = 'blob:' . $link;
		}

		$alt = $this->image_alt_text( $attachment_id );
		if ( '' !== $alt ) {
			$attrs['alt'] = $alt;
		}

		$aspect = $this->image_aspect_ratio( $attachment_id );
		if ( null !== $aspect ) {
			$attrs['aspectRatio'] = $aspect;
		}

		return array(
			'$type' => 'blog.pckt.block.image',
			'attrs' => $attrs,
		);
	}
}
