<?php
/**
 * Plugin Name: ATmosphere
 * Plugin URI: https://github.com/pfefferle/atmosphere
 * Description: Publish WordPress posts to AT Protocol (Bluesky + standard.site) via native OAuth.
 * Version: 0.1.0
 * Author: Automattic
 * Author URI: https://automattic.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: atmosphere
 * Requires PHP: 8.1
 * Requires at least: 6.2
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

\define( 'ATMOSPHERE_VERSION', '0.1.0' );
\define( 'ATMOSPHERE_PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
\define( 'ATMOSPHERE_PLUGIN_URL', \plugin_dir_url( __FILE__ ) );
\define( 'ATMOSPHERE_PLUGIN_FILE', __FILE__ );

// Composer autoloader (classes).
if ( \file_exists( ATMOSPHERE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once ATMOSPHERE_PLUGIN_DIR . 'vendor/autoload.php';
}

// Helper functions (loaded after ABSPATH is available).
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
	\flush_rewrite_rules();
}
\register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );
