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
