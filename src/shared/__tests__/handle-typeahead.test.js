import { searchHandles } from '../handle-typeahead';

describe( 'searchHandles', () => {
	afterEach( () => {
		delete global.fetch;
	} );

	test( 'returns the actors array on a successful response', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( {
				actors: [ { did: 'did:plc:a', handle: 'alice.bsky.social' } ],
			} ),
		} );

		const actors = await searchHandles( 'https://ex.test/xrpc', 'ali' );

		expect( actors ).toHaveLength( 1 );
		expect( actors[ 0 ].handle ).toBe( 'alice.bsky.social' );
	} );

	test( 'returns an empty array on a non-ok response', async () => {
		global.fetch = jest.fn().mockResolvedValue( { ok: false } );

		expect( await searchHandles( 'https://ex.test/xrpc', 'ali' ) ).toEqual(
			[]
		);
	} );

	test( 'returns an empty array when actors is missing or malformed', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( { notActors: true } ),
		} );

		expect( await searchHandles( 'https://ex.test/xrpc', 'ali' ) ).toEqual(
			[]
		);
	} );

	test( 'returns an empty array when fetch rejects', async () => {
		global.fetch = jest.fn().mockRejectedValue( new Error( 'network' ) );

		expect( await searchHandles( 'https://ex.test/xrpc', 'ali' ) ).toEqual(
			[]
		);
	} );

	test( 'builds an encoded q + limit query on a bare endpoint', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( { actors: [] } ),
		} );

		await searchHandles( 'https://ex.test/xrpc', 'al ice' );

		const requested = new URL( global.fetch.mock.calls[ 0 ][ 0 ] );
		expect( requested.searchParams.get( 'q' ) ).toBe( 'al ice' );
		expect( requested.searchParams.get( 'limit' ) ).toBe( '8' );
	} );

	test( 'preserves an existing query string instead of doubling the ?', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( { actors: [] } ),
		} );

		await searchHandles( 'https://ex.test/xrpc?viewer=did:plc:me', 'ali' );

		const raw = String( global.fetch.mock.calls[ 0 ][ 0 ] );
		expect( raw.match( /\?/g ) ).toHaveLength( 1 );

		const requested = new URL( raw );
		expect( requested.searchParams.get( 'viewer' ) ).toBe( 'did:plc:me' );
		expect( requested.searchParams.get( 'q' ) ).toBe( 'ali' );
		expect( requested.searchParams.get( 'limit' ) ).toBe( '8' );
	} );
} );
