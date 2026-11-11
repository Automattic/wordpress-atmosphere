/**
 * Regression test: selecting a suggestion must POST the chosen handle, not the
 * typed prefix. The submit must wait until React commits the chosen handle to
 * the input's DOM value; a synchronous requestSubmit() inside the selection
 * handler would serialize the stale prefix (React 18 batches the state update).
 */

/**
 * External dependencies
 */
// `act` isn't re-exported by @wordpress/element, and react-dom/test-utils' act
// is deprecated (it trips jest-console); import from react directly. react is a
// transitive dep of @wordpress/element, so disable the extraneous-deps rule.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

/**
 * WordPress dependencies
 */
import { createElement, createRoot } from '@wordpress/element';

// Stub the shared typeahead with a "choose" button that fires onChange + onSubmit
// in one handler — exactly what the real component does on a suggestion click —
// with a chosen handle that differs from the typed prefix.
jest.mock( '../../shared/handle-typeahead', () => {
	// Required lazily: jest hoists this factory above the imports, so it can't
	// close over the module-scope `createElement`.
	const { createElement: create } = require( '@wordpress/element' );
	return {
		HandleTypeahead: ( { value, name, id, onChange, onSubmit } ) =>
			create(
				'div',
				null,
				create( 'input', { id, name, value, readOnly: true } ),
				create( 'button', {
					id: 'choose',
					type: 'button',
					onClick: () => {
						onChange( 'alice.bsky.social' );
						onSubmit( 'alice.bsky.social' );
					},
				} )
			),
	};
} );

describe( 'SettingsConnectField submit timing', () => {
	beforeEach( () => {
		window.atmosphereSettingsConnect = { handle: 'ali', typeaheadUrl: '' };
		jest.resetModules();
	} );

	test( 'requestSubmit fires with the chosen handle in the DOM, not the prefix', async () => {
		const { SettingsConnectField } = require( '../index' );

		const form = document.createElement( 'form' );
		const mount = document.createElement( 'div' );
		form.appendChild( mount );
		document.body.appendChild( form );

		let submittedValue = null;
		form.requestSubmit = jest.fn( () => {
			submittedValue = form.querySelector( '#atmosphere_handle' ).value;
		} );

		const root = createRoot( mount );
		await act( async () => {
			root.render( createElement( SettingsConnectField, { form } ) );
		} );

		expect( form.querySelector( '#atmosphere_handle' ).value ).toBe(
			'ali'
		);

		await act( async () => {
			form.querySelector( '#choose' ).dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		} );

		expect( form.requestSubmit ).toHaveBeenCalledTimes( 1 );
		expect( submittedValue ).toBe( 'alice.bsky.social' );
	} );
} );
