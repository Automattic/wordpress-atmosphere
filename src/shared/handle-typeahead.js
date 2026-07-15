/**
 * Shared AT Protocol handle field with network-wide typeahead.
 *
 * Used by both the Settings → Connectors card (a script module) and the
 * Settings → ATmosphere connect field (a classic script). Reads `@wordpress/*`
 * from the `window.wp.*` globals rather than importing them, because a script
 * module can't `import` those packages — the same constraint the card was
 * written under — which is also why this uses `createElement` directly, no JSX.
 */

const { createElement: el, useState, useRef, useEffect } = window.wp.element;
const { __ } = window.wp.i18n;
const { Spinner } = window.wp.components;

const TYPEAHEAD_LIMIT = 8;
const TYPEAHEAD_MIN_CHARS = 2;
const TYPEAHEAD_DEBOUNCE_MS = 250;

/**
 * Query the network-wide handle typeahead for a prefix.
 *
 * Hits the `app.bsky.actor.searchActorsTypeahead` XRPC endpoint configured
 * server-side (CORS-enabled, so the browser calls it directly). Returns an
 * empty list on any failure so a flaky lookup never blocks manual handle entry.
 *
 * @param {string} baseUrl The typeahead XRPC endpoint.
 * @param {string} query   The handle prefix the user has typed.
 * @return {Promise<Array>} Matching actors ({ did, handle, displayName, avatar }).
 */
export async function searchHandles( baseUrl, query ) {
	try {
		const url = `${ baseUrl }?q=${ encodeURIComponent(
			query
		) }&limit=${ TYPEAHEAD_LIMIT }`;
		const res = await fetch( url, {
			headers: { Accept: 'application/json' },
		} );
		if ( ! res.ok ) {
			return [];
		}
		const json = await res.json().catch( () => ( {} ) );
		return Array.isArray( json.actors ) ? json.actors : [];
	} catch {
		return [];
	}
}

/**
 * Handle field with network-wide typeahead.
 *
 * Debounces lookups, shows avatar + handle suggestions, and follows the atproto
 * login checklist: click a result or press Enter to select and start the
 * connection; keep typing a full handle and use the surrounding submit button as
 * a manual fallback. Falls back to a plain input when typeahead is disabled
 * (`typeaheadUrl` empty).
 *
 * @param {Object}   props                  Field props.
 * @param {string}   props.value            Current handle text.
 * @param {boolean}  props.disabled         Whether the field is disabled.
 * @param {string}   props.typeaheadUrl     Typeahead XRPC endpoint ('' disables it).
 * @param {Function} props.onChange         Called with the typed text.
 * @param {Function} props.onSubmit         Called with a handle to connect.
 * @param {string}   [props.name]           Input `name` attribute (for classic form POST).
 * @param {string}   [props.id]             Input id; suggestion ids derive from it.
 * @param {boolean}  [props.showLabel]      Render the field's own label (default true).
 * @param {string}   [props.inputClassName] Class for the input element.
 * @return {Object} The rendered field.
 */
export function HandleTypeahead( {
	value,
	disabled,
	typeaheadUrl,
	onChange,
	onSubmit,
	name,
	id = 'atmosphere-handle-input',
	showLabel = true,
	inputClassName = 'components-text-control__input',
} ) {
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ open, setOpen ] = useState( false );
	const [ active, setActive ] = useState( -1 );
	const [ loading, setLoading ] = useState( false );
	const timer = useRef( null );
	const seq = useRef( 0 );
	const baseUrl = typeaheadUrl || '';
	const suggestionsId = `${ id }-suggestions`;

	// Cancel any pending debounce when the field unmounts.
	useEffect( () => () => clearTimeout( timer.current ), [] );

	const runSearch = ( text ) => {
		const query = text.trim();
		if ( ! baseUrl || query.length < TYPEAHEAD_MIN_CHARS ) {
			setSuggestions( [] );
			setOpen( false );
			setActive( -1 );
			setLoading( false );
			return;
		}
		// Guard against out-of-order responses: only the latest query wins.
		const mine = ++seq.current;
		setLoading( true );
		searchHandles( baseUrl, query ).then( ( actors ) => {
			if ( mine !== seq.current ) {
				return;
			}
			setSuggestions( actors );
			setOpen( actors.length > 0 );
			setActive( actors.length ? 0 : -1 );
			setLoading( false );
		} );
	};

	const onInput = ( text ) => {
		onChange( text );
		clearTimeout( timer.current );
		timer.current = setTimeout(
			() => runSearch( text ),
			TYPEAHEAD_DEBOUNCE_MS
		);
	};

	const choose = ( handle ) => {
		setOpen( false );
		onChange( handle );
		onSubmit( handle );
	};

	const onKeyDown = ( e ) => {
		if ( 'ArrowDown' === e.key ) {
			e.preventDefault();
			setOpen( suggestions.length > 0 );
			setActive( ( i ) => Math.min( i + 1, suggestions.length - 1 ) );
		} else if ( 'ArrowUp' === e.key ) {
			e.preventDefault();
			setActive( ( i ) => Math.max( i - 1, 0 ) );
		} else if ( 'Enter' === e.key ) {
			e.preventDefault();
			if ( open && suggestions.length ) {
				choose( suggestions[ active >= 0 ? active : 0 ].handle );
			} else if ( value.trim() ) {
				onSubmit( value.trim() );
			}
		} else if ( 'Escape' === e.key ) {
			setOpen( false );
		}
	};

	return el(
		'div',
		{ className: 'atmosphere-handle-field' },
		showLabel &&
			el(
				'label',
				{
					className: 'atmosphere-handle-field__label',
					htmlFor: id,
				},
				__( 'AT Protocol handle', 'atmosphere' )
			),
		el(
			'div',
			{ className: 'atmosphere-handle-field__control' },
			el( 'input', {
				id,
				name,
				type: 'text',
				className: inputClassName,
				role: 'combobox',
				autoComplete: 'off',
				placeholder: 'alice.bsky.social',
				value,
				disabled,
				'aria-expanded': open,
				'aria-controls': suggestionsId,
				'aria-autocomplete': 'list',
				'aria-activedescendant':
					active >= 0 ? `${ suggestionsId }-${ active }` : undefined,
				onChange: ( e ) => onInput( e.target.value ),
				onKeyDown,
				onFocus: () => setOpen( suggestions.length > 0 ),
				// Delay so a suggestion click (mousedown) resolves before blur
				// tears the list down.
				onBlur: () => setTimeout( () => setOpen( false ), 150 ),
			} ),
			loading && el( Spinner, null )
		),
		open &&
			suggestions.length > 0 &&
			el(
				'ul',
				{
					className: 'atmosphere-handle-field__list',
					role: 'listbox',
					id: suggestionsId,
				},
				suggestions.map( ( actor, i ) =>
					el(
						'li',
						{
							key: actor.did || actor.handle,
							id: `${ suggestionsId }-${ i }`,
							role: 'option',
							'aria-selected': i === active,
							className:
								'atmosphere-handle-field__option' +
								( i === active ? ' is-active' : '' ),
							// mousedown fires before the input's blur, so the
							// choice registers before the list is dismissed.
							onMouseDown: ( e ) => {
								e.preventDefault();
								choose( actor.handle );
							},
							onMouseEnter: () => setActive( i ),
						},
						actor.avatar &&
							el( 'img', {
								className: 'atmosphere-handle-field__avatar',
								src: actor.avatar,
								alt: '',
								width: 24,
								height: 24,
							} ),
						el(
							'span',
							{ className: 'atmosphere-handle-field__handle' },
							'@' + actor.handle
						),
						actor.displayName &&
							el(
								'span',
								{ className: 'atmosphere-handle-field__name' },
								actor.displayName
							)
					)
				)
			)
	);
}
