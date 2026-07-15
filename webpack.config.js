/**
 * Extends the default @wordpress/scripts config so the Connectors card module
 * can import `@wordpress/connectors`.
 *
 * `@wordpress/connectors` is a WordPress 7.0 script module that core loads on
 * the Settings → Connectors screen and exposes through the browser import map,
 * but it isn't in the version of @wordpress/dependency-extraction-webpack-plugin
 * shipped with this toolchain — so the default build tries to bundle it and
 * fails. We teach the module build's extraction plugin to treat it as an
 * external module (resolved at runtime via the import map) instead. Everything
 * else — including `@wordpress/interactivity` used by the reactions view — falls
 * through to the plugin's defaults untouched.
 *
 * The card reads element / i18n / components from the `window.wp.*` classic-script
 * globals (see src/connectors-card/index.js), so those don't need module externals.
 */

const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const configs = require( '@wordpress/scripts/config/webpack.config' );

// The default export is an array [scriptConfig, moduleConfig] when
// --experimental-modules is set, or a single config object otherwise.
const list = Array.isArray( configs ) ? configs : [ configs ];

module.exports = list.map( ( config ) => {
	// Only the module build imports `@wordpress/connectors`.
	if ( ! config.experiments || ! config.experiments.outputModule ) {
		return config;
	}

	return {
		...config,
		plugins: config.plugins.map( ( plugin ) => {
			if (
				plugin.constructor.name !==
				'DependencyExtractionWebpackPlugin'
			) {
				return plugin;
			}

			return new DependencyExtractionWebpackPlugin( {
				...plugin.options,
				requestToExternalModule( request ) {
					if ( request === '@wordpress/connectors' ) {
						return true;
					}
					// Returning undefined cascades to the plugin's defaults,
					// preserving @wordpress/interactivity and friends.
					return undefined;
				},
			} );
		} ),
	};
} );
