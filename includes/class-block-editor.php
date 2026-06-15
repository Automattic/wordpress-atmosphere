<?php
/**
 * Block-editor asset loading.
 *
 * Enqueues the pre-publish federation panel in the block editor. Kept
 * separate from the REST controller that feeds the panel: this class owns
 * only the front-of-editor asset, the controller owns only the data.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * Block-editor integration.
 */
class Block_Editor {

	/**
	 * Register the editor asset hook.
	 */
	public static function register(): void {
		\add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueue the pre-publish panel script on supported post types only.
	 */
	public static function enqueue(): void {
		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;

		if ( $screen && ! \in_array( $screen->post_type, get_supported_post_types(), true ) ) {
			return;
		}

		$asset_file = ATMOSPHERE_PLUGIN_DIR . 'build/pre-publish-panel/plugin.asset.php';

		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		\wp_enqueue_script(
			'atmosphere-pre-publish-panel',
			ATMOSPHERE_PLUGIN_URL . 'build/pre-publish-panel/plugin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		\wp_set_script_translations( 'atmosphere-pre-publish-panel', 'atmosphere' );
	}
}
