import { strategyLabel, hasOverLimit, isAuthError } from '../utils';

describe( 'strategyLabel', () => {
	test( 'labels each known strategy', () => {
		expect( strategyLabel( 'short-form' ) ).toBe( 'Short note' );
		expect( strategyLabel( 'truncate-link' ) ).toBe( 'Text with link' );
		expect( strategyLabel( 'teaser-thread' ) ).toBe( 'Teaser thread' );
		expect( strategyLabel( 'custom-text' ) ).toBe( 'Custom text' );
		expect( strategyLabel( 'link-card' ) ).toBe( 'Link card' );
	} );

	test( 'falls back to link card for unknown strategies', () => {
		expect( strategyLabel( 'something-else' ) ).toBe( 'Link card' );
		expect( strategyLabel( undefined ) ).toBe( 'Link card' );
	} );
} );

describe( 'hasOverLimit', () => {
	test( 'is false for empty or non-array input', () => {
		expect( hasOverLimit( [] ) ).toBe( false );
		expect( hasOverLimit( null ) ).toBe( false );
		expect( hasOverLimit( undefined ) ).toBe( false );
	} );

	test( 'tolerates null/empty record entries', () => {
		expect( hasOverLimit( [ null, undefined, {} ] ) ).toBe( false );
	} );

	test( 'is false when every record is within the limit', () => {
		expect(
			hasOverLimit( [ { over_limit: false }, { over_limit: false } ] )
		).toBe( false );
	} );

	test( 'is true when any record is over the limit', () => {
		expect(
			hasOverLimit( [ { over_limit: false }, { over_limit: true } ] )
		).toBe( true );
	} );
} );

describe( 'isAuthError', () => {
	test.each( [
		// [ description, error, expected ]
		[
			'rest_forbidden code is a permission failure',
			{ code: 'rest_forbidden', data: { status: 403 } },
			true,
		],
		[
			'a bare 401 status is a permission failure',
			{ data: { status: 401 } },
			true,
		],
		[
			'a bare 403 status is a permission failure',
			{ data: { status: 403 } },
			true,
		],
		[
			'an expired nonce (403) is transient, not a permission failure',
			{ code: 'rest_cookie_invalid_nonce', data: { status: 403 } },
			false,
		],
		[
			'a 500 is transient',
			{ code: 'atmosphere_projection_failed', data: { status: 500 } },
			false,
		],
		[ 'an unknown error is transient', { code: 'whatever' }, false ],
		[ 'a null error is transient', null, false ],
	] )( '%s', ( _description, error, expected ) => {
		expect( isAuthError( error ) ).toBe( expected );
	} );
} );
