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
	 * Jetpack's `the_content` paywall callback.
	 *
	 * Jetpack hooks this at priority 8 to replace a gated post's rendered body
	 * with a "subscribe to keep reading" form whenever the current request
	 * cannot view the post — which is always true in the logged-out WP-Cron
	 * context we publish from. Left in place, it would overwrite the already
	 * narrowed body we hand to `the_content` with that form. {@see
	 * self::suspend_paywall_filter()} unhooks it around our own renders.
	 *
	 * @var string
	 */
	private const PAYWALL_CONTENT_FILTER = 'Automattic\Jetpack\Extensions\Subscriptions\add_paywall';

	/**
	 * Nesting depth of the current publishable-render suspension.
	 *
	 * @var int
	 */
	private static int $suspend_depth = 0;

	/**
	 * Priority the paywall filter was attached at before suspension, or null
	 * when it was not attached.
	 *
	 * @var int|null
	 */
	private static ?int $suspended_priority = null;

	/**
	 * Per-post access-level override, keyed by post ID.
	 *
	 * Set while the pre-publish preview projects a draft, so gating tracks the
	 * access level the author has chosen in the editor but not yet saved
	 * (the meta this normally reads is only written on save). Empty outside a
	 * projection.
	 *
	 * @var array<int,string>
	 */
	private static array $access_override = array();

	/**
	 * Register the publishable-content filter and render seam.
	 */
	public static function init(): void {
		\add_filter( 'atmosphere_publishable_content', array( self::class, 'filter_publishable_content' ), 10, 2 );

		// Neutralise Jetpack's own `the_content` paywall while ATmosphere
		// renders the already-narrowed body (see PAYWALL_CONTENT_FILTER).
		\add_action( 'atmosphere_pre_render_publishable_content', array( self::class, 'suspend_paywall_filter' ) );
		\add_action( 'atmosphere_post_render_publishable_content', array( self::class, 'restore_paywall_filter' ) );

		// Reflect the editor's unsaved access level during pre-publish preview.
		\add_action( 'atmosphere_pre_projection', array( self::class, 'set_access_override' ), 10, 2 );
		\add_action( 'atmosphere_post_projection', array( self::class, 'clear_access_override' ) );
	}

	/**
	 * Capture the unsaved access level for the duration of a preview projection.
	 *
	 * @param \WP_Post $post    The projected draft (keeps the real post ID).
	 * @param mixed    $request The REST request driving the preview.
	 */
	public static function set_access_override( \WP_Post $post, $request ): void {
		if ( $request instanceof \WP_REST_Request && $request->has_param( 'accessLevel' ) ) {
			self::$access_override[ $post->ID ] = (string) $request['accessLevel'];
		}
	}

	/**
	 * Drop the access-level override once the projection is done.
	 *
	 * @param \WP_Post $post The projected draft.
	 */
	public static function clear_access_override( \WP_Post $post ): void {
		unset( self::$access_override[ $post->ID ] );
	}

	/**
	 * Return only the publicly readable portion of a post's content.
	 *
	 * Handles Jetpack's three gating mechanisms, all fail-closed:
	 *
	 *  1. Whole-post — `_jetpack_newsletter_access` is a non-public level and
	 *     there is no split point, so nothing federates.
	 *  2. Split-point — a `jetpack/paywall` block on a gated post; only the
	 *     content above it is public.
	 *  3. Inline — a `premium-content/container`; the `subscriber-view` region
	 *     is removed and the rest kept regardless of the post's access level.
	 *
	 * The whole-post and split gates both key off the stored access level, not
	 * the mere presence of a paywall block: Jetpack renders the entire post to
	 * everyone when the access level is empty / `everybody`, and the block's own
	 * `parent` restriction is only an editor hint (imports, WP-CLI, migrated
	 * content, and the REST API can all leave a stray or nested block behind).
	 * Reading the access level keeps a public post from being wrongly truncated.
	 *
	 * @param string   $content The stored post content.
	 * @param \WP_Post $post    The post being published.
	 * @return string Publicly readable content ('' when fully gated).
	 */
	public static function filter_publishable_content( string $content, \WP_Post $post ): string {
		if ( '' === $content ) {
			return $content;
		}

		// Parse the block tree once and reuse it for every check below;
		// detection, splitting, and stripping all read from the same tree
		// rather than re-parsing the content each time.
		$blocks = \parse_blocks( $content );

		$is_public = self::is_access_level_public( $post );

		// Name-based detection is robust to block attributes and whitespace that
		// a serialized-string match would miss. Detection is depth-aware so a
		// nested marker is never mistaken for its absence.
		$has_split      = self::blocks_contain( $blocks, self::PAYWALL_BLOCK );
		$has_subscriber = self::blocks_contain( $blocks, self::SUBSCRIBER_VIEW_BLOCK );

		// Whole-post gate: a non-public access level with no split point means
		// the entire body is subscriber-only.
		if ( ! $is_public && ! $has_split ) {
			return '';
		}

		$split_applied = false;

		// Split-point: keep only the content above the paywall block. The block
		// gates the content below it only on a genuinely gated post; on a public
		// post it is inert, so we leave that post whole. Fail closed if the block
		// is present but nested where we cannot safely locate the split.
		if ( $has_split && ! $is_public ) {
			$above = self::blocks_above_paywall( $blocks );
			if ( null === $above ) {
				return '';
			}
			$blocks         = $above;
			$split_applied  = true;
			$has_subscriber = self::blocks_contain( $blocks, self::SUBSCRIBER_VIEW_BLOCK );
		}

		// Inline paid-content regions gate independently of the post's access
		// level, so strip them whether or not the post is otherwise public.
		if ( $has_subscriber ) {
			$blocks = self::remove_blocks_by_name( $blocks, self::SUBSCRIBER_VIEW_BLOCK );

			return \serialize_blocks( $blocks );
		}

		// No split performed and nothing inline to strip: publish the stored
		// content verbatim, without a serialize round-trip that could reflow the
		// markup of an unchanged, fully public post.
		if ( ! $split_applied ) {
			return $content;
		}

		return \serialize_blocks( $blocks );
	}

	/**
	 * Unhook Jetpack's `the_content` paywall while ATmosphere renders.
	 *
	 * Fired from `atmosphere_pre_render_publishable_content`. ATmosphere hands
	 * `the_content` a body already narrowed by {@see
	 * self::filter_publishable_content()}, but Jetpack's own paywall callback
	 * (see {@see self::PAYWALL_CONTENT_FILTER}) still runs against the original
	 * global post and, finding no visible viewer, would replace that body with a
	 * subscribe form. Remembering its priority so
	 * {@see self::restore_paywall_filter()} can put it back exactly as it was.
	 * Depth-counted so nested renders suspend once and restore once.
	 */
	public static function suspend_paywall_filter(): void {
		if ( 0 === self::$suspend_depth ) {
			$priority                 = \has_filter( 'the_content', self::PAYWALL_CONTENT_FILTER );
			self::$suspended_priority = ( false === $priority ) ? null : (int) $priority;

			if ( null !== self::$suspended_priority ) {
				\remove_filter( 'the_content', self::PAYWALL_CONTENT_FILTER, self::$suspended_priority );
			}
		}

		++self::$suspend_depth;
	}

	/**
	 * Re-hook Jetpack's `the_content` paywall after ATmosphere renders.
	 *
	 * Fired from `atmosphere_post_render_publishable_content`; the mirror of
	 * {@see self::suspend_paywall_filter()}.
	 */
	public static function restore_paywall_filter(): void {
		if ( self::$suspend_depth > 0 ) {
			--self::$suspend_depth;
		}

		if ( 0 === self::$suspend_depth && null !== self::$suspended_priority ) {
			\add_filter( 'the_content', self::PAYWALL_CONTENT_FILTER, self::$suspended_priority );
			self::$suspended_priority = null;
		}
	}

	/**
	 * Whether a parsed block tree contains a block with the given name at any
	 * depth.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $name   Block name to look for.
	 * @return bool
	 */
	private static function blocks_contain( array $blocks, string $name ): bool {
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';
			if ( $name === $block_name ) {
				return true;
			}

			if ( ! empty( $block['innerBlocks'] ) && self::blocks_contain( $block['innerBlocks'], $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Blocks above the first top-level `jetpack/paywall` block.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array|null Blocks above the split, or null when no top-level
	 *                    paywall block is found (caller fails closed).
	 */
	private static function blocks_above_paywall( array $blocks ): ?array {
		$above = array();

		foreach ( $blocks as $block ) {
			if ( self::PAYWALL_BLOCK === ( $block['blockName'] ?? '' ) ) {
				return $above;
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
		if ( isset( self::$access_override[ $post->ID ] ) ) {
			// Pre-publish preview: use the editor's unsaved access level. Applied
			// last so it wins over Jetpack's memoized save-time lookup below.
			$level = self::$access_override[ $post->ID ];
		} elseif (
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
