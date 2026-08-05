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
use function Atmosphere\is_auto_publish_enabled;
use function Atmosphere\reconnect_url;

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

		/*
		 * The document panel is the cross-posting UI itself, so it stays out
		 * of the way when the site does not cross-post. The pre-publish
		 * panel's whole job is to answer whether this post will be shared,
		 * and "automatic publishing is turned off" is that answer — so it
		 * keeps loading either way.
		 */
		$auto_publish_enabled = is_auto_publish_enabled();

		foreach ( self::SCRIPTS as $name ) {
			if ( 'editor-plugin' === $name && ! $auto_publish_enabled ) {
				continue;
			}

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
	 * @return array{previewPath: string, disabledMetaKey: string, customTextMetaKey: string, settingsUrl: string, reconnectUrl: string, canManage: bool, needsReauth: bool, reauthLead: string}
	 */
	private static function script_data(): array {
		/*
		 * The settings page needs `manage_options`; authors and
		 * editors see the panel too, so reconnect prompts must not
		 * link them into an authorization error.
		 */
		$can_manage = \current_user_can( 'manage_options' );

		$needs_reauth = needs_reauth();

		/*
		 * An operator-initiated disconnect is a state the administrator
		 * chose, not a problem for every author to worry about — a
		 * non-admin gets no persistent warning about it. Administrators
		 * still see it so they can reconnect.
		 */
		if ( $needs_reauth && ! $can_manage && is_operator_disconnected() ) {
			$needs_reauth = false;
		}

		return array(
			'previewPath'       => Pre_Publish_Controller::full_route(),
			'disabledMetaKey'   => ATMOSPHERE_META_DISABLED,
			'customTextMetaKey' => ATMOSPHERE_META_CUSTOM_TEXT,
			'settingsUrl'       => settings_url(),
			'reconnectUrl'      => reconnect_url(),
			'canManage'         => $can_manage,
			'needsReauth'       => $needs_reauth,
			'reauthLead'        => $needs_reauth ? reauth_lead_for_current_user() : '',
		);
	}
}
