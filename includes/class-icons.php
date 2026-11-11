<?php
/**
 * Icon library registration.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's icons with the WordPress icon library.
 *
 * WordPress 7.1 introduced a site-wide icon library that the Icon block
 * and `wp_get_icon()` read from. The plugin contributes the ATmosphere
 * mark and the Bluesky butterfly, monochrome so the block's own color
 * tools apply, and a site can
 * use them in its own content, a "find me on Bluesky" section for
 * example.
 *
 * The API only exists on 7.1+, and the plugin supports 6.5, so
 * registration is a progressive enhancement behind a `function_exists()`
 * check, the same shape as the Connectors integration. On older
 * WordPress nothing is registered and nothing is loaded.
 *
 * The library's sanitizer only keeps `<svg>`, `<path>` and `<polygon>`
 * elements and strips `stroke`, so shipped SVGs must stay within that
 * allow-list; ours use `<svg>` and `<path>`, monochrome, with the fill
 * inherited so the Icon block's color tools apply.
 *
 * @since 2.2.0
 */
class Icons {

	/**
	 * Icon collection slug.
	 *
	 * @var string
	 */
	public const COLLECTION = 'atmosphere';

	/**
	 * Register the collection and its icons.
	 *
	 * Hooked on `init`, where core registers its own collections.
	 *
	 * @since 2.2.0
	 */
	public static function register(): void {
		if ( ! \function_exists( 'wp_register_icon_collection' ) ) {
			return;
		}

		/*
		 * Core refuses a duplicate registration with `_doing_it_wrong()`,
		 * so a second `init` (tests, site switches) must not reach it.
		 */
		if ( \WP_Icon_Collections_Registry::get_instance()->is_registered( self::COLLECTION ) ) {
			return;
		}

		\wp_register_icon_collection(
			self::COLLECTION,
			array(
				'label'       => \__( 'ATmosphere', 'atmosphere' ),
				'description' => \__( 'The ATmosphere and Bluesky icons.', 'atmosphere' ),
			)
		);

		foreach ( self::icons() as $name => $label ) {
			\wp_register_icon(
				self::COLLECTION . '/' . $name,
				array(
					'label'     => $label,
					'file_path' => ATMOSPHERE_PLUGIN_DIR . 'assets/svg/' . $name . '.svg',
				)
			);
		}
	}

	/**
	 * The icons the plugin ships, as file name => label.
	 *
	 * @since 2.2.0
	 *
	 * @return array<string, string>
	 */
	public static function icons(): array {
		return array(
			'atmosphere' => \__( 'ATmosphere', 'atmosphere' ),
			'bluesky'    => \__( 'Bluesky', 'atmosphere' ),
		);
	}
}
