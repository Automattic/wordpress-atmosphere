<?php
/**
 * Block-editor asset loading.
 *
 * Enqueues the ATmosphere block-editor panels: the document-sidebar share
 * toggle and the pre-publish federation panel. Kept separate from the REST
 * controller that feeds the pre-publish panel — this class owns only the
 * front-of-editor assets, the controller owns only the data.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Rest\Admin\Pre_Publish_Controller;
use Atmosphere\Transformer\Threadgate;

/**
 * Block-editor integration.
 */
class Block_Editor {

	/**
	 * Built editor scripts, keyed by their `src/`/`build/` directory name.
	 *
	 * @var string[]
	 */
	private const SCRIPTS = array( 'editor-plugin', 'pre-publish-panel' );

	/**
	 * Register the editor asset hook.
	 */
	public static function register(): void {
		\add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueue the editor scripts on supported post types only.
	 */
	public static function enqueue(): void {
		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;

		if ( $screen && ! \in_array( $screen->post_type, get_supported_post_types(), true ) ) {
			return;
		}

		foreach ( self::SCRIPTS as $name ) {
			self::enqueue_script( $name );
		}
	}

	/**
	 * Enqueue one built editor script by its directory name.
	 *
	 * Bails silently when the build output is missing so the plugin still
	 * loads from a source checkout that hasn't run `npm run build`.
	 *
	 * @param string $name The `build/<name>/` directory holding `plugin.js`.
	 */
	private static function enqueue_script( string $name ): void {
		$asset_file = ATMOSPHERE_PLUGIN_DIR . 'build/' . $name . '/plugin.asset.php';

		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset  = include $asset_file;
		$handle = 'atmosphere-' . $name;

		\wp_enqueue_script(
			$handle,
			ATMOSPHERE_PLUGIN_URL . 'build/' . $name . '/plugin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		\wp_set_script_translations( $handle, 'atmosphere' );

		// Single source of truth for values the JS shares with PHP.
		\wp_localize_script( $handle, 'atmosphereEditor', self::script_data() );
	}

	/**
	 * Shared config exposed to the editor scripts as `window.atmosphereEditor`.
	 *
	 * Keeps the REST route and the share-toggle meta key defined once on the
	 * PHP side so the JS never hardcodes (and drifts from) them.
	 *
	 * @return array{previewPath: string, disabledMetaKey: string, customTextMetaKey: string, replyRestrictionMetaKey: string, settingsUrl: string, canManage: bool}
	 */
	private static function script_data(): array {
		return array(
			'previewPath'              => Pre_Publish_Controller::full_route(),
			'disabledMetaKey'          => ATMOSPHERE_META_DISABLED,
			'customTextMetaKey'        => ATMOSPHERE_META_CUSTOM_TEXT,
			'replyRestrictionMetaKey'  => Threadgate::META_RESTRICTION,
			'threadgateNeedsReconnect' => threadgate_needs_reconnect(),
			'settingsUrl'              => settings_url(),

			/*
			 * The settings page needs `manage_options`; authors and
			 * editors see the panel too, so reconnect prompts must not
			 * link them into an authorization error.
			 */
			'canManage'                => \current_user_can( 'manage_options' ),
		);
	}
}
