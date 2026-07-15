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
} );
