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
 * The registered `default` only resolves for a bare `get_option()` when
 * `register_setting()` has run in the current request. `admin_init` covers
 * `options.php` form submissions and `rest_api_init` covers the
 * `/wp-json/wp/v2/settings` schema + default resolution — but neither fires
 * under WP-CLI or cron, where a scheduled-post publish (or a bare
 * `get_option()` from a script) still needs the defaults. `init()` is
 * itself hooked on `init` from {@see Atmosphere::init()} and dispatches
 * accordingly: register immediately on WP-CLI / cron, otherwise defer to
 * the matching hook. Front-end page views deliberately do NOT register —
 * nothing there reads these options without passing an explicit default.
 */
class Options {

	/**
	 * Dispatch the registration onto the right hook for the request.
	 *
	 * Invoked on the `init` action.
	 */
	public static function init(): void {
		if ( ( \defined( 'WP_CLI' ) && \WP_CLI ) || \wp_doing_cron() ) {
			self::register_settings();
			return;
		}

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
				'type'         => 'boolean',
				'description'  => 'Whether new posts are automatically published to AT Protocol.',
				'default'      => '1',
				'show_in_rest' => true,
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
