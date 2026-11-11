/**
 * Version-marker replacement patterns for the release script.
 *
 * Kept in its own module so `bin/__tests__/release.test.js` can exercise the
 * patterns the release actually uses, rather than a copy that drifts from them.
 */

/*
 * Skip a match sitting on a comment line.
 *
 * A docblock that documents the convention by showing a call with the marker
 * still in it is instructions for the next developer, not code. Rewriting it
 * every release would slowly destroy the example.
 */
const NOT_IN_COMMENT = '(?<!^[ \\t]*(?:\\*|//|#|/\\*)[^\\n]*)';

/*
 * A `'unreleased'` sitting where a function takes an argument.
 *
 * Anchoring on the name of the function it belongs to would be more precise,
 * but it cannot be made to work here: the name and the version sit on
 * different lines in most of these calls, `.` does not cross a newline, and
 * every bound loose enough to span the arguments in between is also loose
 * enough to swallow a `;` inside a translated message, which most of them
 * have. What actually keeps this off unrelated text is the shape of the
 * match: the literal has to be exactly `'unreleased'`, it has to sit in an
 * argument slot, and it must not be on a comment line.
 *
 * The leading comma matters. None of these functions take the version first,
 * so requiring one argument ahead of it leaves `\\__( 'unreleased', 'atmosphere' )`
 * alone, where the word is the thing being translated rather than a version.
 */
const VERSION_ARGUMENT = `(?<=,\\s*)'unreleased'(?=\\s*[,)])`;

/**
 * Build the patterns for one version.
 *
 * @param {string} version The version being released.
 * @return {Array<{search: RegExp, replace: string}>} Patterns for updateVersionInFile().
 */
const phpVersionPatterns = ( version ) => [
	{
		search: /@since unreleased/gi,
		replace: `@since ${ version }`,
	},
	{
		search: /@deprecated unreleased/gi,
		replace: `@deprecated ${ version }`,
	},
	{
		search: new RegExp( NOT_IN_COMMENT + VERSION_ARGUMENT, 'gim' ),
		replace: `'${ version }'`,
	},
];

/**
 * Marker shapes the patterns above are meant to clear, for the post-rewrite check.
 */
const LEFTOVER_MARKER_GREP = `'unreleased'|@(since|deprecated) unreleased`;

module.exports = { phpVersionPatterns, LEFTOVER_MARKER_GREP };
