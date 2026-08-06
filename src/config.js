/**
 * Shared editor config, single-sourced from PHP.
 *
 * PHP localizes `window.atmosphereEditor` (see `Block_Editor::script_data()`)
 * so the REST route and the share-toggle meta key live in one place. The
 * fallbacks keep the panels working in a source checkout and keep the unit
 * tests runnable without a browser global.
 */

const options =
	( typeof window !== 'undefined' && window.atmosphereEditor ) || {};

/**
 * Post meta key for the per-post "share to Bluesky" toggle.
 *
 * @type {string}
 */
export const DISABLED_META_KEY =
	options.disabledMetaKey || 'atmosphere_disabled';

/**
 * Post meta key for the per-post custom Bluesky text.
 *
 * @type {string}
 */
export const CUSTOM_TEXT_META_KEY =
	options.customTextMetaKey || 'atmosphere_custom_text';

/**
 * REST path for the pre-publish preview endpoint.
 *
 * @type {string}
 */
export const PREVIEW_PATH =
	options.previewPath || '/atmosphere/1.0/admin/pre-publish-preview';

/**
 * Where the reconnect prompts should link: the settings page, the
 * Connectors screen when the settings page is hidden (connection-only
 * mode), or empty when neither exists.
 *
 * @type {string}
 */
export const RECONNECT_URL = options.reconnectUrl || '';

/**
 * Whether the current user can open the settings page (manage_options).
 *
 * Reconnect prompts link there only for users who can act on them.
 *
 * @type {boolean}
 */
export const CAN_MANAGE = !! options.canManage;

/**
 * Whether the site's Bluesky connection needs to be re-authorized.
 *
 * False on a never-connected site, so the editor only warns about a
 * connection that existed and stopped working.
 *
 * @type {boolean}
 */
export const NEEDS_REAUTH = !! options.needsReauth;

/**
 * Cause sentence for the reconnect warning, composed server-side.
 *
 * Empty when no reconnect is needed. Users without `manage_options` get a
 * generic sentence instead of the recorded cause.
 *
 * @type {string}
 */
export const REAUTH_LEAD = options.reauthLead || '';

/**
 * Whether posts are automatically cross-posted to Bluesky on publish.
 *
 * @type {boolean}
 */
export const AUTO_PUBLISH = !! options.autoPublish;
