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
	// No page to send an administrator to (settings hidden, no Connectors
	// screen either) is the same dead end as not being able to manage
	// options in the first place.
	if ( ! CAN_MANAGE || ! RECONNECT_URL ) {
		return __( 'Ask an administrator to reconnect it.', 'atmosphere' );
	}

	return (
		<a href={ RECONNECT_URL }>
			{ __( 'Reconnect on the settings page.', 'atmosphere' ) }
		</a>
	);
}
