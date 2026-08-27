/**
 * Pure helpers for the ATmosphere document panel.
 *
 * Kept out of `plugin.js` so they can be unit-tested without rendering the
 * editor component.
 */

import { DISABLED_META_KEY } from '../config';

/**
 * Whether sharing is enabled for the post, given its meta.
 *
 * Sharing is opt-out: it is on unless the share-toggle meta is truthy.
 * Tolerates missing/undefined meta (a fresh post).
 *
 * @param {Object} meta The post's meta object from the entity store.
 * @return {boolean} True when the post will be shared.
 */
export function isSharingEnabled( meta ) {
	return ! ( meta && meta[ DISABLED_META_KEY ] );
}

/**
 * Whether to warn that the post won't be shared because the site is offline.
 *
 * Shown only when sharing is on for this post (an off post won't be shared
 * regardless) and the site has no live Bluesky connection. Suppressed while a
 * prior publish already failed with a reconnect prompt, so the panel doesn't
 * stack two near-identical connection notices.
 *
 * @param {Object}  args                   Named arguments.
 * @param {boolean} args.enabled           Whether sharing is on for this post.
 * @param {boolean} args.isConnected       Whether the site holds a live connection.
 * @param {boolean} args.hasReconnectError Whether a publish already failed needing reconnect.
 * @return {boolean} True when the not-connected notice should render.
 */
export function shouldShowNotConnectedNotice( {
	enabled,
	isConnected,
	hasReconnectError,
} ) {
	return enabled && ! isConnected && ! hasReconnectError;
}
