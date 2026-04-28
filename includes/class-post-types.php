<?php
/**
 * Post type support resolution and sanitization.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * Post Types class.
 */
class Post_Types {

	/**
	 * Resolve the full set of supported post types.
	 *
	 * Combines the configured option with anything third parties opted
	 * in via `\add_post_type_support( $post_type, 'atmosphere' )`.
	 *
	 * @return string[]
	 */
	public static function get_supported(): array {
		$configured = (array) \get_option( 'atmosphere_support_post_types', array( 'post' ) );
		$native     = \get_post_types_by_support( 'atmosphere' );

		$post_types = \array_merge( $configured, $native );

		/**
		 * Filters the post types that support ATmosphere publishing.
		 *
		 * Runs after the option and native `add_post_type_support()`
		 * opt-ins are merged, so plugins can add or remove either source.
		 *
		 * @param string[] $post_types Post type slugs.
		 */
		$post_types = (array) \apply_filters( 'atmosphere_syncable_post_types', $post_types );

		// Normalise: drop empties / non-strings, dedupe, re-index.
		$post_types = \array_filter( $post_types, '\is_string' );
		$post_types = \array_map( 'sanitize_key', $post_types );
		$post_types = \array_filter( $post_types );

		return \array_values( \array_unique( $post_types ) );
	}

	/**
	 * Whether a post type is supported.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function supports( string $post_type ): bool {
		return \in_array( $post_type, self::get_supported(), true );
	}

	/**
	 * Sanitize the option on save.
	 *
	 * Drops unknown or non-public post types and coerces empty input
	 * (e.g. all checkboxes unchecked) to an empty array.
	 *
	 * @param mixed $value Submitted value.
	 * @return string[]
	 */
	public static function sanitize( $value ): array {
		if ( empty( $value ) ) {
			return array();
		}

		$allowed = \get_post_types( array( 'public' => true ) );
		$value   = \array_map( 'sanitize_text_field', (array) $value );

		return \array_values( \array_intersect( $value, $allowed ) );
	}
}
