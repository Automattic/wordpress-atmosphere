/**
 * Pure helpers for the ATmosphere document panel.
 *
 * Kept out of `plugin.js` so they can be unit-tested without rendering the
 * editor component.
 */

import { DISABLED_META_KEY, REPLY_RESTRICTION_META_KEY } from '../config';

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
 * Reply-restriction audience tokens.
 *
 * Mirror the `Threadgate::AUDIENCE_*` constants on the PHP side, which are
 * the source of truth. `NOBODY` is special: it means "no one can reply"
 * rather than an allowed audience.
 *
 * @type {{NOBODY: string, MENTIONED: string, FOLLOWING: string, FOLLOWER: string}}
 */
export const REPLY_AUDIENCE = {
	NOBODY: 'nobody',
	MENTIONED: 'mentioned',
	FOLLOWING: 'following',
	FOLLOWER: 'follower',
};

/**
 * The stored reply-restriction tokens for a post, as an array.
 *
 * Tolerates missing/undefined meta (a fresh post) and a non-array value.
 *
 * @param {Object} meta The post's meta object from the entity store.
 * @return {string[]} The restriction tokens (empty means "everybody").
 */
export function readReplyRestriction( meta ) {
	const value = meta && meta[ REPLY_RESTRICTION_META_KEY ];
	return Array.isArray( value ) ? value : [];
}

/**
 * The top-level reply mode implied by a restriction array.
 *
 * @param {string[]} restriction Restriction tokens.
 * @return {'everybody'|'nobody'|'custom'} The mode.
 */
export function getReplyMode( restriction ) {
	if ( restriction.includes( REPLY_AUDIENCE.NOBODY ) ) {
		return 'nobody';
	}
	return restriction.length ? 'custom' : 'everybody';
}

/**
 * The allowed audiences in a restriction, excluding the "nobody" marker.
 *
 * @param {string[]} restriction Restriction tokens.
 * @return {string[]} Allowed-audience tokens.
 */
export function getReplyAudiences( restriction ) {
	return restriction.filter( ( token ) => token !== REPLY_AUDIENCE.NOBODY );
}

/**
 * Build the restriction array for a chosen mode, preserving audiences.
 *
 * @param {'everybody'|'nobody'|'custom'} mode      Chosen mode.
 * @param {string[]}                      audiences Current allowed audiences.
 * @return {string[]} The restriction to store.
 */
export function buildRestrictionForMode( mode, audiences ) {
	if ( 'nobody' === mode ) {
		return [ REPLY_AUDIENCE.NOBODY ];
	}
	if ( 'custom' === mode ) {
		return audiences;
	}
	return [];
}

/**
 * Add or remove an allowed audience from a restriction.
 *
 * @param {string[]} restriction Current restriction tokens.
 * @param {string}   token       Audience token to toggle.
 * @param {boolean}  on          Whether the audience is allowed.
 * @return {string[]} The updated restriction.
 */
export function toggleReplyAudience( restriction, token, on ) {
	const audiences = new Set( getReplyAudiences( restriction ) );
	if ( on ) {
		audiences.add( token );
	} else {
		audiences.delete( token );
	}
	return [ ...audiences ];
}
