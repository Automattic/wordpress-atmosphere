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
 * URL of the ATmosphere settings page, for reconnect prompts.
 *
 * @type {string}
 */
export const SETTINGS_URL =
	options.settingsUrl || 'options-general.php?page=atmosphere';

/**
 * Whether the current user can open the settings page (manage_options).
 *
 * Reconnect prompts link there only for users who can act on them.
 *
 * @type {boolean}
 */
export const CAN_MANAGE = !! options.canManage;

/**
 * Whether the site holds a live Bluesky connection.
 *
 * False also covers a lapsed connection (needs re-authorization), so the
 * document panel can warn that a share-on post won't actually be shared.
 * Defaults to connected in a source checkout / tests so the panel doesn't
 * cry wolf when PHP hasn't localized the flag.
 *
 * @type {boolean}
 */
export const IS_CONNECTED =
	options.isConnected === undefined ? true : !! options.isConnected;
