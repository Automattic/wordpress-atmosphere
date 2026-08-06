import { isSharingEnabled, shareHelpText } from '../utils';

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

describe( 'shareHelpText', () => {
	test( 'says nothing will be shared when the toggle is off', () => {
		expect( shareHelpText( false, false ) ).toBe(
			'This post will not be shared via ATmosphere.'
		);
	} );

	test( 'ignores the connection state when the toggle is off', () => {
		expect( shareHelpText( false, true ) ).toBe(
			shareHelpText( false, false )
		);
	} );

	test( 'promises delivery on publish when the connection is healthy', () => {
		expect( shareHelpText( true, false ) ).toBe(
			'This post will be shared via ATmosphere when published.'
		);
	} );

	test( 'stops promising delivery while the connection is dead', () => {
		expect( shareHelpText( true, true ) ).toBe(
			'Sharing is on for this post, but it will not be shared while your site is disconnected from Bluesky.'
		);
	} );
} );
