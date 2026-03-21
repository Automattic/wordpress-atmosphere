<?php
/**
 * Integration loader.
 *
 * Conditionally loads plugin-specific integrations when their
 * target plugin is active. Each integration is a static class
 * with an init() method that registers hooks.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Integrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Integration loader.
 */
class Load {

	/**
	 * Initialize all available integrations.
	 *
	 * Runs on plugins_loaded at priority 20 so all plugins
	 * have registered their constants and classes.
	 */
	public static function init(): void {
		\add_action( 'plugins_loaded', array( self::class, 'register' ), 20 );
	}

	/**
	 * Register integrations whose target plugin is active.
	 */
	public static function register(): void {
		// Integrations are registered here as they are added.
	}
}
