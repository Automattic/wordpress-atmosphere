/**
 * Pure helpers for the pre-publish panel.
 *
 * Kept out of `plugin.js` so they can be unit-tested without rendering the
 * editor component.
 */

import { __ } from '@wordpress/i18n';

/**
 * Human label for a projected publishing strategy.
 *
 * @param {string} strategy Strategy key from the REST projector.
 * @return {string} Translated label.
 */
export function strategyLabel( strategy ) {
	switch ( strategy ) {
		case 'short-form':
			return __( 'Short note', 'atmosphere' );
		case 'truncate-link':
			return __( 'Text with link', 'atmosphere' );
		case 'teaser-thread':
			return __( 'Teaser thread', 'atmosphere' );
		case 'custom-text':
			return __( 'Custom text', 'atmosphere' );
		// Password-protected posts; the panel shows a reason instead, so
		// this label is a defensive fallback for custom consumers.
		case 'redacted':
			return __( 'Not shared', 'atmosphere' );
		case 'link-card':
		default:
			return __( 'Link card', 'atmosphere' );
	}
}

/**
 * Whether any projected record exceeds the Bluesky limit.
 *
 * @param {Array} records Projected records from the REST projector.
 * @return {boolean} True when at least one record is over the limit.
 */
export function hasOverLimit( records ) {
	return (
		Array.isArray( records ) &&
		records.some( ( record ) => record && record.over_limit )
	);
}
