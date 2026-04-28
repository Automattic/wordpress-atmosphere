<?php
/**
 * Plugin Name: ATmosphere
 * Plugin URI: https://github.com/pfefferle/atmosphere
 * Description: Publish WordPress posts to AT Protocol (Bluesky + standard.site) via native OAuth.
 * Version: unreleased
 * Author: Automattic
 * Author URI: https://automattic.com
 * License: GPL-2.0
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: atmosphere
 * Requires PHP: 8.2
 * Requires at least: 6.2
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

\define( 'ATMOSPHERE_VERSION', 'unreleased' );
\define( 'ATMOSPHERE_PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
\define( 'ATMOSPHERE_PLUGIN_URL', \plugin_dir_url( __FILE__ ) );
\define( 'ATMOSPHERE_PLUGIN_FILE', __FILE__ );

/*
 * Custom autoloader for Atmosphere classes — maps the Atmosphere
 * namespace to includes/ using WordPress filename conventions.
 */
require_once ATMOSPHERE_PLUGIN_DIR . 'includes/class-autoloader.php';

Autoloader::register_path( __NAMESPACE__ . '\Integrations', ATMOSPHERE_PLUGIN_DIR . 'integrations' );
Autoloader::register_path( __NAMESPACE__, ATMOSPHERE_PLUGIN_DIR . 'includes' );

// Helper functions.
require_once ATMOSPHERE_PLUGIN_DIR . 'includes/functions.php';

/**
 * Initialize the plugin.
 */
function init() {
	$atmosphere = new Atmosphere();
	$atmosphere->init();
}
\add_action( 'plugins_loaded', __NAMESPACE__ . '\init' );

/**
 * Activation hook.
 */
function activate() {
	// Generate publication TID on first activation.
	if ( ! \get_option( 'atmosphere_publication_tid' ) ) {
		$tid = Transformer\TID::generate();
		\update_option( 'atmosphere_publication_tid', $tid, false );
	}

	// Flush rewrite rules for client metadata endpoint.
	\flush_rewrite_rules();
}
\register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );

/**
 * Deactivation hook.
 */
function deactivate() {
	\wp_clear_scheduled_hook( 'atmosphere_refresh_token' );
	\wp_clear_scheduled_hook( 'atmosphere_sync_reactions' );
	\wp_clear_scheduled_hook( 'atmosphere_sync_publication' );
	\wp_clear_scheduled_hook( 'atmosphere_delete_records' );
	// Clear the legacy hook name in case an earlier PR-6 build scheduled it.
	\wp_clear_scheduled_hook( 'atmosphere_sync_comments' );
	\flush_rewrite_rules();
}
\register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );
