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
	'atmosphere_tid_last_ts',
	'atmosphere_refresh_lock',
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
 *
 *  - atmo_dpop_nonce_<md5>          — per-URL DPoP nonces (5 min TTL).
 *    Wildcard MUST stay in lock-step with `Nonce_Storage::PREFIX`
 *    ({@see \Atmosphere\OAuth\Nonce_Storage}). uninstall.php runs
 *    pre-bootstrap, so we can't reference the constant directly
 *    without loading the autoloader here. Anyone renaming the
 *    constant should grep for `atmo_dpop_nonce_` and update both.
 *  - atmosphere_oauth_rate_<user_id>
 *    — per-user OAuth rate-limit counter (15 min TTL).
 *  - atmosphere_profile_<md5>       — reaction-sync profile cache
 *    written by `Reaction_Sync::resolve_author()`. No constant —
 *    grep for `atmosphere_profile_` in includes/.
 *  - atmosphere_last_seen_own_<col> — reaction-sync watermarks per
 *    collection (likes, reposts, posts). Wildcard MUST stay in
 *    lock-step with `Reaction_Sync::OPTION_LAST_SEEN_OWN_PREFIX`
 *    ({@see \Atmosphere\Reaction_Sync}).
 *
 * Query for matching option names first, then route deletion through
 * `delete_transient()` / `delete_option()` so the object cache
 * (Redis, Memcached, `alloptions`) is invalidated alongside the
 * underlying `wp_options` row. A plain SQL `DELETE` against the
 * options table would leave stale values in any persistent cache,
 * so a subsequent reinstall could read them back.
 *
 * Persistent-object-cache caveat: on installs with a drop-in object
 * cache (Redis, Memcached, etc.) `set_transient()` writes to the
 * object cache and bypasses `wp_options` entirely — so the LIKE
 * queries below return zero rows on those installs, and the only
 * dynamic transients that get cleaned are the ones the cache layer
 * has already evicted to the database. The remaining keys age out
 * naturally on their TTL (DPoP nonces: 5 min; profile cache: 1 hr)
 * and contain no decryptable secrets — DPoP nonces are server-issued
 * opaque tokens, profile data is public — so the residual is
 * acceptable. If a future dynamic-key transient is long-lived or
 * holds secret material, switch to a write-time registry pattern.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$atmosphere_transient_rows = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_atmo\_dpop\_nonce\_%'
	    OR option_name LIKE '\_transient\_timeout\_atmo\_dpop\_nonce\_%'
	    OR option_name LIKE '\_transient\_atmosphere\_profile\_%'
	    OR option_name LIKE '\_transient\_timeout\_atmosphere\_profile\_%'
	    OR option_name LIKE '\_transient\_atmosphere\_oauth\_%'
	    OR option_name LIKE '\_transient\_timeout\_atmosphere\_oauth\_%'"
);

$atmosphere_option_rows = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE 'atmosphere\_last\_seen\_own\_%'"
);
// phpcs:enable

foreach ( $atmosphere_transient_rows as $atmosphere_row ) {
	// Strip `_transient_` or `_transient_timeout_` prefix to feed
	// `delete_transient()` the bare key.
	if ( 0 === strpos( $atmosphere_row, '_transient_timeout_' ) ) {
		delete_transient( substr( $atmosphere_row, strlen( '_transient_timeout_' ) ) );
	} elseif ( 0 === strpos( $atmosphere_row, '_transient_' ) ) {
		delete_transient( substr( $atmosphere_row, strlen( '_transient_' ) ) );
	}
}

foreach ( $atmosphere_option_rows as $atmosphere_row ) {
	delete_option( $atmosphere_row );
}
