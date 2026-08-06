/**
 * Pure helpers for the ATmosphere document panel.
 *
 * Kept out of `plugin.js` so they can be unit-tested without rendering the
 * editor component.
 */

import { __ } from '@wordpress/i18n';
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
 * Help text under the share toggle: what happens to THIS post.
 *
 * The control describing itself, so it always renders with the control and is
 * never counted as one of the panel's messages.
 *
 * @param {boolean} enabled  Whether sharing is on for this post.
 * @param {boolean} canShare Whether the site can share at all right now.
 * @return {string} Translated help text.
 */
export function shareHelpText( enabled, canShare ) {
	if ( ! enabled ) {
		return __(
			'This post will not be shared via ATmosphere.',
			'atmosphere'
		);
	}

	if ( ! canShare ) {
		// Covers a dead connection and a site that was never connected: in
		// both, the post is queued for a share that cannot happen yet.
		return __(
			'Sharing is on for this post, but it will not be shared until your site is connected to Bluesky.',
			'atmosphere'
		);
	}

	return __(
		'This post will be shared via ATmosphere when published.',
		'atmosphere'
	);
}

/**
 * The one message the panel shows, or null when there is nothing to say.
 *
 * Every state here is a view of the same small set of problems, and showing
 * two at once makes the reader work out which to act on: a dead connection
 * explains both a failed share and a removal that is not happening, so it
 * speaks for them. Highest priority first, and only one ever wins.
 *
 * The site-level half is decided in PHP and arrives whole; this only picks
 * between it and the two post-level cases.
 *
 * @param {Object}  shareStatus          Site decision from `Atmosphere\share_status()`.
 * @param {Object}  post                 Post-level state.
 * @param {boolean} post.enabled         Whether sharing is on for this post.
 * @param {boolean} post.hasRecord       Whether the post is on Bluesky.
 * @param {boolean} post.hasPublishError Whether the last share attempt failed.
 * @return {{kind: string, severity: string, message: string, action: boolean}|null} The message.
 */
export function panelMessage(
	shareStatus,
	{ enabled, hasRecord, hasPublishError }
) {
	if ( shareStatus.message ) {
		return {
			kind: shareStatus.state,
			severity: shareStatus.severity,
			message: shareStatus.message,
			action: !! shareStatus.action,
		};
	}

	// Nothing post-level is worth saying while the site cannot share at all:
	// whatever went wrong or is pending is a consequence of that, and the
	// site either explained it above or deliberately stayed quiet.
	if ( ! shareStatus.can_share ) {
		return null;
	}

	if ( hasPublishError && enabled ) {
		return {
			kind: 'publishError',
			severity: 'error',
			message: '',
			action: false,
		};
	}

	if ( hasRecord && ! enabled ) {
		return {
			kind: 'pendingRemoval',
			severity: 'warning',
			message: __(
				'Sharing is off, but this post is still on Bluesky. It will be removed the next time your site syncs.',
				'atmosphere'
			),
			action: false,
		};
	}

	return null;
}
