import { isSharingEnabled } from '../utils';

describe( 'isSharingEnabled', () => {
	test( 'is enabled by default (no meta / empty meta)', () => {
		expect( isSharingEnabled( undefined ) ).toBe( true );
		expect( isSharingEnabled( {} ) ).toBe( true );
	} );

	test( 'is disabled when the flag is truthy', () => {
		expect( isSharingEnabled( { atmosphere_disabled: true } ) ).toBe(
			false
		);
	} );

	test( 'is enabled when the flag is explicitly false', () => {
		expect( isSharingEnabled( { atmosphere_disabled: false } ) ).toBe(
			true
		);
	} );
} );
