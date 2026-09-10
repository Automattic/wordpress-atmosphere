/**
 * Pure helpers for the ATmosphere document panel.
 *
 * Kept out of `plugin.js` so they can be unit-tested without rendering the
 * editor component.
 */

import { __ } from '@wordpress/i18n';
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
 * Help text under the share toggle: what happens to THIS post.
 *
 * The control describing itself, so it always renders with the control and is
 * never counted as one of the panel's messages.
 *
 * @param {boolean} enabled        Whether sharing is on for this post.
 * @param {boolean} canShare       Whether the site can share at all right now.
 * @param {boolean} sharingEnabled Whether the site cross-posts automatically.
 * @return {string} Translated help text.
 */
export function shareHelpText( enabled, canShare, sharingEnabled = true ) {
	if ( ! sharingEnabled ) {
		/*
		 * Automatic sharing is off site-wide, so publishing does nothing
		 * either way and the toggle would read as inert. It is not: the meta
		 * it writes is what `is_post_publishable()` reads, so it decides
		 * whether `wp atmosphere backfill` ever touches this post, and it
		 * decides again if the site switches sharing back on. The help text
		 * has to say that, because the control is otherwise load-bearing and
		 * unexplained.
		 */
		return enabled
			? __(
					'Automatic sharing is off for this site. This post is set to be shared if sharing is turned back on, or if the site is backfilled.',
					'atmosphere'
			  )
			: __(
					'Automatic sharing is off for this site. This post is set not to be shared, even if sharing is turned back on or the site is backfilled.',
					'atmosphere'
			  );
	}

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
 * @param {Object}  shareStatus            Site decision from `Atmosphere\share_status()`.
 * @param {Object}  post                   Post-level state.
 * @param {boolean} post.enabled           Whether sharing is on for this post.
 * @param {boolean} post.hasRecord         Whether the post has records on the PDS.
 * @param {boolean} post.hasPublishError   Whether the last share attempt failed.
 * @param {boolean} post.willBeUnpublished Whether the edited state stops it being shareable.
 * @param {boolean} post.isDirty           Whether that edit is still unsaved.
 * @return {{kind: string, severity: string, message: string, action: boolean}|null} The message.
 */
export function panelMessage(
	shareStatus,
	{ enabled, hasRecord, hasPublishError, willBeUnpublished, isDirty }
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

	/*
	 * Removal outranks a failed share, which is the one place the "a dead
	 * connection explains everything" reasoning above does not reach. The
	 * two co-occur normally (a record from a successful publish, then a
	 * later failed update), and the failure copy says "Update the post to
	 * try again" — which is the save that destroys the record. A past
	 * failure neither explains a pending removal nor is excused by one.
	 *
	 * Removal is not undoable: republishing later mints a new record with a
	 * new URL, so the likes, reposts and replies on the old one are gone.
	 * "This post will not be shared" would read like a no-op for that.
	 *
	 * `hasRecord` is the server's own `has_post_records()`, so this follows
	 * whatever the cleanup path would actually delete, documents included.
	 * There is no saved-status requirement, because there is no single route
	 * in: `on_status_change()` handles the publish transitions, and
	 * `on_share_meta_changed()` schedules `atmosphere_update_post` on a bare
	 * toggle save, whose worker deletes for any post that is no longer
	 * publishable but still holds records — a draft that kept its records
	 * included. Holding records at all is the condition that matters.
	 *
	 * Known gap: Move to Trash deletes through REST and redirects without a
	 * render in between, so no client-side guard in this panel can catch it.
	 * Quick Edit, bulk edit, REST, XML-RPC and WP-CLI are outside it too.
	 */
	if ( hasRecord && willBeUnpublished ) {
		return {
			kind: 'pendingRemoval',
			severity: 'warning',
			/*
			 * Tense follows whether the change is still unsaved. The
			 * condition is state, not a dirty edit, so it stays true right
			 * after the save that caused the removal: cron runs in a
			 * separate request, so the records are still there and the post
			 * still reads as unshareable. Repeating the future tense would
			 * tell a reader who just saved that it had not happened yet.
			 */
			message: isDirty
				? __(
						'This post is on Bluesky. Saving this change will remove it from there, and that cannot be undone.',
						'atmosphere'
				  )
				: __(
						'This post is still on Bluesky. It will be removed from there the next time your site syncs, and that cannot be undone.',
						'atmosphere'
				  ),
			action: false,
		};
	}

	if ( hasPublishError && enabled ) {
		return {
			kind: 'publishError',
			severity: 'error',
			message: '',
			action: false,
		};
	}

	return null;
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
