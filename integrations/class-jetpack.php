<?php
/**
 * Jetpack paid-content integration.
 *
 * Teaches ATmosphere about Jetpack's subscriber/paid-content gating so gated
 * post bodies never reach a public AT Protocol record. Everything here is
 * visitor-independent: it reads a post's stored access-level meta and block
 * markup, never the current user, cookies, or an unlock session — the gate
 * must return the same answer during an author save, a WP-Cron run, or a
 * logged-out request.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Integrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Strips Jetpack-gated content from the publishable body.
 */
class Jetpack {

	/**
	 * Post meta holding the whole-post access level.
	 *
	 * Mirrors Jetpack's `_jetpack_newsletter_access` (the stored DB key, stable
	 * across Jetpack versions). Any value other than '' / 'everybody' means the
	 * whole post is gated.
	 *
	 * @var string
	 */
	private const ACCESS_META = '_jetpack_newsletter_access';

	/**
	 * The access level that means "readable by everybody".
	 *
	 * @var string
	 */
	private const ACCESS_EVERYBODY = 'everybody';

	/**
	 * The split-point block. Content above it is public; below it is gated.
	 *
	 * @var string
	 */
	private const PAYWALL_BLOCK = 'jetpack/paywall';

	/**
	 * The inline block whose content is subscriber-only.
	 *
	 * @var string
	 */
	private const SUBSCRIBER_VIEW_BLOCK = 'premium-content/subscriber-view';

	/**
	 * Register the publishable-content filter.
	 */
	public static function init(): void {
		\add_filter( 'atmosphere_publishable_content', array( self::class, 'filter_publishable_content' ), 10, 2 );
	}

	/**
	 * Return only the publicly readable portion of a post's content.
	 *
	 * Handles Jetpack's three gating mechanisms, all fail-closed:
	 *
	 *  1. Whole-post — `_jetpack_newsletter_access` is a non-public level and
	 *     there is no split point, so nothing federates.
	 *  2. Split-point — a `jetpack/paywall` block; only the content above it is
	 *     public.
	 *  3. Inline — a `premium-content/container`; the `subscriber-view` region
	 *     is removed and the rest kept.
	 *
	 * @param string   $content The stored post content.
	 * @param \WP_Post $post    The post being published.
	 * @return string Publicly readable content ('' when fully gated).
	 */
	public static function filter_publishable_content( string $content, \WP_Post $post ): string {
		// Name-based detection is robust to block attributes and whitespace that
		// a serialized-string match would miss.
		$has_split = \has_block( self::PAYWALL_BLOCK, $content );

		// Whole-post gate: a non-public access level with no split point means
		// the entire body is subscriber-only.
		if ( ! $has_split && ! self::is_access_level_public( $post ) ) {
			return '';
		}

		// Split-point: keep only the content above the paywall block. Jetpack
		// treats content above the block as public regardless of the post's
		// access level. Fail closed if the block is present but not at the top
		// level (an unsupported nesting we cannot safely split).
		if ( $has_split ) {
			$above = self::content_above_paywall( $content );
			if ( null === $above ) {
				return '';
			}
			$content = $above;
		}

		// Inline: drop any subscriber-only regions from what remains.
		return self::strip_subscriber_view_blocks( $content );
	}

	/**
	 * Content above the first top-level `jetpack/paywall` block.
	 *
	 * @param string $content Serialized block content.
	 * @return string|null Content above the split, or null when no top-level
	 *                     paywall block is found (caller fails closed).
	 */
	private static function content_above_paywall( string $content ): ?string {
		$above = array();

		foreach ( \parse_blocks( $content ) as $block ) {
			if ( self::PAYWALL_BLOCK === ( $block['blockName'] ?? '' ) ) {
				return \serialize_blocks( $above );
			}

			$above[] = $block;
		}

		return null;
	}

	/**
	 * Whether the post's whole-post access level is readable by everybody.
	 *
	 * Prefers Jetpack's canonical accessor when present (it caches and coerces
	 * the value); otherwise reads the stored meta directly so the check works
	 * in tests and on sites where the class is not loaded. Fails closed: any
	 * value other than '' / 'everybody' is treated as gated.
	 *
	 * @param \WP_Post $post The post being checked.
	 * @return bool
	 */
	private static function is_access_level_public( \WP_Post $post ): bool {
		if (
			\class_exists( 'Jetpack_Memberships' )
			&& \method_exists( 'Jetpack_Memberships', 'get_post_access_level' )
		) {
			$level = (string) \Jetpack_Memberships::get_post_access_level( $post->ID );
		} else {
			$level = (string) \get_post_meta( $post->ID, self::ACCESS_META, true );
		}

		return '' === $level || self::ACCESS_EVERYBODY === $level;
	}

	/**
	 * Remove `premium-content/subscriber-view` blocks from serialized content.
	 *
	 * Walks the parsed block tree and drops every subscriber-view subtree, then
	 * re-serializes. The public `logged-out-view` teaser and any surrounding
	 * blocks are preserved.
	 *
	 * @param string $content Serialized block content.
	 * @return string Content with subscriber-only regions removed.
	 */
	private static function strip_subscriber_view_blocks( string $content ): string {
		if ( '' === $content || ! \has_block( self::SUBSCRIBER_VIEW_BLOCK, $content ) ) {
			return $content;
		}

		$blocks = self::remove_blocks_by_name( \parse_blocks( $content ), self::SUBSCRIBER_VIEW_BLOCK );

		return \serialize_blocks( $blocks );
	}

	/**
	 * Filter a block list, dropping top-level blocks with the target name and
	 * recursing into the inner blocks of the rest.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $target Block name to remove.
	 * @return array Filtered blocks.
	 */
	private static function remove_blocks_by_name( array $blocks, string $target ): array {
		$result = array();

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';
			if ( $target === $block_name ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block = self::remove_child_blocks( $block, $target );
			}

			$result[] = $block;
		}

		return $result;
	}

	/**
	 * Remove matching inner blocks from a single block.
	 *
	 * `innerContent` interleaves literal HTML chunks (strings) with `null`
	 * placeholders, one per inner block in order. Dropping an inner block means
	 * dropping its placeholder too, or serialization would misalign.
	 *
	 * @param array  $block  A parsed block with inner blocks.
	 * @param string $target Block name to remove.
	 * @return array The block with matching inner blocks removed.
	 */
	private static function remove_child_blocks( array $block, string $target ): array {
		$inner_blocks  = array();
		$inner_content = array();
		$child_index   = 0;

		foreach ( $block['innerContent'] as $chunk ) {
			if ( null !== $chunk ) {
				$inner_content[] = $chunk;
				continue;
			}

			$child = $block['innerBlocks'][ $child_index ] ?? null;
			++$child_index;

			if ( null === $child ) {
				continue;
			}

			$child_name = $child['blockName'] ?? '';
			if ( $target === $child_name ) {
				continue;
			}

			if ( ! empty( $child['innerBlocks'] ) ) {
				$child = self::remove_child_blocks( $child, $target );
			}

			$inner_blocks[]  = $child;
			$inner_content[] = null;
		}

		$block['innerBlocks']  = $inner_blocks;
		$block['innerContent'] = $inner_content;

		return $block;
	}
}
