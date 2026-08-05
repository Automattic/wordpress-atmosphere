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
	 * @return array{previewPath: string, disabledMetaKey: string, customTextMetaKey: string, settingsUrl: string, canManage: bool, needsReauth: bool, reauthLead: string}
	 */
	private static function script_data(): array {
		/*
		 * The settings page needs `manage_options`; authors and
		 * editors see the panel too, so reconnect prompts must not
		 * link them into an authorization error.
		 */
		$can_manage = \current_user_can( 'manage_options' );

		return array(
			'previewPath'       => Pre_Publish_Controller::full_route(),
			'disabledMetaKey'   => ATMOSPHERE_META_DISABLED,
			'customTextMetaKey' => ATMOSPHERE_META_CUSTOM_TEXT,
			'settingsUrl'       => settings_url(),
			'canManage'         => $can_manage,
			'needsReauth'       => needs_reauth(),
			'reauthLead'        => self::reauth_lead( $can_manage ),
		);
	}

	/**
	 * Cause sentence for the editor's reconnect warning.
	 *
	 * Reuses {@see \Atmosphere\reauth_reason_lead()} so the editor explains a
	 * dead session in the same words as the admin notice and the Site Health
	 * test, with the same operator-disconnect swap: someone who clicked
	 * Disconnect must not be told their session expired.
	 *
	 * Users without `manage_options` get a generic sentence instead. The
	 * recorded causes talk about rotated security keys and site migrations,
	 * which is noise for an author whose only move is to ask an admin.
	 *
	 * @param bool $can_manage Whether the current user can manage options.
	 * @return string Translated, unescaped sentence. Empty when no reconnect is needed.
	 */
	private static function reauth_lead( bool $can_manage ): string {
		if ( ! needs_reauth() ) {
			return '';
		}

		if ( ! $can_manage ) {
			return \__( 'Your site’s Bluesky connection needs attention.', 'atmosphere' );
		}

		if ( is_operator_disconnected() ) {
			return \__( 'ATmosphere is disconnected from Bluesky.', 'atmosphere' );
		}

		return reauth_reason_lead();
	}
}
