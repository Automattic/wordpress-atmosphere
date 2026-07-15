/**
 * Jest setup: expose the `window.wp.*` globals that admin pages load as classic
 * scripts.
 *
 * The shared `HandleTypeahead` module (and the connectors card) read
 * `@wordpress/*` from `window.wp.*` rather than importing them, because a script
 * module can't `import` those packages. Jest's jsdom environment doesn't provide
 * those globals, so stub them here — using the real `@wordpress/element` and
 * `@wordpress/i18n` plus a lightweight `components` shim — so the modules load.
 */

/**
 * WordPress dependencies
 */
import * as element from '@wordpress/element';
import * as i18n from '@wordpress/i18n';

window.wp = {
	...( window.wp || {} ),
	element,
	i18n,
	components: {
		...( window.wp && window.wp.components ),
		Spinner: () => null,
	},
};

// React 18 requires this flag for `act` to work without printing a warning
// that @wordpress/jest-console would fail the test on.
global.IS_REACT_ACT_ENVIRONMENT = true;
