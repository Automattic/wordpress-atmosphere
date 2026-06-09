<?php
/**
 * Base class for content parsers.
 *
 * Concrete parsers extend this instead of implementing the
 * Content_Parser interface directly, so the WordPress-coupling
 * (block-tree access, rendered HTML, image-blob uploads, grapheme
 * clamping) lives in one place rather than being re-derived by every
 * format.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Content_Parser;

use Atmosphere\Transformer\Post;
use function Atmosphere\sanitize_text;
use function Atmosphere\to_iso8601;
use function Atmosphere\truncate_graphemes;

\defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers and sane defaults for content parsers.
 */
abstract class Parser_Base implements Content_Parser {

	/**
	 * Per-request cache of parsed block trees, keyed by post ID.
	 *
	 * `parse_blocks()` is not free and a parser commonly walks the tree
	 * more than once (e.g. once to collect images, once to build the
	 * record), so memoize it for the life of the request.
	 *
	 * @var array<int,array>
	 */
	private static array $block_cache = array();

	/**
	 * Per-request cache of rendered HTML, keyed by post ID.
	 *
	 * @var array<int,string>
	 */
	private static array $rendered_html_cache = array();

	/**
	 * Clear parser caches.
	 *
	 * The cache assumes a post's content is stable for the life of a
	 * request. Tests that mutate post content and re-parse, or that
	 * reuse recycled post IDs, should call this between parses.
	 *
	 * @internal
	 * @return void
	 */
	public static function flush_block_cache(): void {
		self::$block_cache         = array();
		self::$rendered_html_cache = array();
	}

	/**
	 * Whether this parser can produce content for the given post.
	 *
	 * Defaults to true: most parsers can render something for any post.
	 * Block-tree formats should override this to require blocks.
	 *
	 * @param \WP_Post $post The WordPress post object.
	 * @return bool
	 */
	public function applies_to( \WP_Post $post ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return true;
	}

	/**
	 * Parsed block tree for a post, memoized per request.
	 *
	 * @param \WP_Post $post The WordPress post object.
	 * @return array Parsed blocks from parse_blocks().
	 */
	final protected function get_blocks( \WP_Post $post ): array {
		if ( ! isset( self::$block_cache[ $post->ID ] ) ) {
			self::$block_cache[ $post->ID ] = \parse_blocks( $post->post_content );
		}

		return self::$block_cache[ $post->ID ];
	}

	/**
	 * Whether the post carries real (named) Gutenberg blocks.
	 *
	 * Classic-editor content parses as a single nameless block, so the
	 * presence of at least one named block is the signal that block-tree
	 * parsers can work with the post.
	 *
	 * @param \WP_Post $post The WordPress post object.
	 * @return bool
	 */
	final protected function has_blocks( \WP_Post $post ): bool {
		foreach ( $this->get_blocks( $post ) as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rendered post HTML with the_content applied.
	 *
	 * Runs inside setup_postdata()/reset so shortcodes and blocks that
	 * rely on the global post resolve, matching front-end output.
	 *
	 * @param \WP_Post $post The WordPress post object.
	 * @return string
	 */
	final protected function get_rendered_html( \WP_Post $post ): string {
		global $wp_query;

		if ( isset( self::$rendered_html_cache[ $post->ID ] ) ) {
			return self::$rendered_html_cache[ $post->ID ];
		}

		/*
		 * setup_postdata() works through the global $wp_query, which can
		 * be absent in cron / WP-CLI publish paths. Only set up and
		 * restore the loop context when a real query exists; otherwise
		 * run the filter directly so those paths don't fatal.
		 */
		$has_query            = $wp_query instanceof \WP_Query;
		$previous_query_post  = $has_query ? $wp_query->post : null;
		$previous_global_post = $GLOBALS['post'] ?? null;

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- setup_postdata() does not update the global post.

		if ( $has_query ) {
			$wp_query->post = $post;
			\setup_postdata( $post );
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.
			$html = \apply_filters( 'the_content', $post->post_content );
		} finally {
			if ( $has_query ) {
				if ( $previous_query_post instanceof \WP_Post ) {
					$GLOBALS['post'] = $previous_query_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous loop context.
					$wp_query->post  = $previous_query_post;
					\setup_postdata( $previous_query_post );
				} else {
					\wp_reset_postdata();
				}
			}

			if ( $previous_global_post instanceof \WP_Post ) {
				$GLOBALS['post'] = $previous_global_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post.
			} else {
				unset( $GLOBALS['post'] );
			}
		}

		self::$rendered_html_cache[ $post->ID ] = \trim( $html );

		return self::$rendered_html_cache[ $post->ID ];
	}

	/**
	 * Post content rendered to plain text.
	 *
	 * @param \WP_Post $post The WordPress post object.
	 * @return string
	 */
	final protected function get_plain_text( \WP_Post $post ): string {
		return sanitize_text( $this->get_rendered_html( $post ) );
	}

	/**
	 * Collect image attachment IDs referenced by `core/image` blocks.
	 *
	 * Walks the block tree (including inner blocks) and returns the
	 * deduplicated attachment IDs in document order. Blocks without a
	 * resolvable attachment ID (e.g. external-URL images) are skipped.
	 *
	 * @param \WP_Post $post The WordPress post object.
	 * @return int[]
	 */
	final protected function collect_image_attachments( \WP_Post $post ): array {
		$ids = array();

		$this->walk_blocks(
			$this->get_blocks( $post ),
			static function ( array $block ) use ( &$ids ): void {
				if ( 'core/image' === ( $block['blockName'] ?? '' )
					&& ! empty( $block['attrs']['id'] )
				) {
					$ids[] = (int) $block['attrs']['id'];
				}
			}
		);

		return \array_values( \array_unique( $ids ) );
	}

	/**
	 * Build a lexicon image blob ref for an attachment, uploading once.
	 *
	 * Delegates to Post::upload_image_blob(), which caches the blob ref
	 * in post meta so repeated calls (and re-publishes) don't re-upload.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Blob reference, or null when the upload fails.
	 */
	final protected function build_image_ref( int $attachment_id ): ?array {
		return Post::upload_image_blob( $attachment_id );
	}

	/**
	 * Clamp text to a lexicon grapheme limit.
	 *
	 * @param string $text          Text to clamp.
	 * @param int    $max_graphemes Maximum graphemes.
	 * @return string
	 */
	final protected function truncate_graphemes( string $text, int $max_graphemes ): string {
		return truncate_graphemes( $text, $max_graphemes );
	}

	/**
	 * WordPress locale as a BCP-47 language tag array.
	 *
	 * @return string[]
	 */
	final protected function get_langs(): array {
		return array( \substr( \get_locale(), 0, 2 ) );
	}

	/**
	 * Convert a GMT datetime string to ISO 8601.
	 *
	 * @param string $datetime GMT datetime.
	 * @return string
	 */
	final protected function iso8601( string $datetime ): string {
		return to_iso8601( $datetime );
	}

	/**
	 * Depth-first walk over a block tree, invoking $visitor per block.
	 *
	 * @param array    $blocks  Parsed blocks.
	 * @param callable $visitor Receives each block array.
	 * @return void
	 */
	final protected function walk_blocks( array $blocks, callable $visitor ): void {
		foreach ( $blocks as $block ) {
			$visitor( $block );

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_blocks( $block['innerBlocks'], $visitor );
			}
		}
	}

	/**
	 * Plain text of a block.
	 *
	 * Blocks whose text lives in inner blocks (e.g. `core/quote`, which
	 * wraps `core/paragraph`) are gathered recursively; leaf blocks use
	 * their inner HTML. Markup is stripped and entities decoded —
	 * structured formats that carry a `plaintext` field (Leaflet, pckt)
	 * drop inline formatting until facets are supported.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	final protected function block_plaintext( array $block ): string {
		if ( ! empty( $block['innerBlocks'] ) ) {
			$parts = array();
			foreach ( $block['innerBlocks'] as $inner ) {
				$text = $this->block_plaintext( $inner );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}

			return \implode( "\n", $parts );
		}

		$text = \wp_strip_all_tags( $block['innerHTML'] ?? '' );
		$text = \html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		return \trim( $text );
	}

	/**
	 * Heading level for a `core/heading` block, clamped to 1–6.
	 *
	 * @param array $block Parsed block.
	 * @return int
	 */
	final protected function heading_level( array $block ): int {
		$level = (int) ( $block['attrs']['level'] ?? 2 );

		return \max( 1, \min( 6, $level ) );
	}

	/**
	 * Whether a `core/list` block is ordered.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	final protected function is_ordered_list( array $block ): bool {
		return ! empty( $block['attrs']['ordered'] );
	}

	/**
	 * Plain-text list items for a `core/list` block.
	 *
	 * Each `core/list-item` inner block becomes one entry. Nested lists
	 * are flattened away (their items are dropped) — the core-block tier
	 * intentionally keeps lists single-level. Empty items are skipped.
	 *
	 * @param array $block Parsed list block.
	 * @return string[]
	 */
	final protected function list_item_texts( array $block ): array {
		$items = array();

		foreach ( $block['innerBlocks'] ?? array() as $inner ) {
			if ( 'core/list-item' !== ( $inner['blockName'] ?? '' ) ) {
				continue;
			}

			$text = $this->block_plaintext( $inner );
			if ( '' !== $text ) {
				$items[] = $text;
			}
		}

		return $items;
	}

	/**
	 * Attachment ID referenced by a `core/image` block, if any.
	 *
	 * @param array $block Parsed block.
	 * @return int|null
	 */
	final protected function image_attachment_id( array $block ): ?int {
		$id = (int) ( $block['attrs']['id'] ?? 0 );

		return $id > 0 ? $id : null;
	}

	/**
	 * Canonical alt text for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	final protected function image_alt_text( int $attachment_id ): string {
		$alt = \get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return \is_string( $alt ) ? $alt : '';
	}

	/**
	 * Integer {width,height} aspect ratio for an attachment, or null.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{width:int,height:int}|null
	 */
	final protected function image_aspect_ratio( int $attachment_id ): ?array {
		return Post::get_attachment_aspect_ratio( $attachment_id );
	}

	/**
	 * Whether saved block/classic content is still present after rendering.
	 *
	 * Structured parsers read the saved block tree directly. That is useful
	 * for preserving block semantics, but it can bypass membership,
	 * visibility, shortcode, or render-time filters that remove content
	 * from the public page. Parsers that consume saved markup should use
	 * this as an applies_to() guard and fall back to rendered HTML when it
	 * returns false.
	 *
	 * @param \WP_Post $post The WordPress post object.
	 * @return bool
	 */
	final protected function saved_content_survives_rendering( \WP_Post $post ): bool {
		$rendered_html = $this->get_rendered_html( $post );

		if ( '' === \trim( $rendered_html ) ) {
			return false;
		}

		$rendered_text = self::normalize_visibility_text( \wp_strip_all_tags( $rendered_html ) );

		if ( ! $this->has_blocks( $post ) ) {
			$saved_text = self::normalize_visibility_text( \wp_strip_all_tags( $post->post_content ) );

			return '' === $saved_text || \str_contains( $rendered_text, $saved_text );
		}

		$visible = true;

		$this->walk_blocks(
			$this->get_blocks( $post ),
			function ( array $block ) use ( &$visible, $rendered_html, $rendered_text ): void {
				if ( ! $visible ) {
					return;
				}

				$name = (string) ( $block['blockName'] ?? '' );

				if ( 'core/image' === $name ) {
					$attachment_id = $this->image_attachment_id( $block );
					if ( null !== $attachment_id && ! $this->rendered_html_contains_image( $rendered_html, $attachment_id ) ) {
						$visible = false;
					}
					return;
				}

				if ( ! \in_array(
					$name,
					array(
						'',
						'core/paragraph',
						'core/heading',
						'core/list-item',
						'core/quote',
						'core/pullquote',
						'core/code',
						'core/preformatted',
					),
					true
				) ) {
					return;
				}

				$text = self::normalize_visibility_text( $this->block_plaintext( $block ) );
				if ( '' !== $text && ! \str_contains( $rendered_text, $text ) ) {
					$visible = false;
				}
			}
		);

		return $visible;
	}

	/**
	 * Whether rendered HTML still contains an attachment image block.
	 *
	 * @param string $html          Rendered HTML.
	 * @param int    $attachment_id Attachment ID.
	 * @return bool
	 */
	private function rendered_html_contains_image( string $html, int $attachment_id ): bool {
		if ( \str_contains( $html, 'wp-image-' . $attachment_id ) ) {
			return true;
		}

		$url = (string) \wp_get_attachment_url( $attachment_id );

		return '' !== $url && \str_contains( $html, $url );
	}

	/**
	 * Normalize text before comparing saved content with rendered output.
	 *
	 * @param string $text Text to normalize.
	 * @return string
	 */
	private static function normalize_visibility_text( string $text ): string {
		$text      = \html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$collapsed = \preg_replace( '/\s+/u', ' ', $text );

		/*
		 * preg_replace returns null on a PCRE error (e.g. invalid UTF-8
		 * under the /u flag). Keep the pre-collapse text rather than
		 * blanking it, which would skew the saved-content comparison.
		 */
		return \trim( null === $collapsed ? $text : $collapsed );
	}
}
