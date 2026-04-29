<?php
/**
 * ATmosphere uninstall script.
 *
 * Cleans up all plugin data when the plugin is deleted.
 *
 * @package Atmosphere
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove options.
delete_option( 'atmosphere_connection' );
delete_option( 'atmosphere_publication_tid' );
delete_option( 'atmosphere_publication_uri' );
delete_option( 'atmosphere_auto_publish' );

// Remove scheduled events.
wp_clear_scheduled_hook( 'atmosphere_refresh_token' );
wp_clear_scheduled_hook( 'atmosphere_publish_post' );
wp_clear_scheduled_hook( 'atmosphere_update_post' );
wp_clear_scheduled_hook( 'atmosphere_delete_post' );
wp_clear_scheduled_hook( 'atmosphere_delete_records' );
wp_clear_scheduled_hook( 'atmosphere_sync_publication' );
wp_clear_scheduled_hook( 'atmosphere_sync_reactions' );
wp_clear_scheduled_hook( 'atmosphere_publish_comment' );
wp_clear_scheduled_hook( 'atmosphere_update_comment' );
wp_clear_scheduled_hook( 'atmosphere_delete_comment' );
wp_clear_scheduled_hook( 'atmosphere_delete_comment_record' );

// Remove post meta.
global $wpdb;

$atmosphere_meta_keys = array(
	'_atmosphere_bsky_tid',
	'_atmosphere_bsky_uri',
	'_atmosphere_bsky_cid',
	'_atmosphere_doc_tid',
	'_atmosphere_doc_uri',
	'_atmosphere_doc_cid',
	'_atmosphere_blob_ref',
);

foreach ( $atmosphere_meta_keys as $atmosphere_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $atmosphere_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

// Remove comment meta written by the outbound-comment publisher.
$atmosphere_comment_meta_keys = array(
	'_atmosphere_bsky_tid',
	'_atmosphere_bsky_uri',
	'_atmosphere_bsky_cid',
	'_atmosphere_publish_attempts',
);

foreach ( $atmosphere_comment_meta_keys as $atmosphere_key ) {
	$wpdb->delete( $wpdb->commentmeta, array( 'meta_key' => $atmosphere_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

// Remove transients.
delete_transient( 'atmosphere_oauth_verifier' );
delete_transient( 'atmosphere_oauth_state' );
delete_transient( 'atmosphere_oauth_dpop_jwk' );
delete_transient( 'atmosphere_oauth_resolved' );
