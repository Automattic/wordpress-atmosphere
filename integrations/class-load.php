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
		// Jetpack paid-content gating: keep subscriber-only bodies out of
		// public AT Protocol records. The membership feature ships in more than
		// one shape, so check every signal that it could be present:
		// - JETPACK__VERSION: the Jetpack plugin (self-hosted, Atomic).
		// - IS_WPCOM: WordPress.com Simple, where jetpack-mu-wpcom provides the
		// gating blocks without ever defining JETPACK__VERSION.
		// - Jetpack_Memberships: the class itself, when already loaded.
		// Missing any of these once left the filter unregistered on Simple
		// sites, federating gated posts in full. Detection stays coarse on
		// purpose; the filter itself is cheap and fails closed, and the
		// integration resolves the real access level at publish time.
		if (
			\defined( 'JETPACK__VERSION' )
			|| \defined( 'IS_WPCOM' )
			|| \class_exists( 'Jetpack_Memberships' )
		) {
			Jetpack::init();
		}
	}
}
