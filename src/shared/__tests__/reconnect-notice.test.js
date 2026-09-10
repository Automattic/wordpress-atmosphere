/**
 * Tests for the shared reconnect call to action.
 *
 * Rendered on two production surfaces (the document panel's publish-error
 * notice and the pre-publish panel), so its three copy branches — a link for
 * an administrator, a no-destination sentence for an administrator with
 * nowhere to send, and an ask-an-administrator sentence for everyone else —
 * are covered once here rather than indirectly through each panel's own
 * tests. Follows the `jest.mock`-based precedent in
 * `src/settings-connect/__tests__/index.test.js`: `../../config` is mocked
 * per test (via `jest.doMock`, not the hoisted `jest.mock`) so each case can
 * set its own CAN_MANAGE / RECONNECT_URL without a browser global.
 */

/**
 * External dependencies
 */
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

/**
 * WordPress dependencies
 */
import { createElement, createRoot } from '@wordpress/element';

describe( 'ReconnectAction', () => {
	let container;

	beforeEach( () => {
		jest.resetModules();
		container = document.createElement( 'div' );
		document.body.appendChild( container );
	} );

	afterEach( () => {
		document.body.removeChild( container );
		container = null;
	} );

	/**
	 * Mock `../../config` (resolved from this test file, the same absolute
	 * path `reconnect-notice.js` reaches via its own `../config`) with the
	 * given values, then render `ReconnectAction` fresh against them.
	 *
	 * @param {Object} config Mocked CAN_MANAGE / RECONNECT_URL values.
	 * @return {HTMLElement} The container the component rendered into.
	 */
	async function renderWithConfig( config ) {
		jest.doMock( '../../config', () => config );
		const { ReconnectAction } = require( '../reconnect-notice' );

		const root = createRoot( container );
		await act( async () => {
			root.render( createElement( ReconnectAction ) );
		} );

		return container;
	}

	test( 'an administrator with a reconnect URL gets a link to it', async () => {
		const node = await renderWithConfig( {
			CAN_MANAGE: true,
			RECONNECT_URL:
				'https://example.com/wp-admin/options-general.php?page=atmosphere',
		} );

		const link = node.querySelector( 'a' );

		expect( link ).not.toBeNull();
		expect( link.getAttribute( 'href' ) ).toBe(
			'https://example.com/wp-admin/options-general.php?page=atmosphere'
		);
		expect( link.textContent ).toBe( 'Reconnect your Bluesky account.' );
	} );

	test( 'an administrator with no reconnect destination gets the no-destination sentence and no link', async () => {
		const node = await renderWithConfig( {
			CAN_MANAGE: true,
			RECONNECT_URL: '',
		} );

		expect( node.querySelector( 'a' ) ).toBeNull();
		expect( node.textContent ).toBe(
			'Reconnect your Bluesky account to fix this.'
		);
	} );

	test( 'a non-admin gets the ask-an-administrator sentence and no link', async () => {
		const node = await renderWithConfig( {
			CAN_MANAGE: false,
			RECONNECT_URL:
				'https://example.com/wp-admin/options-general.php?page=atmosphere',
		} );

		expect( node.querySelector( 'a' ) ).toBeNull();
		expect( node.textContent ).toBe(
			'Ask an administrator to reconnect it.'
		);
	} );
} );
