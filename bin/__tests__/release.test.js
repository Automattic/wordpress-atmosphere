/**
 * Tests for the release script's version-marker replacement.
 *
 * These import the patterns the release actually runs, so a change to
 * bin/version-patterns.js is covered here rather than in a copy of it.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { phpVersionPatterns } = require( '../version-patterns' );

const VERSION = '9.9.9';

const rewrite = ( content ) =>
	phpVersionPatterns( VERSION ).reduce(
		( updated, { search, replace } ) => updated.replace( search, replace ),
		content
	);

describe( 'Version markers in code', () => {
	test.each( [
		[
			'a call wrapped across several lines',
			`\t\t\t\\_doing_it_wrong(\n\t\t\t\t__METHOD__,\n\t\t\t\t\\esc_html__( 'must return an array.', 'atmosphere' ),\n\t\t\t\t'unreleased'\n\t\t\t);`,
		],
		[
			'a wrapped call whose message is a variable, not a literal',
			`\t\t\t\\_doing_it_wrong(\n\t\t\t\t\\esc_html( $method ),\n\t\t\t\t\\esc_html( $message ),\n\t\t\t\t'unreleased'\n\t\t\t);`,
		],
		[
			'a single-line call whose message is a variable',
			`\t\t\\_doing_it_wrong( \\esc_html( $method ), \\esc_html( $message ), 'unreleased' );`,
		],
		[
			'a single-line call whose message is a literal',
			`\t\t\\_doing_it_wrong( __METHOD__, 'Use new_method().', 'unreleased' );`,
		],
		[ '_deprecated_function', `\t\t\\_deprecated_function( __FUNCTION__, 'unreleased', 'new_function' );` ],
		[ '_deprecated_argument', `\t\t\\_deprecated_argument( __FUNCTION__, 'unreleased', 'Pass an array.' );` ],
		[
			'apply_filters_deprecated, where the version is not the last argument',
			`\t\t\\apply_filters_deprecated( 'atmosphere_old', array( $value ), 'unreleased', 'atmosphere_new' );`,
		],
		[
			'do_action_deprecated',
			`\t\tdo_action_deprecated( 'old_hook', array( $v ), 'unreleased', 'new_hook' );`,
		],
		[
			'version_compare in a migration',
			`\t\tif ( \\version_compare( $version_from_db, 'unreleased', '<' ) ) {`,
		],
		[
			'a wrapped call whose message contains a semicolon',
			`\t\t\t\\_doing_it_wrong(\n\t\t\t\t__METHOD__,\n\t\t\t\t\\esc_html__( 'Entries were not non-empty strings; those were skipped.', 'atmosphere' ),\n\t\t\t\t'unreleased'\n\t\t\t);`,
		],
		[
			'a wrapped call whose message is a ternary spanning lines',
			`\t\t\t\\_doing_it_wrong(\n\t\t\t\t__METHOD__,\n\t\t\t\t$fallback\n\t\t\t\t\t? \\esc_html__( 'Missing $type; falling back.', 'atmosphere' )\n\t\t\t\t\t: \\esc_html__( 'Missing $type; omitting.', 'atmosphere' ),\n\t\t\t\t'unreleased'\n\t\t\t);`,
		],
	] )( 'stamps the version on %s', ( _label, source ) => {
		const result = rewrite( source );

		expect( result ).toContain( `'${ VERSION }'` );
		expect( result ).not.toContain( "'unreleased'" );
	} );

	test( 'stamps every marker in a file that has more than one', () => {
		const source = `\t\t\\_doing_it_wrong(\n\t\t\t__METHOD__,\n\t\t\t'First.',\n\t\t\t'unreleased'\n\t\t);\n\n\t\t\\_doing_it_wrong(\n\t\t\t__METHOD__,\n\t\t\t'Second.',\n\t\t\t'unreleased'\n\t\t);`;

		expect( rewrite( source ).match( /9\.9\.9/g ) ).toHaveLength( 2 );
	} );
} );

describe( 'Version markers in docblocks', () => {
	test( 'stamps @since and @deprecated, in any case', () => {
		const source = `\t/**\n\t * @since unreleased\n\t * @since UNRELEASED Changed the return shape.\n\t * @deprecated unreleased\n\t */`;

		const result = rewrite( source );

		expect( result ).not.toMatch( /unreleased/i );
		expect( result.match( /9\.9\.9/g ) ).toHaveLength( 3 );
	} );
} );

describe( 'Text that only looks like a marker', () => {
	test.each( [
		[
			'a documented example inside a block comment',
			`\t\t/*\n\t\t * Example:\n\t\t *\n\t\t * if ( \\version_compare( $version_from_db, 'unreleased', '<' ) ) {\n\t\t *     // Update routine.\n\t\t * }\n\t\t */`,
		],
		[
			'a documented call inside a docblock',
			`\t\t * \\_doing_it_wrong( __METHOD__, 'Example.', 'unreleased' );`,
		],
		[
			'a commented-out call',
			`\t\t// \\_deprecated_function( __FUNCTION__, 'unreleased', 'x' );`,
		],
		[ 'prose describing the convention', `\t\t * Use 'unreleased' as the version number for new migrations.` ],
		[ 'a variable assignment', `\t\t$unreleased_var = 'unreleased';` ],
		[
			'a user agent that ends in the word',
			`\t\t\tarray( 'comment_agent' => 'ATmosphere/0.0.0-unreleased' )`,
		],
		[ 'a translated string', `\t\t$label = \\__( 'unreleased', 'atmosphere' );` ],
	] )( 'leaves %s alone', ( _label, source ) => {
		expect( rewrite( source ) ).toBe( source );
	} );
} );

describe( 'The plugin as it stands', () => {
	const pluginRoot = path.join( __dirname, '..', '..' );

	const phpFiles = ( dir ) =>
		fs.readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
			const full = path.join( dir, entry.name );

			if ( entry.isDirectory() ) {
				return phpFiles( full );
			}

			return entry.name.endsWith( '.php' ) ? [ full ] : [];
		} );

	test( 'no marker is left behind in includes/', () => {
		const leftovers = phpFiles( path.join( pluginRoot, 'includes' ) )
			.map( ( file ) => [ file, rewrite( fs.readFileSync( file, 'utf8' ) ) ] )
			.filter( ( [ , content ] ) => /'unreleased'|@(since|deprecated) unreleased/i.test( content ) )
			.map( ( [ file ] ) => path.relative( pluginRoot, file ) );

		expect( leftovers ).toEqual( [] );
	} );

	test( 'the release leaves the test suite untouched', () => {
		const tests = phpFiles( path.join( pluginRoot, 'tests' ) );

		expect( tests.length ).toBeGreaterThan( 0 );

		tests.forEach( ( file ) => {
			const content = fs.readFileSync( file, 'utf8' );

			expect( rewrite( content ) ).toBe( content );
		} );
	} );
} );
