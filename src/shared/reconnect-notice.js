/**
 * Shared reconnect call to action for the editor panels.
 *
 * The document panel, its publish-error notice, and the pre-publish panel all
 * end on the same two sentences, split by whether the reader can actually fix
 * it. Kept here so the copy and the capability check exist once.
 */

import { __ } from '@wordpress/i18n';
import { CAN_MANAGE, RECONNECT_URL } from '../config';

/**
 * What to do about a dead connection, addressed to the current user.
 *
 * @return {React.JSX.Element|string} A link for administrators, plain text for everyone else.
 */
export function ReconnectAction() {
	// Someone who can't manage options has no fix of their own to offer.
	if ( ! CAN_MANAGE ) {
		return __( 'Ask an administrator to reconnect it.', 'atmosphere' );
	}

	// An administrator with nowhere to go (settings hidden, no Connectors
	// screen either) must not be told to ask an administrator about it.
	if ( ! RECONNECT_URL ) {
		return __(
			'Reconnect your Bluesky account to fix this.',
			'atmosphere'
		);
	}

	return (
		<a href={ RECONNECT_URL }>
			{ __( 'Reconnect your Bluesky account.', 'atmosphere' ) }
		</a>
	);
}
