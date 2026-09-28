/**
 * Jest unit-test configuration.
 *
 * Builds on `@wordpress/jest-preset-default` and registers a setup file that
 * exposes the `window.wp.*` globals our script modules read at load time. We
 * append to the `setupFiles` the preset already declares rather than replacing
 * them, so the preset's own globals still run.
 */

/**
 * WordPress dependencies
 */
const presetConfig = require( '@wordpress/jest-preset-default' );

const babelTransform = [
	require.resolve( 'babel-jest' ),
	{ presets: [ require.resolve( '@wordpress/babel-preset-default' ) ] },
];

module.exports = {
	...presetConfig,
	testPathIgnorePatterns: [
		'/build/',
		'/node_modules/',
		'/tests/phpunit/',
		'/vendor/',
	],
	setupFiles: [ ...presetConfig.setupFiles, '<rootDir>/jest.setup.js' ],
	/*
	 * Babel is configured here rather than in a root config file, so the
	 * transform stays scoped to the tests: a root Babel config would also apply
	 * to the webpack build.
	 */
	transform: {
		'\\.[jt]sx?$': babelTransform,
	},
};
