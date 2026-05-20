<?php
/**
 * Settings API option registration.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers every stored plugin option with the Settings API.
 *
 * Mirrors the convention the ActivityPub plugin uses: hook
 * `register_settings()` on both `admin_init` (so `options.php` form
 * submissions run the registered sanitize callbacks) and
 * `rest_api_init` (so `/wp-json/wp/v2/settings` exposes the registered
 * shape and resolves defaults for REST callers). Without the REST
 * registration, third-party code reading these options through the
 * REST endpoint would see empty values instead of the documented
 * defaults on a fresh install.
 */
class Options {

	/**
	 * Wire the registration hooks.
	 */
	public static function init(): void {
		\add_action( 'admin_init', array( self::class, 'register_settings' ) );
		\add_action( 'rest_api_init', array( self::class, 'register_settings' ) );
	}

	/**
	 * Register every option with the Settings API.
	 */
	public static function register_settings(): void {
		\register_setting(
			'atmosphere',
			'atmosphere_auto_publish',
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		\register_setting(
			'atmosphere',
			'atmosphere_long_form_composition',
			array(
				'type'              => 'string',
				'description'       => 'Composition strategy for long-form Bluesky posts.',
				'default'           => 'link-card',
				'sanitize_callback' => array( Sanitize::class, 'long_form_composition' ),
				'show_in_rest'      => array(
					'schema' => array(
						'enum' => Atmosphere::LONG_FORM_STRATEGIES,
					),
				),
			)
		);

		\register_setting(
			'atmosphere',
			'atmosphere_support_post_types',
			array(
				'type'              => 'array',
				'description'       => 'Post types to publish to AT Protocol.',
				'default'           => array( 'post' ),
				'sanitize_callback' => array( Post_Types::class, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);

		\register_setting(
			'atmosphere',
			'atmosphere_handle',
			array(
				'type'              => 'string',
				'show_in_rest'      => false,
				'sanitize_callback' => array( Sanitize::class, 'handle' ),
			)
		);
	}
}
