/**
 * Jest unit-test configuration.
 *
 * Builds on `@wordpress/jest-preset-default` and registers a setup file that
 * adds the testing-library matchers and exposes the `window.wp.*` globals our
 * script modules read at load time. We append to the `setupFilesAfterEnv` the
 * preset already declares rather than replacing them, so the preset's own
 * setup still runs.
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
	setupFilesAfterEnv: [
		...presetConfig.setupFilesAfterEnv,
		'<rootDir>/jest.setup.js',
	],
	/*
	 * Babel is configured here rather than in a root config file, so the
	 * transform stays scoped to the tests: a root Babel config would also apply
	 * to the webpack build.
	 *
	 * `@wordpress/theme` (a transitive dependency of `@wordpress/components`) is
	 * ESM-only and ships as .mjs, so it needs the same transform as the .js/.ts
	 * sources.
	 */
	transform: {
		'\\.[jt]sx?$': babelTransform,
		'\\.mjs$': babelTransform,
	},
	/*
	 * Allow ESM/TypeScript-only packages to be transformed by Babel at any depth
	 * in node_modules. The negative lookahead with an optional inner path skips
	 * the ignore for nested copies too, like
	 * `node_modules/@wordpress/components/node_modules/uuid/`, so the
	 * untranspiled `import`/`export` syntax reaches Babel. `@wordpress/components`
	 * pulls in `@wordpress/ui` (raw TS source) and `@wordpress/theme` (an `.mjs`
	 * ESM module), both of which must be transformed too.
	 */
	transformIgnorePatterns: [
		'/node_modules/(?!(?:.*/)?(?:uuid|@wordpress/(?:theme|ui))/)',
	],
};
