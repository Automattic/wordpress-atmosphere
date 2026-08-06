import { isSharingEnabled, shareHelpText, panelMessage } from '../utils';

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
		expect( shareHelpText( false, true ) ).toBe(
			'This post will not be shared via ATmosphere.'
		);
	} );

	test( 'ignores the connection state when the toggle is off', () => {
		expect( shareHelpText( false, true ) ).toBe(
			shareHelpText( false, false )
		);
	} );

	test( 'promises delivery on publish when the connection is healthy', () => {
		expect( shareHelpText( true, true ) ).toBe(
			'This post will be shared via ATmosphere when published.'
		);
	} );

	test( 'stops promising delivery while the connection is dead', () => {
		expect( shareHelpText( true, false ) ).toBe(
			'Sharing is on for this post, but it will not be shared until your site is connected to Bluesky.'
		);
	} );
} );

const OK = {
	state: 'ok',
	message: '',
	severity: 'info',
	action: false,
	can_share: true,
};
const post = ( overrides = {} ) => ( {
	enabled: true,
	hasRecord: false,
	hasPublishError: false,
	...overrides,
} );

describe( 'panelMessage', () => {
	test( 'says nothing when everything is fine', () => {
		expect( panelMessage( OK, post() ) ).toBeNull();
	} );

	test( 'renders whatever the site decided, with its action', () => {
		const status = {
			state: 'needs_reconnect',
			message: 'Your session expired.',
			severity: 'warning',
			action: true,
			can_share: false,
		};

		expect( panelMessage( status, post() ) ).toEqual( {
			kind: 'needs_reconnect',
			severity: 'warning',
			message: 'Your session expired.',
			action: true,
		} );
	} );

	test( 'a site problem outranks a failed share, so only one shows', () => {
		const status = {
			state: 'needs_reconnect',
			message: 'Your session expired.',
			severity: 'warning',
			action: true,
			can_share: false,
		};

		expect(
			panelMessage( status, post( { hasPublishError: true } ) ).kind
		).toBe( 'needs_reconnect' );
	} );

	test( 'stays silent when the site said nothing and cannot share', () => {
		const status = {
			state: 'sharing_off_external',
			message: '',
			severity: 'info',
			action: false,
			can_share: false,
		};

		expect(
			panelMessage(
				status,
				post( { hasPublishError: true, hasRecord: true } )
			)
		).toBeNull();
	} );

	test( 'reports a failed share when the site is otherwise fine', () => {
		expect(
			panelMessage( OK, post( { hasPublishError: true } ) )
		).toMatchObject( { kind: 'publishError', severity: 'error' } );
	} );

	test( 'reports a pending removal, and a failure outranks it', () => {
		expect(
			panelMessage( OK, post( { enabled: false, hasRecord: true } ) ).kind
		).toBe( 'pendingRemoval' );

		expect(
			panelMessage(
				OK,
				post( {
					enabled: true,
					hasRecord: true,
					hasPublishError: true,
				} )
			).kind
		).toBe( 'publishError' );
	} );
} );
