import { renderHook } from '@testing-library/react';
import { useOptions } from '../use-options';

describe( 'useOptions', () => {
	afterEach( () => {
		delete window._atmosphereOptions;
		jest.clearAllMocks();
	} );

	test( 'returns empty object when no options set', () => {
		const { result } = renderHook( () => useOptions() );
		expect( result.current ).toEqual( {} );
	} );

	test( 'returns options from window global', () => {
		window._atmosphereOptions = {
			namespace: 'atmosphere/1.0',
			defaultAvatarUrl: 'https://example.com/avatar.jpg',
		};

		const { result } = renderHook( () => useOptions() );

		expect( result.current ).toEqual( {
			namespace: 'atmosphere/1.0',
			defaultAvatarUrl: 'https://example.com/avatar.jpg',
		} );
	} );

	test( 'handles missing window options gracefully', () => {
		window._atmosphereOptions = undefined;

		const { result } = renderHook( () => useOptions() );

		expect( result.current ).toEqual( {} );
	} );
} );
