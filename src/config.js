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
 * Whether the current user can manage the connection (manage_options).
 *
 * Reconnect prompts link to `RECONNECT_URL` only for users who can act on
 * them; everyone else is told to ask an administrator.
 *
 * @type {boolean}
 */
export const CAN_MANAGE = !! options.canManage;

/**
 * The site-level share decision, computed once in PHP.
 *
 * `{ state, message, severity, action, can_share, sharing_enabled }` — see
 * `Atmosphere\share_status()`. The panel renders this rather than deriving
 * the same answer from separate flags, which is what let the editor's two
 * surfaces contradict each other.
 *
 * `can_share` is whether a share could succeed right now; `sharing_enabled`
 * is whether the site cross-posts automatically at all. Both feed the help
 * text under the share toggle, and `state` decides the one case where the
 * panel removes itself entirely (`sharing_off_external`).
 *
 * @type {Object}
 */
export const SHARE_STATUS = options.shareStatus || {
	state: 'ok',
	message: '',
	severity: 'info',
	action: false,
	can_share: true,
	sharing_enabled: true,
};

/**
 * URL of the ATmosphere settings page, for reconnect prompts.
 *
 * @type {string}
 */
export const SETTINGS_URL =
	options.settingsUrl || 'options-general.php?page=atmosphere';

/**
 * Whether the Bluesky connection predates the reply-restriction scope.
 *
 * True means a restriction set in the editor is skipped at publish time
 * until the site reconnects, so the panel says so next to the control.
 *
 * @type {boolean}
 */
export const THREADGATE_NEEDS_RECONNECT = !! options.threadgateNeedsReconnect;

/**
 * Post meta key for the per-post Bluesky reply restriction.
 *
 * @type {string}
 */
export const REPLY_RESTRICTION_META_KEY =
	options.replyRestrictionMetaKey || 'atmosphere_reply_restriction';
