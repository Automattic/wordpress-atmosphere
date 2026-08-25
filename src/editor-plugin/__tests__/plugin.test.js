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

	test( 'explains what the toggle is for when sharing is off site-wide', () => {
		// The control still writes the meta `is_post_publishable()` reads, so
		// it decides whether `wp atmosphere backfill` reaches this post. The
		// help text has to say so, or the toggle reads as inert.
		expect( shareHelpText( true, false, false ) ).toContain( 'backfilled' );
		expect( shareHelpText( true, false, false ) ).toContain(
			'turned back on'
		);
		expect( shareHelpText( false, false, false ) ).toContain(
			'set not to be shared'
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
	sharing_enabled: true,
};
const post = ( overrides = {} ) => ( {
	enabled: true,
	hasRecord: false,
	hasPublishError: false,
	willBeUnpublished: false,
	isDirty: true,
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

	test( 'a pending removal outranks a stale failed share', () => {
		// The two co-occur normally: a record from a successful publish,
		// then a failed update. The failure copy says "update the post to
		// try again", which is the save that destroys the record, so the
		// removal has to win. Both inputs are set so the conflict is real.
		expect(
			panelMessage(
				OK,
				post( {
					enabled: true,
					hasRecord: true,
					hasPublishError: true,
					willBeUnpublished: true,
				} )
			).kind
		).toBe( 'pendingRemoval' );
	} );

	test( 'reports a failed share when nothing is being removed', () => {
		expect(
			panelMessage(
				OK,
				post( { hasRecord: true, hasPublishError: true } )
			).kind
		).toBe( 'publishError' );
	} );

	test( 'says nothing about removal for a post with no records', () => {
		// Nothing to delete, so no warning however unshareable it becomes.
		expect(
			panelMessage(
				OK,
				post( { enabled: false, willBeUnpublished: true } )
			)
		).toBeNull();
	} );

	test( 'warns about removal for any post holding records', () => {
		// `on_share_meta_changed()` deletes for any post that stops being
		// publishable while it still holds records, a draft included, so
		// the warning does not require a saved status of publish.
		expect(
			panelMessage(
				OK,
				post( { hasRecord: true, willBeUnpublished: true } )
			).message
		).toContain( 'cannot be undone' );
	} );

	test( 'uses the future tense only while the change is unsaved', () => {
		const pending = ( isDirty ) =>
			panelMessage(
				OK,
				post( { hasRecord: true, willBeUnpublished: true, isDirty } )
			).message;

		expect( pending( true ) ).toContain( 'Saving this change will remove' );

		// After the save the condition is still true, because cron has not
		// run yet. Repeating the future tense would read as "it didn't take".
		expect( pending( false ) ).toContain( 'still on Bluesky' );
		expect( pending( false ) ).not.toContain( 'Saving this change' );
	} );
} );
