/**
 * ATmosphere card for the WordPress 7.0 Settings → Connectors screen.
 *
 * Registers ATmosphere as a connector card via the core `@wordpress/connectors`
 * script module and renders the connect / connected / needs-reauth states. The
 * OAuth handshake itself runs server-side: the card asks the plugin's REST
 * routes (see `Atmosphere\Rest\Admin\Connection_Controller`) to mint the
 * authorization URL, then navigates the browser to it; the authorization server
 * redirects back to this screen once the callback completes. As the user types,
 * the handle field offers network-wide suggestions from a typeahead service
 * (its endpoint is supplied — and can be disabled — server-side via
 * `data.typeaheadUrl`); picking a result starts the connection immediately.
 *
 * Only `@wordpress/connectors` is imported (it's a script module, resolved via
 * core's import map). Element, i18n, and components are read from the
 * `window.wp.*` classic-script globals that admin pages always load — the same
 * approach Jetpack's connector card uses — because those packages aren't
 * available as script modules, so a module can't `import` them. That's also why
 * this file uses `createElement` directly instead of JSX.
 *
 * The `@wordpress/connectors` API is still experimental in core, so we read both
 * the `__experimental*` names and the eventual stable names and use whichever the
 * running WordPress exposes.
 */

const { createElement: el, useState, useRef, useEffect } = window.wp.element;
const { __ } = window.wp.i18n;
const { Button, Notice, ExternalLink, Spinner } = window.wp.components;
// Core's own connected-state badge, so ATmosphere's matches its neighbours and
// inherits core's tokens (colours, dark mode) instead of hardcoding any.
const Badge =
	window.wp.components.Badge || window.wp.components.__experimentalBadge;
const HStack =
	window.wp.components.__experimentalHStack || window.wp.components.HStack;
const VStack =
	window.wp.components.__experimentalVStack || window.wp.components.VStack;

const MODULE_ID = '@atmosphere/connectors-card';

const dataEl = document.getElementById(
	`wp-script-module-data-${ MODULE_ID }`
);
const data = ( () => {
	try {
		return JSON.parse( dataEl?.textContent ?? '{}' );
	} catch {
		return {};
	}
} )();

/**
 * POST to one of the plugin's connection REST routes.
 *
 * Uses `fetch` with an explicit `X-WP-Nonce` rather than `@wordpress/api-fetch`
 * so the card carries its own credentials and doesn't depend on core having
 * configured a module-scoped api-fetch nonce on this screen.
 *
 * @param {string} path REST path relative to the REST root.
 * @param {Object} body JSON request body.
 * @return {Promise<Object>} The parsed JSON response.
 * @throws {Error} With the server's message on a non-2xx response.
 */
async function post( path, body ) {
	const res = await fetch( data.restRoot + path, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': data.restNonce,
		},
		body: JSON.stringify( body ),
	} );

	const json = await res.json().catch( () => ( {} ) );

	if ( ! res.ok ) {
		throw new Error(
			json.message ||
				__( 'Something went wrong. Please try again.', 'atmosphere' )
		);
	}

	return json;
}

const TYPEAHEAD_LIMIT = 8;
const TYPEAHEAD_MIN_CHARS = 2;
const TYPEAHEAD_DEBOUNCE_MS = 250;
const SUGGESTIONS_ID = 'atmosphere-handle-suggestions';

/**
 * Query the network-wide handle typeahead for a prefix.
 *
 * Hits the `app.bsky.actor.searchActorsTypeahead` XRPC endpoint configured
 * server-side (CORS-enabled, so the browser calls it directly). Returns an empty
 * list on any failure so a flaky lookup never blocks manual handle entry.
 *
 * @param {string} baseUrl The typeahead XRPC endpoint.
 * @param {string} query   The handle prefix the user has typed.
 * @return {Promise<Array>} Matching actors ({ did, handle, displayName, avatar }).
 */
async function searchHandles( baseUrl, query ) {
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
 * connection; keep typing a full handle and use the Connect button as a manual
 * fallback. Falls back to a plain input when typeahead is disabled server-side
 * (`data.typeaheadUrl` empty).
 *
 * @param {Object}   props          Field props.
 * @param {string}   props.value    Current handle text.
 * @param {boolean}  props.disabled Whether the field is disabled.
 * @param {Function} props.onChange Called with the typed text.
 * @param {Function} props.onSubmit Called with a handle to connect.
 * @return {Object} The rendered field.
 */
function HandleTypeahead( { value, disabled, onChange, onSubmit } ) {
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ open, setOpen ] = useState( false );
	const [ active, setActive ] = useState( -1 );
	const [ loading, setLoading ] = useState( false );
	const timer = useRef( null );
	const seq = useRef( 0 );
	const baseUrl = data.typeaheadUrl || '';

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
		el(
			'label',
			{
				className: 'atmosphere-handle-field__label',
				htmlFor: 'atmosphere-handle-input',
			},
			__( 'AT Protocol handle', 'atmosphere' )
		),
		el(
			'div',
			{ className: 'atmosphere-handle-field__control' },
			el( 'input', {
				id: 'atmosphere-handle-input',
				type: 'text',
				className: 'components-text-control__input',
				role: 'combobox',
				autoComplete: 'off',
				placeholder: 'alice.bsky.social',
				value,
				disabled,
				'aria-expanded': open,
				'aria-controls': SUGGESTIONS_ID,
				'aria-autocomplete': 'list',
				'aria-activedescendant':
					active >= 0 ? `${ SUGGESTIONS_ID }-${ active }` : undefined,
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
					id: SUGGESTIONS_ID,
				},
				suggestions.map( ( actor, i ) =>
					el(
						'li',
						{
							key: actor.did || actor.handle,
							id: `${ SUGGESTIONS_ID }-${ i }`,
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

/**
 * Build the ATmosphere connector card component.
 *
 * Returned as a factory so the resolved ConnectorItem is captured once and the
 * component itself stays a plain function of props, which keeps the hooks below
 * valid.
 *
 * @param {Function} Shell The core ConnectorItem card chrome component.
 * @return {Function} The card component.
 */
function makeCard( Shell ) {
	return function AtmosphereConnectorCard( { name, description, logo } ) {
		const [ handle, setHandle ] = useState( data.handle || '' );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );

		const connect = async ( explicit ) => {
			const target = (
				typeof explicit === 'string' && explicit ? explicit : handle
			).trim();
			if ( ! target || busy ) {
				return;
			}
			setBusy( true );
			setError( '' );
			try {
				// The card only ever runs on the Connectors screen, so the
				// server sends the flow back there after the callback on its
				// own — the card supplies only the handle, never a return URL.
				const json = await post( data.authorizePath, {
					handle: target,
				} );
				window.location.href = json.url;
			} catch ( e ) {
				setError( e.message );
				setBusy( false );
			}
		};

		const disconnect = async () => {
			if ( busy ) {
				return;
			}
			setBusy( true );
			setError( '' );
			try {
				await post( data.disconnectPath, {} );
				window.location.reload();
			} catch ( e ) {
				setError( e.message );
				setBusy( false );
			}
		};

		const connected = !! data.isConnected;
		const needsReauth = !! data.needsReauth;

		let actionArea;
		let body;

		if ( connected ) {
			// Match the neighbouring connector cards: a "Connected" badge sits
			// beside the action button in the header row, rendered with core's
			// own `Badge` component (`intent="success"`) so it inherits core's
			// styling and tokens rather than any hardcoded values. The badge now
			// carries the connected state, so the body drops to a slim
			// `@handle · View profile` line — the two pieces generic connectors
			// don't need but ATmosphere does: which account, and a link to it.
			actionArea = el(
				HStack,
				{ spacing: 3, expanded: false },
				Badge
					? el(
							Badge,
							{ intent: 'success' },
							__( 'Connected', 'atmosphere' )
					  )
					: el( 'span', null, __( 'Connected', 'atmosphere' ) ),
				el(
					Button,
					{
						variant: 'tertiary',
						size: 'compact',
						onClick: disconnect,
						isBusy: busy,
						disabled: busy,
						accessibleWhenDisabled: true,
					},
					__( 'Disconnect', 'atmosphere' )
				)
			);
			body =
				data.handle || data.profileUrl
					? el(
							'p',
							{ className: 'atmosphere-connector-card__status' },
							data.handle && '@' + data.handle,
							data.handle && data.profileUrl && ' · ',
							data.profileUrl &&
								el(
									ExternalLink,
									{ href: data.profileUrl },
									__( 'View profile', 'atmosphere' )
								)
					  )
					: null;
		} else {
			actionArea = el(
				Button,
				{
					variant: 'secondary',
					size: 'compact',
					onClick: () => connect(),
					isBusy: busy,
					disabled: busy || ! handle,
					accessibleWhenDisabled: true,
				},
				needsReauth
					? __( 'Reconnect', 'atmosphere' )
					: __( 'Connect', 'atmosphere' )
			);
			body = el(
				VStack,
				{ spacing: 3 },
				needsReauth &&
					el(
						Notice,
						{ status: 'warning', isDismissible: false },
						__(
							'Your AT Protocol session expired. Reconnect to keep publishing.',
							'atmosphere'
						)
					),
				el( HandleTypeahead, {
					value: handle,
					disabled: busy,
					onChange: setHandle,
					onSubmit: ( h ) => connect( h ),
				} )
			);
		}

		return el(
			Shell,
			{ logo, name, description, actionArea },
			el(
				VStack,
				{ spacing: 3 },
				body,
				error &&
					el(
						Notice,
						{
							status: 'error',
							isDismissible: false,
							onRemove: () => setError( '' ),
						},
						error
					)
			)
		);
	};
}

/**
 * Register the card with core, once the connectors module is available.
 *
 * Imported dynamically so a missing/renamed export degrades to a no-op instead
 * of a module-eval error that would blank the whole screen.
 */
let connectors = null;
try {
	// eslint-disable-next-line import/no-unresolved -- resolved via WP import map at runtime.
	connectors = await import( '@wordpress/connectors' );
} catch {
	connectors = null;
}

if ( connectors ) {
	const registerConnector =
		connectors.__experimentalRegisterConnector ||
		connectors.registerConnector;
	const ConnectorItem =
		connectors.__experimentalConnectorItem || connectors.ConnectorItem;

	// Without the card chrome there's nothing to render into, so skip
	// registration and leave the settings page as the baseline UI.
	if ( registerConnector && ConnectorItem ) {
		registerConnector( data.connectorId || 'atmosphere', {
			render: makeCard( ConnectorItem ),
		} );
	}
}
