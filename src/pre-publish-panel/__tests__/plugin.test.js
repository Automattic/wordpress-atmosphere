import { strategyLabel, hasOverLimit } from '../utils';

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
