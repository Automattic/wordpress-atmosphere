/**
 * Progressive-enhancement typeahead for the Settings → ATmosphere connect field.
 *
 * The settings page renders a plain `<input name="atmosphere_handle">` inside a
 * classic `<form action="options.php">`; submitting it runs the field's sanitize
 * callback, which resolves the handle and redirects into the OAuth flow. This
 * script replaces that input with the shared `HandleTypeahead` (keeping the same
 * `name`, so a no-JS page still posts the handle), and — matching the Connectors
 * card — submits the form the moment a suggestion is chosen, kicking off the
 * connection immediately.
 *
 * A classic script (not a script module): it reads `window.wp.*` globals, and
 * its `wp-element`/`wp-components`/`wp-i18n` dependencies are declared at enqueue
 * time (see Admin::enqueue_assets), since classic admin pages don't load them by
 * default.
 */

import { HandleTypeahead } from '../shared/handle-typeahead';

const {
	createElement: el,
	createRoot,
	useState,
	useEffect,
} = window.wp.element;

const config =
	( typeof window !== 'undefined' && window.atmosphereSettingsConnect ) || {};

/**
 * The mounted field: owns the handle value and submits its form on selection.
 *
 * Selecting a suggestion sets the handle and flags a pending submit; the actual
 * `form.requestSubmit()` runs from an effect AFTER React commits the new value
 * to the input's DOM node. Submitting synchronously inside the selection handler
 * would serialize the input's pre-selection value — React 18 batches the state
 * update — posting the typed prefix instead of the chosen handle.
 *
 * @param {Object}      props      Component props.
 * @param {HTMLElement} props.form The settings form to submit on connect.
 * @return {Object} The rendered field.
 */
export function SettingsConnectField( { form } ) {
	const [ handle, setHandle ] = useState( config.handle || '' );
	const [ pendingSubmit, setPendingSubmit ] = useState( false );

	useEffect( () => {
		if ( pendingSubmit && form && handle.trim() ) {
			setPendingSubmit( false );
			// requestSubmit() runs the form's normal submit (validation + POST
			// to options.php), so the handle's sanitize callback redirects to
			// OAuth — the same path the "Save Changes" button takes today. By
			// the time this effect runs, the chosen handle is committed to the
			// input's DOM value, so the POST carries it, not the typed prefix.
			form.requestSubmit();
		}
	}, [ pendingSubmit, handle, form ] );

	const submit = ( value ) => {
		setHandle( value );
		if ( value.trim() ) {
			setPendingSubmit( true );
		}
	};

	return el( HandleTypeahead, {
		value: handle,
		disabled: false,
		typeaheadUrl: config.typeaheadUrl || '',
		name: 'atmosphere_handle',
		id: 'atmosphere_handle',
		showLabel: false,
		inputClassName: 'regular-text',
		onChange: setHandle,
		onSubmit: submit,
	} );
}

/**
 * Replace the plain handle input with the typeahead field once the DOM is ready.
 */
function enhance() {
	const input = document.getElementById( 'atmosphere_handle' );
	if ( ! input ) {
		return;
	}
	const form = input.closest( 'form' );
	const mount = document.createElement( 'div' );
	mount.className = 'atmosphere-handle-typeahead-mount';
	input.replaceWith( mount );
	createRoot( mount ).render( el( SettingsConnectField, { form } ) );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', enhance );
} else {
	enhance();
}
