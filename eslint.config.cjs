/**
 * ESLint flat config.
 *
 * The default config of `@wordpress/scripts` 36, plus Jest globals for the test
 * files: its `test-unit` rules target Vitest, but the tests here run on Jest,
 * so `describe`, `test`, `expect` and `jest` would read as undefined.
 */

const globals = require( 'globals' );
const wpPlugin = require( '@wordpress/eslint-plugin' );
const { hasBabelConfig } = require( '@wordpress/scripts/utils' );

const testFiles = [
	'**/@(test|__tests__)/**/*.{js,jsx,ts,tsx,mjs,cjs,mts,cts}',
	'**/*.@(test|spec).{js,jsx,ts,tsx,mjs,cjs,mts,cts}',
	'jest.setup.js',
];

const config = [
	// Global ignores.
	{
		ignores: [ '**/build/**', '**/node_modules/**', '**/vendor/**' ],
	},

	/*
	 * ESLint's default file discovery covers only `.js`, `.mjs` and `.cjs`.
	 * Every other extension has to be named before any config below applies.
	 */
	{ files: [ '**/*.jsx', '**/*.ts', '**/*.tsx', '**/*.mts', '**/*.cts' ] },

	// Base recommended config from @wordpress/eslint-plugin.
	...wpPlugin.configs.recommended,

	// Unit-test overrides from the plugin, scoped to our test file patterns.
	...wpPlugin.configs[ 'test-unit' ].map( ( c ) => ( {
		...c,
		files: testFiles,
	} ) ),

	// Test files run on Jest.
	{
		files: testFiles,
		languageOptions: {
			globals: { ...globals.jest },
		},
	},

	/*
	 * The ESLint config itself pulls plugin modules that are transitive deps
	 * of `@wordpress/scripts`, so don't ask to list them twice.
	 */
	{
		files: [ 'eslint.config.cjs' ],
		rules: {
			'import/no-extraneous-dependencies': 'off',
		},
	},
];

// If the project has no Babel config, provide defaults.
if ( ! hasBabelConfig() ) {
	config.push( {
		languageOptions: {
			parserOptions: {
				requireConfigFile: false,
				babelOptions: {
					presets: [
						require.resolve( '@wordpress/babel-preset-default' ),
					],
				},
			},
		},
	} );
}

module.exports = config;
