import { isSharingEnabled, shareHelpText, siteStatus } from '../utils';

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

describe( 'siteStatus', () => {
	test( 'says nothing when sharing is on and the connection is fine', () => {
		expect( siteStatus( true, '', '' ) ).toBeNull();
	} );

	test( 'warns about the connection, with an action', () => {
		expect( siteStatus( true, '', 'Your session expired.' ) ).toEqual( {
			severity: 'warning',
			message: 'Your session expired.',
			action: true,
		} );
	} );

	test( 'explains sharing being off when the owner turned it off', () => {
		expect( siteStatus( false, 'Turned off in settings.', '' ) ).toEqual( {
			severity: 'info',
			message: 'Turned off in settings.',
			action: false,
		} );
	} );

	test( 'says nothing when sharing was forced off from outside', () => {
		expect( siteStatus( false, '', '' ) ).toBeNull();
	} );

	test( 'sharing being off outranks a dead connection', () => {
		expect( siteStatus( false, '', 'Your session expired.' ) ).toBeNull();
		expect(
			siteStatus( false, 'Turned off in settings.', 'Your session expired.' )
		).toEqual( {
			severity: 'info',
			message: 'Turned off in settings.',
			action: false,
		} );
	} );
} );
