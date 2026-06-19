/**
 * Backwards-compat shim for `withSyncEvent` (WordPress 6.8+).
 *
 * `withSyncEvent` wraps an Interactivity action so the event object remains
 * synchronously available — required since WordPress 6.9 deprecated the
 * implicit-sync `data-wp-on-async--{event}` directive.
 *
 * On WordPress 6.5–6.7 the helper is undefined. The original `data-wp-on`
 * directive already delivers events synchronously there, so the identity
 * fallback below preserves behavior without bumping the minimum version.
 *
 * The property is read via a `const`-aliased bracket access so webpack does
 * not tree-shake the namespace import into a named binding; a named import
 * would throw at module instantiation on cores that don't export it.
 */
import * as Interactivity from '@wordpress/interactivity';

const key = 'withSyncEvent';
export const withSyncEvent = Interactivity[ key ] ?? ( ( fn ) => fn );
