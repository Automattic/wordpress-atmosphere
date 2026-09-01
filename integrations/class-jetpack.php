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
	 * Stack of post IDs currently inside a publishable render.
	 *
	 * The paywall is only stepped aside for these posts: their bodies were
	 * already narrowed by {@see self::filter_publishable_content()}. Any
	 * other post rendered while the stack is non-empty (a query loop or
	 * shortcode rendering a different post inline) keeps its paywall.
	 *
	 * @var int[]
	 */
	private static array $suspended_posts = array();

	/**
	 * Paywall callbacks swapped out for the duration of the suspension.
	 *
	 * Each entry records the original callback, the priority it was hooked
	 * at, and the scoped wrapper standing in for it, so
	 * {@see self::restore_paywall_filter()} can put everything back exactly
	 * as found.
	 *
	 * @var array<int,array{function:string,priority:int,wrapper:\Closure}>
	 */
	private static array $suspended_callbacks = array();

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

		// The publishable-content memo keys on post ID + content hash, but our
		// output also turns on the access level, which lives in meta (or an
		// unsaved preview override) and never touches the stored content. Fold it
		// into the key so each gating decision gets its own slot.
		\add_filter( 'atmosphere_publishable_content_cache_key', array( self::class, 'vary_cache_key' ), 10, 2 );
	}

	/**
	 * Capture the unsaved access level for the duration of a preview projection.
	 *
	 * Only overrides when the request carries a non-empty `accessLevel`. The
	 * param registers a `''` default, and `WP_REST_Request::has_param()` counts
	 * defaults once the request is dispatched, so `has_param()` is true on every
	 * request — including one that never sent the level (an older cached editor
	 * build, or a post type whose access meta the editor does not expose). A
	 * blank override would read as "everybody" and make the preview claim a
	 * saved-gated post is public, then publish only a teaser. Treating blank as
	 * "not provided" falls back to the saved access level, which fails closed.
	 *
	 * @param \WP_Post $post    The projected draft (keeps the real post ID).
	 * @param mixed    $request The REST request driving the preview.
	 */
	public static function set_access_override( \WP_Post $post, $request ): void {
		if ( $request instanceof \WP_REST_Request && '' !== (string) $request['accessLevel'] ) {
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
	 * Fold the post's access level into the publishable-content memo key.
	 *
	 * The default key covers only the stored content, but our output also turns
	 * on the whole-post access level, which lives in meta (or, during a preview,
	 * in an unsaved override) and never touches `post_content`. Left out of the
	 * key, two different gating decisions for the same post + content would share
	 * a cache slot: an unsaved override would mask the saved post, and changing
	 * the saved access level mid-request would return the earlier, more
	 * permissive answer. Appends the effective level, resolved by
	 * {@see self::effective_access_level()} — the same source the gating
	 * decision itself reads — so the key can never diverge from the decision
	 * it caches.
	 *
	 * @param string   $key  The default cache key (post ID + content hash).
	 * @param \WP_Post $post The post being published.
	 * @return string The key, varied by the effective access level.
	 */
	public static function vary_cache_key( string $key, \WP_Post $post ): string {
		return $key . ':access=' . self::effective_access_level( $post );
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
	 * Step Jetpack's `the_content` paywall aside for the post being rendered.
	 *
	 * Fired from `atmosphere_pre_render_publishable_content`. ATmosphere hands
	 * `the_content` a body already narrowed by {@see
	 * self::filter_publishable_content()}, but Jetpack's own paywall callback
	 * (see {@see self::PAYWALL_CONTENT_FILTER}) still runs against the global
	 * post and, finding no visible viewer, would replace that body with a
	 * subscribe form.
	 *
	 * The suspension is scoped, not global: each matched paywall callback is
	 * swapped for a wrapper that skips the paywall only when the post under
	 * render is one ATmosphere narrowed (the render seam sets the global
	 * post; see Parser_Base::get_rendered_html()). A *different* post
	 * rendered inline — a query loop or shortcode embedding another, gated
	 * post — keeps its paywall, so its gated body cannot surface in the
	 * record.
	 *
	 * Matching covers any string callback named `add_paywall`, at any
	 * priority, so a renamed namespace or a WordPress.com variant is caught
	 * too. A paywall hooked as a closure is not detectable and stays active —
	 * which fails closed: its subscribe form would replace the narrowed body,
	 * never reveal the gated one.
	 *
	 * @param \WP_Post $post The post about to be rendered.
	 */
	public static function suspend_paywall_filter( \WP_Post $post ): void {
		self::$suspended_posts[] = $post->ID;

		if ( 1 !== \count( self::$suspended_posts ) ) {
			return;
		}

		$hook = $GLOBALS['wp_filter']['the_content'] ?? null;

		if ( ! $hook instanceof \WP_Hook ) {
			return;
		}

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$function = $entry['function'] ?? null;

				if ( ! \is_string( $function )
					|| ! \str_ends_with( \strtolower( $function ), 'add_paywall' )
				) {
					continue;
				}

				$wrapper = static function ( $content ) use ( $function ) {
					$post_id = ( $GLOBALS['post'] ?? null ) instanceof \WP_Post ? (int) $GLOBALS['post']->ID : 0;

					if ( \in_array( $post_id, self::$suspended_posts, true ) ) {
						return $content;
					}

					return \is_callable( $function ) ? $function( $content ) : $content;
				};

				\remove_filter( 'the_content', $function, (int) $priority );
				\add_filter( 'the_content', $wrapper, (int) $priority );

				self::$suspended_callbacks[] = array(
					'function' => $function,
					'priority' => (int) $priority,
					'wrapper'  => $wrapper,
				);
			}
		}
	}

	/**
	 * Re-hook Jetpack's `the_content` paywall after ATmosphere renders.
	 *
	 * Fired from `atmosphere_post_render_publishable_content`; the mirror of
	 * {@see self::suspend_paywall_filter()}. Pops the rendered post off the
	 * stack and, once the outermost render finishes, swaps every wrapper
	 * back for the original callback at its original priority.
	 */
	public static function restore_paywall_filter(): void {
		if ( ! empty( self::$suspended_posts ) ) {
			\array_pop( self::$suspended_posts );
		}

		if ( ! empty( self::$suspended_posts ) ) {
			return;
		}

		foreach ( self::$suspended_callbacks as $entry ) {
			\remove_filter( 'the_content', $entry['wrapper'], $entry['priority'] );
			\add_filter( 'the_content', $entry['function'], $entry['priority'] );
		}

		self::$suspended_callbacks = array();
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
	 * Reads {@see self::effective_access_level()}. Fails closed: any value
	 * other than '' / 'everybody' is treated as gated.
	 *
	 * @param \WP_Post $post The post being checked.
	 * @return bool
	 */
	private static function is_access_level_public( \WP_Post $post ): bool {
		$level = self::effective_access_level( $post );

		return '' === $level || self::ACCESS_EVERYBODY === $level;
	}

	/**
	 * The post's effective whole-post access level.
	 *
	 * Resolution order: the preview's unsaved override (it reflects the
	 * editor's latest choice, so it wins over any memoized lookup), then
	 * Jetpack's canonical accessor when present (it caches and coerces the
	 * value), then the stored meta so the check works in tests and on sites
	 * where the class is not loaded.
	 *
	 * The single resolver feeds both the gating decision
	 * ({@see self::is_access_level_public()}) and the memo key
	 * ({@see self::vary_cache_key()}): reading two different sources there
	 * could cache a full body under a gated-labeled slot when the sources
	 * disagree mid-request.
	 *
	 * @param \WP_Post $post The post being checked.
	 * @return string The access level ('' when none is stored).
	 */
	private static function effective_access_level( \WP_Post $post ): string {
		if ( isset( self::$access_override[ $post->ID ] ) ) {
			return self::$access_override[ $post->ID ];
		}

		if (
			\class_exists( 'Jetpack_Memberships' )
			&& \method_exists( 'Jetpack_Memberships', 'get_post_access_level' )
		) {
			return (string) \Jetpack_Memberships::get_post_access_level( $post->ID );
		}

		return (string) \get_post_meta( $post->ID, self::ACCESS_META, true );
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
