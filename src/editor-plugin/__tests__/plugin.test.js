import { isSharingEnabled, shouldShowNotConnectedNotice } from '../utils';

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

describe( 'shouldShowNotConnectedNotice', () => {
	test( 'warns when sharing is on and the site is disconnected', () => {
		expect(
			shouldShowNotConnectedNotice( {
				enabled: true,
				isConnected: false,
				hasReconnectError: false,
			} )
		).toBe( true );
	} );

	test( 'stays quiet while connected', () => {
		expect(
			shouldShowNotConnectedNotice( {
				enabled: true,
				isConnected: true,
				hasReconnectError: false,
			} )
		).toBe( false );
	} );

	test( 'stays quiet when sharing is off for the post', () => {
		expect(
			shouldShowNotConnectedNotice( {
				enabled: false,
				isConnected: false,
				hasReconnectError: false,
			} )
		).toBe( false );
	} );

	test( 'defers to an existing reconnect-flavored publish error', () => {
		expect(
			shouldShowNotConnectedNotice( {
				enabled: true,
				isConnected: false,
				hasReconnectError: true,
			} )
		).toBe( false );
	} );
} );
