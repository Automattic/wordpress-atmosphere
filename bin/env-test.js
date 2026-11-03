#!/usr/bin/env node

/**
 * Run PHPUnit inside the wp-env tests container.
 *
 * `.wp-env.json` maps the plugin as ".", and wp-env mounts a local source
 * under its own directory name. That is `wordpress-atmosphere` for a default
 * clone, but a renamed clone, a git worktree, or a Conductor workspace is
 * mounted under whatever its directory is called. The container path is
 * derived the same way here, so the tests run from any checkout.
 *
 * Extra arguments go to PHPUnit: `npm run env-test -- --filter=pattern`.
 */

/* eslint-disable no-console -- A command-line script reports to the terminal. */

const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const ROOT = path.resolve( __dirname, '..' );

// wp-env mounts a local plugin under the name of its directory.
const PLUGIN_DIR = `wp-content/plugins/${ path.basename( ROOT ) }`;

// The package does not export its bin path, so it is read from disk.
const WP_ENV = path.join(
	ROOT,
	'node_modules',
	'@wordpress',
	'env',
	'bin',
	'wp-env'
);

if ( ! fs.existsSync( WP_ENV ) ) {
	console.error( 'wp-env is not installed. Run `npm install` first.' );
	process.exit( 1 );
}

const { status, error } = spawnSync(
	process.execPath,
	[
		WP_ENV,
		'run',
		'tests-cli',
		`--env-cwd=${ PLUGIN_DIR }`,
		'vendor/bin/phpunit',
		...process.argv.slice( 2 ),
	],
	{ cwd: ROOT, stdio: 'inherit' }
);

if ( error ) {
	console.error( error.message );
	process.exit( 1 );
}

process.exit( status ?? 1 );
