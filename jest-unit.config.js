/**
 * Jest unit-test configuration.
 *
 * Extends the default `@wordpress/scripts` unit config and registers a setup
 * file that exposes the `window.wp.*` globals our script modules read at load
 * time. Jest concatenates a preset's `setupFiles` with these, so the preset's
 * own globals still run.
 */

/**
 * Internal dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaultConfig,
	setupFiles: [ '<rootDir>/jest.setup.js' ],
};
