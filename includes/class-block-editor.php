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
use function Atmosphere\share_status;
use function Atmosphere\verify_connection;
use function Atmosphere\settings_url;
use function Atmosphere\threadgate_needs_reconnect;
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
		 * Built once and shared by both scripts. Recomputing per script
		 * would run the whole share decision, the reconnect-URL resolution
		 * and a capability check twice for an identical result.
		 */
		$data = self::script_data();

		foreach ( self::SCRIPTS as $name ) {
			self::enqueue_script( $name, $data );
		}
	}

	/**
	 * Enqueue one built editor script by its directory name.
	 *
	 * Bails silently when the build output is missing so the plugin still
	 * loads from a source checkout that hasn't run `npm run build`.
	 *
	 * @param string $name The `build/<name>/` directory holding `plugin.js`.
	 * @param array  $data Shared config to localize onto the script.
	 */
	private static function enqueue_script( string $name, array $data ): void {
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
		\wp_localize_script( $handle, 'atmosphereEditor', $data );
	}

	/**
	 * Shared config exposed to the editor scripts as `window.atmosphereEditor`.
	 *
	 * Keeps the REST route and the share-toggle meta key defined once on the
	 * PHP side so the JS never hardcodes (and drifts from) them.
	 *
	 * @return array{previewPath: string, disabledMetaKey: string, customTextMetaKey: string, replyRestrictionMetaKey: string, threadgateNeedsReconnect: bool, settingsUrl: string, reconnectUrl: string, canManage: bool, shareStatus: array}
	 */
	private static function script_data(): array {
		/*
		 * The settings page needs `manage_options`; authors and
		 * editors see the panel too, so reconnect prompts must not
		 * link them into an authorization error.
		 */
		$can_manage = \current_user_can( 'manage_options' );

		/*
		 * Confirm the session with the PDS before resolving the share
		 * status below. This is the earliest useful moment: the author has
		 * the editor open and has not written anything yet, so a dead
		 * connection surfaces as a reconnect prompt they can act on before
		 * investing a post in it — rather than after clicking Publish.
		 *
		 * A revoked refresh token is indistinguishable from a working one
		 * in local state, so `share_status()` alone cannot see it. The
		 * probe records nothing of its own; it lets a dead session travel
		 * the ordinary refresh path into `needs_reauth`, which the existing
		 * banner already renders.
		 *
		 * Not gated on whether sharing is switched on: the connection is
		 * shared infrastructure, and the reconnect prompt is connection
		 * level rather than cross-posting level. Cached site-wide, and
		 * bounded by a short timeout, so a slow PDS cannot hold up the
		 * editor — the probe fails open and the banner simply keeps
		 * whatever the last verdict was.
		 */
		verify_connection();

		/*
		 * One decision object rather than a set of loose flags. The panel
		 * renders what {@see \Atmosphere\share_status()} decided instead of
		 * re-deriving it in JavaScript, which is how the two editor surfaces
		 * used to end up contradicting each other.
		 */
		return array(
			'previewPath'              => Pre_Publish_Controller::full_route(),
			'disabledMetaKey'          => ATMOSPHERE_META_DISABLED,
			'customTextMetaKey'        => ATMOSPHERE_META_CUSTOM_TEXT,
			'replyRestrictionMetaKey'  => Threadgate::META_RESTRICTION,
			'threadgateNeedsReconnect' => threadgate_needs_reconnect(),
			'settingsUrl'              => settings_url(),
			'reconnectUrl'             => reconnect_url(),
			'canManage'                => $can_manage,
			'shareStatus'              => share_status(),
		);
	}
}
