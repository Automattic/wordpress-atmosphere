/**
 * Tests for the container path the PHPUnit runner changes into.
 */

const { pluginDir } = require( '../env-test' );

describe( 'pluginDir', () => {
	it( 'uses the directory name of a default clone', () => {
		expect( pluginDir( '/home/dev/wordpress-atmosphere' ) ).toBe(
			'wp-content/plugins/wordpress-atmosphere'
		);
	} );

	it( 'follows a renamed clone', () => {
		expect( pluginDir( '/home/dev/code/atmosphere' ) ).toBe(
			'wp-content/plugins/atmosphere'
		);
	} );

	it( 'follows a git worktree', () => {
		expect(
			pluginDir( '/home/dev/atmosphere/.claude/worktrees/fix-thing' )
		).toBe( 'wp-content/plugins/fix-thing' );
	} );
} );
