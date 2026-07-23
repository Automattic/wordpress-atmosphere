/**
 * Jest unit-test configuration.
 *
 * Extends the default `@wordpress/scripts` unit config and registers a setup
 * file that exposes the `window.wp.*` globals our script modules read at load
 * time. We append to any `setupFiles` the default config already declares
 * rather than replacing them, so the toolchain's own globals still run.
 */

/**
 * Internal dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaultConfig,
	setupFiles: [
		...( defaultConfig.setupFiles || [] ),
		'<rootDir>/jest.setup.js',
	],
};
