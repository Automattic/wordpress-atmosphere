<?php
/**
 * Helper functions for ATmosphere.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * Parse an AT-URI into components.
 *
 * @param string $uri AT-URI (at://did/collection/rkey).
 * @return array{did: string, collection: string, rkey: string}|false
 */
function parse_at_uri( string $uri ): array|false {
	if ( ! \str_starts_with( $uri, 'at://' ) ) {
		return false;
	}

	$segments = \explode( '/', \substr( $uri, 5 ) );

	if ( \count( $segments ) < 3 ) {
		return false;
	}

	return array(
		'did'        => $segments[0],
		'collection' => $segments[1],
		'rkey'       => $segments[2],
	);
}

/**
 * Build an AT-URI from components.
 *
 * @param string $did        DID.
 * @param string $collection Collection NSID.
 * @param string $rkey       Record key.
 * @return string
 */
function build_at_uri( string $did, string $collection, string $rkey ): string {
	return "at://{$did}/{$collection}/{$rkey}";
}

/**
 * Decode entities, strip HTML, normalise whitespace.
 *
 * @param string $text Raw text.
 * @return string Clean text.
 */
function sanitize_text( string $text ): string {
	// Decode BEFORE stripping. WordPress stores many strings HTML-entity
	// encoded (esc_html at save time), so an entity-encoded tag such as
	// `&lt;script&gt;` arrives with no literal angle brackets. Stripping
	// first would leave it untouched and the later decode would turn it
	// into live `<script>` markup in the record. Decoding first turns it
	// into a real tag that wp_strip_all_tags then removes.
	$text = \html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	$text = \wp_strip_all_tags( $text );
	// `/u` matches Unicode whitespace too — without it NBSP (U+00A0),
	// ideographic space (U+3000), and similar survive both this collapse
	// and the trim() below, masquerading as real prose downstream.
	// PCRE in `/u` mode returns null on invalid UTF-8; fall back to the
	// pre-replacement text so trim() doesn't TypeError on PHP 8.1+.
	$collapsed = \preg_replace( '/\s+/u', ' ', $text );
	$text      = \is_string( $collapsed ) ? $collapsed : $text;

	return \trim( $text );
}

/**
 * Hard-clamp a string to an AT Protocol `maxGraphemes` limit.
 *
 * Uses `grapheme_substr` when the `intl` extension is loaded — the
 * spec-exact form, matching the way Lexicon counts characters. Falls
 * back to `mb_substr` (code points) otherwise: every grapheme is at
 * least one code point, so a code-point clamp at `$max_graphemes` is
 * always within the grapheme limit, just sometimes more conservative
 * than needed for emoji-heavy or combining-character text.
 *
 * A non-positive `$max_graphemes` returns an empty string. Both
 * `grapheme_substr()` and `mb_substr()` would otherwise interpret a
 * negative length as "drop the last N characters" — not a clamp, and
 * the opposite of what every caller wants.
 *
 * No marker is appended — used for canonical fields like the
 * `site.standard.publication` `name` / `description`, where adding
 * `…` would mislead consumers about the original length and burn
 * grapheme budget. Callers that want an ellipsis on excerpts should
 * use {@see truncate_text()} instead.
 *
 * @param string $text          Text to clamp.
 * @param int    $max_graphemes Maximum graphemes.
 * @return string
 */
function truncate_graphemes( string $text, int $max_graphemes ): string {
	if ( $max_graphemes <= 0 ) {
		return '';
	}

	if ( \function_exists( 'grapheme_strlen' ) ) {
		$length = \grapheme_strlen( $text );

		/*
		 * `grapheme_strlen()` returns null for invalid UTF-8. Falling
		 * through to the `mb_*` branch instead of returning unchanged
		 * keeps the clamp load-bearing — a malformed-and-oversized
		 * blogname must still leave with a bounded length even if the
		 * grapheme count is indeterminate.
		 */
		if ( null !== $length ) {
			if ( $length <= $max_graphemes ) {
				return $text;
			}
			$clamped = \grapheme_substr( $text, 0, $max_graphemes );
			if ( \is_string( $clamped ) ) {
				return $clamped;
			}
		}
	}

	if ( \mb_strlen( $text ) <= $max_graphemes ) {
		return $text;
	}

	return \mb_substr( $text, 0, $max_graphemes );
}

/**
 * Truncate text to a character limit, breaking at word boundaries.
 *
 * @param string $text   Text to truncate.
 * @param int    $limit  Maximum characters (mb_strlen code points).
 * @param string $marker Ellipsis marker.
 * @return string
 */
function truncate_text( string $text, int $limit = 300, string $marker = '...' ): string {
	if ( \mb_strlen( $text ) <= $limit ) {
		return $text;
	}

	$cut       = \mb_substr( $text, 0, $limit - \mb_strlen( $marker ) );
	$last_word = \mb_strrpos( $cut, ' ' );

	if ( $last_word && $last_word > $limit * 0.8 ) {
		$cut = \mb_substr( $cut, 0, $last_word );
	}

	return $cut . $marker;
}

/**
 * Convert a WordPress GMT datetime to ISO 8601.
 *
 * @param string $datetime GMT datetime string.
 * @return string
 */
function to_iso8601( string $datetime ): string {
	return \gmdate( 'Y-m-d\TH:i:s.000\Z', \strtotime( $datetime ) );
}

/**
 * Get the stored connection (OAuth credentials + ephemeral state).
 *
 * Normalizes non-array values to an empty array so a corrupted
 * `atmosphere_connection` option (e.g. an admin overwrote it with a
 * scalar via wp-cli or a misbehaving import plugin) cannot raise a
 * TypeError at every caller's `: array` return-type check. The
 * `admin_notices` hook in particular composes this with other
 * checks during page render — a TypeError there whitescreens the
 * admin until the row is repaired.
 *
 * @return array
 */
function get_connection(): array {
	$conn = \get_option( 'atmosphere_connection', array() );
	return \is_array( $conn ) ? $conn : array();
}

/**
 * Get the persisted AT Protocol identity (DID, handle, PDS endpoint).
 *
 * Identity is stored separately from the OAuth credentials so that a
 * failed token refresh — which clears the live session — does not also
 * wipe the bidirectional verification headers (`.well-known/atproto-did`
 * and the `<link rel="site.standard.document">` tag). On a legacy
 * connection that still embeds the identity inside `atmosphere_connection`
 * this performs a one-shot lazy migration into the new option.
 *
 * @return array{did?: string, handle?: string, pds_endpoint?: string}
 */
function get_identity(): array {
	$identity = \get_option( 'atmosphere_identity', array() );

	if ( ! empty( $identity['did'] ) ) {
		return $identity;
	}

	$conn = get_connection();

	if ( empty( $conn['did'] ) ) {
		return array();
	}

	$identity = array(
		'did'          => (string) $conn['did'],
		'handle'       => (string) ( $conn['handle'] ?? '' ),
		'pds_endpoint' => (string) ( $conn['pds_endpoint'] ?? '' ),
	);

	\update_option( 'atmosphere_identity', $identity, true );

	return $identity;
}

/**
 * Whether a persisted AT Protocol identity is on file.
 *
 * Drives the public verification headers and the settings UI's
 * publishing section so they keep functioning across token expiry.
 *
 * @return bool
 */
function has_identity(): bool {
	return ! empty( get_identity()['did'] );
}

/**
 * Whether the plugin holds a live OAuth session against the PDS.
 *
 * Returns false when the credentials are missing OR the connection
 * is flagged `needs_reauth` (last refresh attempt was rejected with
 * a permanent error). Use `has_identity()` for code paths that only
 * need the site's DID/handle and do not require live credentials.
 *
 * @return bool
 */
function is_connected(): bool {
	if ( ! has_identity() ) {
		return false;
	}

	$conn = get_connection();

	if ( ! empty( $conn['needs_reauth'] ) ) {
		return false;
	}

	return ! empty( $conn['access_token'] );
}

/**
 * Whether the connection requires the user to re-authorize.
 *
 * True when an identity is on file but the credentials option is
 * missing, empty, or flagged `needs_reauth` after a permanent OAuth
 * refresh failure. False on a never-connected site.
 *
 * @return bool
 */
function needs_reauth(): bool {
	if ( ! has_identity() ) {
		return false;
	}

	$conn = get_connection();

	return ! empty( $conn['needs_reauth'] ) || empty( $conn['access_token'] );
}

/**
 * Get the connected DID.
 *
 * @return string
 */
function get_did(): string {
	return (string) ( get_identity()['did'] ?? '' );
}

/**
 * Get the connected PDS endpoint.
 *
 * @return string
 */
function get_pds_endpoint(): string {
	return (string) ( get_identity()['pds_endpoint'] ?? '' );
}

/**
 * Plugin-owned WP-Cron hooks.
 *
 * Single source of truth for `deactivate()`, `Client::disconnect()`, and
 * `uninstall.php`. Keeping the lists in sync prevents queued events from
 * a previous install/connection from firing against the current one and
 * (worst case) issuing applyWrites against a different repo.
 *
 * @return string[]
 */
function get_cron_hooks(): array {
	return array(
		'atmosphere_refresh_token',
		'atmosphere_sync_reactions',
		'atmosphere_sync_publication',
		'atmosphere_publish_post',
		'atmosphere_update_post',
		'atmosphere_delete_post',
		'atmosphere_delete_records',
		'atmosphere_publish_comment',
		'atmosphere_update_comment',
		'atmosphere_delete_comment',
		'atmosphere_delete_comment_record',
		'atmosphere_run_historical_visibility_cleanup',
		// Legacy hook from an early build of the comment publisher; cleared
		// for users upgrading from that snapshot.
		'atmosphere_sync_comments',
	);
}

/**
 * Clear every plugin-owned scheduled hook used during disconnect.
 *
 * The `atmosphere_revoke_refresh_token` event is intentionally NOT
 * cleared here. `Client::disconnect()` schedules it AFTER this helper
 * runs so a slow auth server cannot block the admin click; including
 * it in the loop would clear the event we just queued. The cron
 * worker is a one-shot best-effort POST; once it fires (or its
 * scheduled-event row ages out), there is nothing local to clean up.
 *
 * `deactivate()` and uninstall use {@see clear_scheduled_hooks_all()}
 * instead because at that point the plugin is going away and the
 * still-queued revoke event would orphan encrypted ciphertexts in
 * `wp_options['cron']` forever — WP-Cron does not auto-drop rows
 * whose callbacks are no longer registered.
 */
function clear_scheduled_hooks(): void {
	foreach ( get_cron_hooks() as $hook ) {
		\wp_clear_scheduled_hook( $hook );
	}
}

/**
 * Clear every plugin-owned scheduled hook, including the one-shot
 * revocation hook `Client::disconnect()` defers cleanup of.
 *
 * Use at plugin deactivation / uninstall so a queued revoke event
 * does not sit in `wp_options['cron']` with encrypted ciphertext
 * waiting for a callback that no longer exists.
 */
function clear_scheduled_hooks_all(): void {
	clear_scheduled_hooks();
	\wp_clear_scheduled_hook( 'atmosphere_revoke_refresh_token' );
}

/**
 * Get post types that publish to AT Protocol.
 *
 * @return string[] Post type slugs.
 */
function get_supported_post_types(): array {
	return Post_Types::get_supported();
}

/**
 * Whether a post type publishes to AT Protocol.
 *
 * @param string $post_type Post type slug.
 * @return bool
 */
function is_supported_post_type( string $post_type ): bool {
	return Post_Types::supports( $post_type );
}

/**
 * Whether a post is currently eligible for AT Protocol publishing.
 *
 * Federation output is remote, site-wide state. Do not use
 * post_password_required() here: it depends on the current visitor's
 * unlock cookie and can leak protected content into PDS records.
 *
 * @param \WP_Post $post Post object.
 * @return bool
 */
function is_post_publishable( \WP_Post $post ): bool {
	return 'publish' === $post->post_status
		&& '' === (string) $post->post_password
		&& is_supported_post_type( $post->post_type );
}
