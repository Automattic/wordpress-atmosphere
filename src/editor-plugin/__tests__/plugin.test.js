import {
	isSharingEnabled,
	readReplyRestriction,
	getReplyMode,
	getReplyAudiences,
	buildRestrictionForMode,
	toggleReplyAudience,
	REPLY_AUDIENCE,
} from '../utils';

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

describe( 'readReplyRestriction', () => {
	test( 'defaults to an empty array', () => {
		expect( readReplyRestriction( undefined ) ).toEqual( [] );
		expect( readReplyRestriction( {} ) ).toEqual( [] );
		expect(
			readReplyRestriction( { atmosphere_reply_restriction: 'oops' } )
		).toEqual( [] );
	} );

	test( 'reads the stored tokens', () => {
		expect(
			readReplyRestriction( {
				atmosphere_reply_restriction: [ 'following' ],
			} )
		).toEqual( [ 'following' ] );
	} );
} );

describe( 'getReplyMode', () => {
	test( 'empty is everybody', () => {
		expect( getReplyMode( [] ) ).toBe( 'everybody' );
	} );

	test( 'nobody marker is nobody', () => {
		expect( getReplyMode( [ REPLY_AUDIENCE.NOBODY ] ) ).toBe( 'nobody' );
	} );

	test( 'audiences are custom', () => {
		expect( getReplyMode( [ REPLY_AUDIENCE.MENTIONED ] ) ).toBe( 'custom' );
	} );
} );

describe( 'getReplyAudiences', () => {
	test( 'strips the nobody marker', () => {
		expect( getReplyAudiences( [ REPLY_AUDIENCE.NOBODY ] ) ).toEqual( [] );
		expect(
			getReplyAudiences( [
				REPLY_AUDIENCE.MENTIONED,
				REPLY_AUDIENCE.FOLLOWING,
			] )
		).toEqual( [ 'mentioned', 'following' ] );
	} );
} );

describe( 'buildRestrictionForMode', () => {
	test( 'everybody clears the restriction', () => {
		expect(
			buildRestrictionForMode( 'everybody', [ 'mentioned' ] )
		).toEqual( [] );
	} );

	test( 'nobody stores the marker', () => {
		expect( buildRestrictionForMode( 'nobody', [ 'mentioned' ] ) ).toEqual(
			[ REPLY_AUDIENCE.NOBODY ]
		);
	} );

	test( 'custom preserves the audiences', () => {
		expect( buildRestrictionForMode( 'custom', [ 'follower' ] ) ).toEqual( [
			'follower',
		] );
	} );
} );

describe( 'toggleReplyAudience', () => {
	test( 'adds an audience', () => {
		expect(
			toggleReplyAudience( [], REPLY_AUDIENCE.MENTIONED, true )
		).toEqual( [ 'mentioned' ] );
	} );

	test( 'removes an audience', () => {
		expect(
			toggleReplyAudience(
				[ 'mentioned', 'following' ],
				REPLY_AUDIENCE.MENTIONED,
				false
			)
		).toEqual( [ 'following' ] );
	} );

	test( 'does not duplicate an existing audience', () => {
		expect(
			toggleReplyAudience(
				[ 'mentioned' ],
				REPLY_AUDIENCE.MENTIONED,
				true
			)
		).toEqual( [ 'mentioned' ] );
	} );

	test( 'toggling drops the nobody marker', () => {
		expect(
			toggleReplyAudience(
				[ REPLY_AUDIENCE.NOBODY ],
				REPLY_AUDIENCE.FOLLOWING,
				true
			)
		).toEqual( [ 'following' ] );
	} );
} );
