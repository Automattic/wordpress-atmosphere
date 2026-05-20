#!/usr/bin/env node

const { execSync } = require( 'child_process' );
const readline = require( 'readline' );
const fs = require( 'fs' );

const rl = readline.createInterface( {
	input: process.stdin,
	output: process.stdout,
} );

const exec = ( command ) => {
	try {
		return execSync( command, { stdio: 'inherit' } );
	} catch ( error ) {
		console.error( `Error executing command: ${ command }` );
		process.exit( 1 );
	}
};

const execWithOutput = ( command ) => {
	try {
		return execSync( command, { stdio: 'pipe' } ).toString().trim();
	} catch ( error ) {
		console.error( `Error executing command: ${ command }` );
		process.exit( 1 );
	}
};

const updateVersionInFile = ( filePath, version, patterns ) => {
	let content = fs.readFileSync( filePath, 'utf8' );

	patterns.forEach( ( { search, replace } ) => {
		content = content.replace( search, typeof replace === 'function' ? replace( version ) : replace );
	} );

	fs.writeFileSync( filePath, content );
};

const prompt = ( question ) =>
	new Promise( ( resolve ) => rl.question( question, ( answer ) => resolve( answer.trim() ) ) );

/*
 * Calls `vendor/bin/changelogger` directly rather than through the
 * `composer changelog:write` composer script. The script chains
 * `composer install` + `vendor/bin/changelogger write --add-pr-num`,
 * and composer's `--` arg-forwarding hands extra flags (like
 * `--use-version`) to the FIRST sub-command, which doesn't know them.
 * Skipping composer here lets us pass `--use-version=X.Y.Z` to the
 * changelogger binary as documented.
 */
const runChangeloggerWrite = ( useVersion ) => {
	const useVersionFlag = useVersion ? ` --use-version=${ useVersion }` : '';
	execSync( `vendor/bin/changelogger write --add-pr-num${ useVersionFlag }`, { stdio: 'pipe' } );
};

/*
 * Match `## [1.0.0] - 2026-05-20` AND `## 1.0.0 - 2026-05-20`. Jetpack
 * changelogger writes the bracketed Keep-a-Changelog form when a
 * prior version exists (so it has a comparison link to attach) and
 * the bare form on a first release with no link. The release script
 * has to work for both.
 */
const RELEASE_HEADER_RE = /## \[?(\d+\.\d+\.\d+)\]? - \d{4}-\d{2}-\d{2}/;
const RELEASE_HEADER_RE_GLOBAL = /## \[?(\d+\.\d+\.\d+)\]? - (\d{4}-\d{2}-\d{2})/g;

const generateChangelog = async () => {
	/*
	 * First attempt without --use-version. Jetpack changelogger infers
	 * the next version from the most recent `## X.Y.Z` block in
	 * CHANGELOG.md plus the `Significance:` of each unreleased entry.
	 * On a first-ever release the file has no prior block, so the bare
	 * command fails with "Changelog file contains no entries! Use
	 * --use-version to specify the initial version." We detect that
	 * exact case and re-run with an explicit version gathered
	 * interactively; everything else surfaces verbatim so real
	 * failures stop being swallowed by `stdio: 'ignore'`.
	 *
	 * Note: semver intentionally refuses to auto-bump 0.x.y → 1.0.0
	 * even when entries carry `Significance: major`. To go to 1.0,
	 * answer the prompt with `1.0.0`.
	 */
	try {
		runChangeloggerWrite( null );
	} catch ( error ) {
		const stdout = ( error.stdout || '' ).toString();
		const stderr = ( error.stderr || '' ).toString();
		const combined = `${ stdout }\n${ stderr }`;

		const needsInitialVersion = combined.includes( 'Changelog file contains no entries' );

		if ( ! needsInitialVersion ) {
			console.error( 'Error generating changelog:' );
			if ( stdout ) {
				console.error( stdout );
			}
			if ( stderr ) {
				console.error( stderr );
			}
			process.exit( 1 );
		}

		console.log(
			'CHANGELOG.md has no prior version blocks — Jetpack changelogger ' +
				'cannot infer the next version. This is normal for the very ' +
				'first release. (Note: semver does not auto-bump 0.x.y → 1.0.0; ' +
				'enter 1.0.0 explicitly if you want to ship the first stable.)'
		);
		const initial = await prompt(
			'Initial version to assign this release (e.g. 1.0.0): '
		);

		if ( ! /^\d+\.\d+\.\d+$/.test( initial ) ) {
			console.error(
				`"${ initial }" is not a valid X.Y.Z version. Aborting.`
			);
			process.exit( 1 );
		}

		try {
			runChangeloggerWrite( initial );
		} catch ( retryError ) {
			console.error( 'Error generating changelog (retry):' );
			console.error( ( retryError.stdout || '' ).toString() );
			console.error( ( retryError.stderr || '' ).toString() );
			process.exit( 1 );
		}
	}

	const content = fs.readFileSync( 'CHANGELOG.md', 'utf8' );
	const match = content.match( RELEASE_HEADER_RE );

	if ( ! match ) {
		console.error( 'No version found in CHANGELOG.md after writing entries' );
		process.exit( 1 );
	}

	return match[ 1 ];
};

const updateReadmeWithChangelog = ( version ) => {
	// Grab the contents of the changelog and readme files.
	const changelogContent = fs.readFileSync( 'CHANGELOG.md', 'utf8' );
	const readmeContent = fs.readFileSync( 'readme.txt', 'utf8' );

	// Ensure the latest release entry was found in the list of latest releases we grabbed.
	// Accept both `## [version]` (Keep-a-Changelog with link) and bare `## version`
	// (Jetpack changelogger's first-release output without a comparison link).
	const latestReleaseRegex = new RegExp( `## \\[?${ version }\\]?.*?(?=## \\[?\\d|$)`, 's' );
	const latestReleaseMatch = changelogContent.match( latestReleaseRegex );
	if ( ! latestReleaseMatch ) {
		console.error( `No changelog entry found for version ${ version }` );
		process.exit( 1 );
	}

	// Extract the changelog entries for the given version
	// as well as any other entries from other releases under the same major version
	// e.g. if the latest release is 1.2.1, then we want to include all entries from 1.0.0 to 1.2.1.
	const majorVersion = version.split( '.' )[ 0 ];

	// Find all releases with the same major version.
	const releaseRegex = new RegExp( RELEASE_HEADER_RE_GLOBAL.source, 'g' );
	const releases = [];
	let match;

	while ( ( match = releaseRegex.exec( changelogContent ) ) !== null ) {
		const [ , releaseVersion, releaseDate ] = match;
		if ( releaseVersion.startsWith( `${ majorVersion }.` ) ) {
			// Find the content for this release
			const releaseContentRegex = new RegExp( `## \\[?${ releaseVersion }\\]?.*?(?=## \\[?\\d|$)`, 's' );
			const releaseContent = changelogContent.match( releaseContentRegex );

			if ( releaseContent ) {
				releases.push( {
					version: releaseVersion,
					date: releaseDate,
					content: releaseContent[ 0 ],
				} );
			}
		}
	}

	// Sort releases by version number (newest first)
	releases.sort( ( a, b ) => {
		const aParts = a.version.split( '.' ).map( Number );
		const bParts = b.version.split( '.' ).map( Number );

		for ( let i = 0; i < 3; i++ ) {
			if ( aParts[ i ] !== bParts[ i ] ) {
				return bParts[ i ] - aParts[ i ]; // Descending order
			}
		}

		return 0;
	} );

	// Format the changelog entries for readme.txt
	// 1. Increase the header level by one (add one more #)
	// 2. Remove the square brackets from the version numbers.
	// 3. Remove PR numbers like [#123] from the ends of lines.
	let formattedChangelog = releases
		.map( ( entry ) => {
			/*
			 * The header in CHANGELOG.md may be bracketed (`## [X.Y.Z] - DATE`)
			 * or bare (`## X.Y.Z - DATE`); the regex covers both so we don't
			 * silently leave the original `##` line in place.
			 */
			const headerRegex = new RegExp( `## \\[?${ entry.version }\\]? - ${ entry.date }` );
			return entry.content
				.replace( /### /g, '#### ' )
				.replace( headerRegex, `### ${ entry.version } - ${ entry.date }` )
				.replace( /\s+\[#\d+\]$/gm, '' )
				.trim();
		} )
		.join( '\n\n' );

	// Find the changelog section in readme.txt
	const changelogSectionRegex = /== Changelog ==([\s\S]*?)(?=== |$)/;
	const changelogSection = readmeContent.match( changelogSectionRegex );

	if ( ! changelogSection ) {
		console.error( 'No changelog section found in readme.txt' );
		process.exit( 1 );
	}

	// At the bottom of the changelog section, add a link to the full changelog on GitHub.
	formattedChangelog +=
		'\n\nSee full Changelog on [GitHub](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/CHANGELOG.md).';

	// Update the readme.txt with the new changelog section
	const updatedReadmeContent = readmeContent.replace(
		changelogSectionRegex,
		`== Changelog ==\n\n${ formattedChangelog }\n\n`
	);

	fs.writeFileSync( 'readme.txt', updatedReadmeContent );
	console.log(
		`Updated readme.txt with changelog entries for version ${ version } and other entries from major version ${ majorVersion }`
	);
};

const updateReadmeWithUpgradeNotice = ( version ) => {
	return new Promise( ( resolve ) => {
		rl.question( '\nWould you like to add an upgrade notice for this version? (y/n): ', ( answer ) => {
			if ( answer.toLowerCase() === 'y' || answer.toLowerCase() === 'yes' ) {
				rl.question( 'Enter the upgrade notice (leave empty to skip): ', ( notice ) => {
					if ( notice.trim() ) {
						// Read the readme.txt file
						let readmeContent = fs.readFileSync( 'readme.txt', 'utf8' );

						// Check if Upgrade Notice section already exists
						const upgradeNoticeSectionRegex = /== Upgrade Notice ==([\s\S]*?)(?=== |$)/;
						const upgradeNoticeSection = readmeContent.match( upgradeNoticeSectionRegex );

						// Create the new upgrade notice section
						const newUpgradeNotice = `== Upgrade Notice ==\n\n= ${ version } =\n\n${ notice.trim() }\n\n`;

						if ( upgradeNoticeSection ) {
							// Replace the entire existing Upgrade Notice section
							readmeContent = readmeContent.replace( upgradeNoticeSectionRegex, newUpgradeNotice );
						} else {
							// Create a new Upgrade Notice section at the end of the file
							readmeContent += `\n\n${ newUpgradeNotice }`;
						}

						fs.writeFileSync( 'readme.txt', readmeContent );
						console.log( `Added upgrade notice for version ${ version } to readme.txt` );
					} else {
						console.log( 'No upgrade notice added.' );
					}
					resolve();
				} );
			} else {
				console.log( 'Skipping upgrade notice.' );
				resolve();
			}
		} );
	} );
};

async function createRelease() {
	// Start by generating the changelog.
	// The changelog will automatically pick a version
	// based off each changelog entry's provided significance.
	const version = await generateChangelog();

	const currentBranch = execWithOutput( 'git rev-parse --abbrev-ref HEAD' );

	// Check if release branch already exists
	const branchExists = execWithOutput( `git branch --list release/${ version }` );
	if ( branchExists ) {
		console.error( `\nError: Branch release/${ version } already exists.` );
		// Return to original branch if we're not already there
		if ( currentBranch !== execWithOutput( 'git rev-parse --abbrev-ref HEAD' ) ) {
			exec( `git checkout ${ currentBranch }` );
		}
		process.exit( 1 );
	}

	// Create and checkout release branch
	const branchName = `release/${ version }`;
	exec( `git checkout -b ${ branchName }` );

	// Update version numbers in files
	updateVersionInFile( 'atmosphere.php', version, [
		{
			search: /Version: unreleased/i,
			replace: `Version: ${ version }`,
		},
		{
			search: /ATMOSPHERE_VERSION', 'unreleased/i,
			replace: `ATMOSPHERE_VERSION', '${ version }`,
		},
	] );

	updateVersionInFile( 'readme.txt', version, [
		{
			search: /Stable tag: unreleased/i,
			replace: `Stable tag: ${ version }`,
		},
	] );

	updateVersionInFile( 'package.json', version, [
		{
			search: /"version": "0\.0\.0-unreleased"/,
			replace: `"version": "${ version }"`,
		},
	] );

	// Update the changelog section in readme.txt
	updateReadmeWithChangelog( version );

	// Prompt for and update the upgrade notice section in readme.txt
	await updateReadmeWithUpgradeNotice( version );

	// Replace "unreleased" version placeholders across all PHP files
	const phpFiles = execWithOutput( 'find . -name "*.php" -not -path "./vendor/*"' ).split( '\n' );

	phpFiles.forEach( ( filePath ) => {
		if ( ! filePath ) {
			return;
		}

		updateVersionInFile( filePath, version, [
			{
				search: /@since unreleased/gi,
				replace: `@since ${ version }`,
			},
			{
				search: /@deprecated unreleased/gi,
				replace: `@deprecated ${ version }`,
			},
			/*
			 * The `s` (dotAll) flag is critical here. `_deprecated_*()`,
			 * `_doing_it_wrong()`, `apply_filters_deprecated()`, etc.
			 * are routinely formatted across multiple lines in this
			 * codebase, with `\esc_html__()` calls or translator
			 * comments between the function name and the version
			 * literal. Without `s`, `.*?` won't match the embedded
			 * newlines and the substitution silently no-ops on every
			 * multi-line call site — leaving `'unreleased'` literals
			 * in the released code.
			 */
			{
				search: /(?<=_deprecated_(?:function|class|constructor|file|argument|hook)\s*\(\s*.*?,\s*')unreleased(?=')/gis,
				replace: ( match ) => match.replace( /unreleased/i, version ),
			},
			{
				search: /(?<=_doing_it_wrong\s*\(\s*.*?,\s*.*?,\s*')unreleased(?=')/gis,
				replace: ( match ) => match.replace( /unreleased/i, version ),
			},
			{
				search: /(?<=\b(?:apply_filters_deprecated|do_action_deprecated)\s*\(\s*'.*?'\s*,\s*array\s*\(.*?\)\s*,\s*')unreleased(?=['"],\s*['"])/gis,
				replace: ( match ) => match.replace( /unreleased/i, version ),
			},
			{
				search: /(?<=version_compare\s*\(\s*\$\w+,\s*')unreleased(?=',\s*['<=>])/gis,
				replace: ( match ) => match.replace( /unreleased/i, version ),
			},
		] );
	} );

	// Stage and commit changes
	exec( 'git add .' );
	exec( `git commit -m "Release ${ version }"` );

	// Push to remote
	exec( `git push -u origin ${ branchName }` );

	// Get current user's GitHub username
	const currentUser = execWithOutput( 'gh api user --jq .login' );

	// Create PR using GitHub CLI and capture the URL
	console.log( '\nCreating PR...' );
	const prUrl = execWithOutput(
		`gh pr create --title "Release ${ version }" --body "Release version ${ version }" --base trunk --head ${ branchName } --reviewer "Automattic/fediverse" --assignee "${ currentUser }" --label "Release"`
	);

	// Open PR in browser if a URL was returned
	if ( prUrl && prUrl.includes( 'github.com' ) ) {
		exec( `open ${ prUrl }` );
	}
}

async function release() {
	try {
		// Check if gh CLI is installed
		try {
			execSync( 'gh --version', { stdio: 'ignore' } );
		} catch ( error ) {
			console.error( 'GitHub CLI (gh) is not installed. Please install it first:' );
			console.error( 'https://cli.github.com/' );
			process.exit( 1 );
		}

		// Ensure we're on trunk branch and up to date
		exec( 'git checkout trunk' );
		exec( 'git pull origin trunk' );

		await createRelease();
	} catch ( error ) {
		console.error( 'An error occurred:', error );
		process.exit( 1 );
	} finally {
		rl.close();
	}
}

release();
