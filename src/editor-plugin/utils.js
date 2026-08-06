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
 * Help text under the share toggle.
 *
 * While the connection is dead the toggle still records a preference, so the
 * copy has to describe a delayed share rather than promise one on publish.
 *
 * @param {boolean} enabled     Whether sharing is on for this post.
 * @param {boolean} needsReauth Whether the site's connection needs a reconnect.
 * @return {string} Translated help text.
 */
export function shareHelpText( enabled, needsReauth ) {
	if ( ! enabled ) {
		return __(
			'This post will not be shared via ATmosphere.',
			'atmosphere'
		);
	}

	if ( needsReauth ) {
		return __(
			'Sharing is on for this post, but it will not be shared while your site is disconnected from Bluesky.',
			'atmosphere'
		);
	}

	return __(
		'This post will be shared via ATmosphere when published.',
		'atmosphere'
	);
}

/**
 * The panel's single site-level message, or null when there is nothing to say.
 *
 * One fact, one owner: this is the only place the panel speaks about the site
 * rather than the post, so post-level copy never has to restate any of it.
 * Precedence matters. Sharing being off outranks the connection, because when
 * ATmosphere is not the thing publishing, the connection has no bearing on
 * this screen. Sharing forced off from outside says nothing at all: a host
 * plugin owns that experience and the reader cannot act on the arrangement.
 *
 * @param {boolean} autoPublish       Whether sharing is on for the site.
 * @param {string}  autoPublishNotice Why sharing is off, when worth saying.
 * @param {string}  reauthLead        Cause sentence, empty when there is none.
 * @return {{severity: string, message: string, action: boolean}|null} The message.
 */
export function siteStatus( autoPublish, autoPublishNotice, reauthLead ) {
	if ( ! autoPublish ) {
		return autoPublishNotice
			? { severity: 'info', message: autoPublishNotice, action: false }
			: null;
	}

	if ( reauthLead ) {
		return { severity: 'warning', message: reauthLead, action: true };
	}

	return null;
}
