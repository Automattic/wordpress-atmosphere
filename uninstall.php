<?php
/**
 * ATmosphere uninstall script.
 *
 * Cleans up all plugin data when the plugin is deleted.
 *
 * @package Atmosphere
 */

use function Atmosphere\clear_scheduled_hooks;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Load helpers so the cron-hook list stays in lock-step with
 * deactivate() and Client::disconnect(). uninstall.php is loaded by
 * WordPress without the plugin itself being booted, so this require
 * is necessary.
 */
require_once __DIR__ . '/includes/functions.php';

// Remove options.
$atmosphere_options = array(
	'atmosphere_connection',
	'atmosphere_publication_tid',
	'atmosphere_publication_uri',
	'atmosphere_auto_publish',
	'atmosphere_previous_handle',
	'atmosphere_long_form_composition',
	'atmosphere_support_post_types',
	'atmosphere_last_seen_notification',
);

foreach ( $atmosphere_options as $atmosphere_option ) {
	delete_option( $atmosphere_option );
}

// Remove scheduled events via the canonical helper.
clear_scheduled_hooks();

global $wpdb;

// Remove post meta written by the publisher and document transformer.
$atmosphere_meta_keys = array(
	'_atmosphere_bsky_tid',
	'_atmosphere_bsky_uri',
	'_atmosphere_bsky_cid',
	'_atmosphere_bsky_thread_records',
	'_atmosphere_bsky_uri_index',
	'_atmosphere_bsky_orphan_records',
	'_atmosphere_doc_tid',
	'_atmosphere_doc_uri',
	'_atmosphere_doc_cid',
	'_atmosphere_doc_ref_pending',
	'_atmosphere_blob_ref',
);

foreach ( $atmosphere_meta_keys as $atmosphere_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $atmosphere_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

/*
 * Remove comment meta. Two surfaces here:
 *  - outbound: comment-publishing TID/URI/CID + attempt counter.
 *  - inbound:  reaction-sync stamps every imported reaction with a
 *              protocol marker + author DID + author avatar URL so
 *              the gravatar override + dedupe-by-protocol checks work.
 *
 * The plugin-prefixed keys are safe to wipe by key alone. The
 * `protocol` key, however, is unprefixed and could conceivably be
 * written by other plugins for their own purposes, so we scope the
 * delete to the `'atproto'` value that ATmosphere itself writes.
 */
$atmosphere_comment_meta_keys = array(
	'_atmosphere_bsky_tid',
	'_atmosphere_bsky_uri',
	'_atmosphere_bsky_cid',
	'_atmosphere_publish_attempts',
	'_atmosphere_author_did',
	'_atmosphere_author_avatar',
);

foreach ( $atmosphere_comment_meta_keys as $atmosphere_key ) {
	$wpdb->delete( $wpdb->commentmeta, array( 'meta_key' => $atmosphere_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->delete(
	$wpdb->commentmeta,
	array(
		'meta_key'   => 'protocol',
		'meta_value' => 'atproto',
	)
);
// phpcs:enable

// Remove fixed-key transients.
$atmosphere_transients = array(
	'atmosphere_oauth_verifier',
	'atmosphere_oauth_state',
	'atmosphere_oauth_dpop_jwk',
	'atmosphere_oauth_resolved',
	'atmosphere_invalid_long_form_composition_logged',
);

foreach ( $atmosphere_transients as $atmosphere_transient ) {
	delete_transient( $atmosphere_transient );
}

/*
 * Remove transient + option families that use dynamic keys:
 *  - atmo_dpop_nonce_<md5>          — per-URL DPoP nonces (5 min TTL).
 *  - atmosphere_last_seen_own_<col> — reaction-sync watermarks per
 *                                     collection (likes, reposts, posts).
 *
 * Direct DB delete is the only way to wildcard-match these. The
 * `_transient_` / `_transient_timeout_` rows for any object-cache-backed
 * site fall through to the DB anyway, since uninstall runs without the
 * plugin loaded.
 */
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_atmo\_dpop\_nonce\_%' OR option_name LIKE '\_transient\_timeout\_atmo\_dpop\_nonce\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'atmosphere\_last\_seen\_own\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
