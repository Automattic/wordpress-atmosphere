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
 * Build a web URL pointing at an AT Protocol appview.
 *
 * Returns an UNESCAPED URL. Callers MUST escape at the point of use
 * (\esc_url() for HTML output, \esc_url_raw() for storage/redirects), as
 * late as possible and in the right context.
 *
 * @param string $path    Path after the host, with no leading slash, e.g.
 *                        'profile/<did>/post/<rkey>' or 'hashtag/<tag>'.
 *                        Callers are responsible for encoding path segments.
 * @param array  $context Optional parts the caller has on hand. Recognised
 *                        keys: 'type' (profile|post|mention|hashtag), 'did',
 *                        'handle', 'rkey', 'tag'.
 * @return string Unescaped URL, e.g. 'https://bsky.app/profile/<did>'.
 */
function appview_url( string $path, array $context = array() ): string {
	/**
	 * Filters the base of AT Protocol appview web links.
	 *
	 * The base is everything before the path: scheme, host, and an optional
	 * path prefix. The returned value may be a bare host ('deer.social'), a
	 * host with a path prefix ('something.social/atblue'), and may include a
	 * scheme and/or trailing slash — it is normalized before use, so appviews
	 * hosted on a subdomain or a subpath work cleanly. Defaults to 'bsky.app',
	 * the Bluesky appview. To rewrite the path itself (e.g. a custom route),
	 * use the {@see 'atmosphere_appview_url'} filter instead.
	 *
	 * @since unreleased
	 *
	 * @param string $host    Default appview host ('bsky.app').
	 * @param string $path    Path being built, e.g. 'profile/<did>'.
	 * @param array  $context Available parts: type, did, handle, rkey, tag.
	 */
	$base = \apply_filters( 'atmosphere_appview_host', 'bsky.app', $path, $context );

	$url = appview_base_url( $base ) . '/' . \ltrim( $path, '/' );

	/**
	 * Filters the fully assembled AT Protocol appview web URL.
	 *
	 * Use this to rewrite the entire URL — including the route, e.g.
	 * '/account/<did>' instead of '/profile/<did>', or a custom hashtag
	 * route — by rebuilding it from the parts in $context. Return a complete
	 * URL; it is escaped by the caller, not here.
	 *
	 * @since unreleased
	 *
	 * @param string $url     Assembled URL, e.g. 'https://bsky.app/profile/<did>'.
	 * @param string $path    Path that was appended, e.g. 'profile/<did>'.
	 * @param array  $context Available parts: type, did, handle, rkey, tag.
	 */
	return \apply_filters( 'atmosphere_appview_url', $url, $path, $context );
}

/**
 * Normalize a filtered appview base into a clean 'scheme://host[:port][/prefix]'.
 *
 * Accepts a bare host, a host with a path prefix, with or without a scheme or
 * trailing slash, and rebuilds it without empty or doubled segments. The host is
 * lower-cased and the scheme is clamped to http/https. An empty value falls back
 * to 'https://bsky.app' silently; a non-empty value that yields no host falls
 * back too, but flags `_doing_it_wrong` since the callback returned something
 * unusable.
 *
 * These URLs are display links — rendered for users to click, or stored as
 * comment author/source links — and are always escaped at the call site. They
 * are never fetched server-side, so there is no SSRF surface, and IP-literal or
 * localhost hosts are intentionally allowed for self-hosted appviews. The value
 * comes from a site-owner filter callback, not from external request input.
 *
 * @param string $base Filtered base value, e.g. 'something.social/atblue/'.
 * @return string Clean base with no trailing slash, e.g. 'https://something.social/atblue'.
 */
function appview_base_url( string $base ): string {
	$base = \trim( $base );

	// An empty value just means "use the default appview" — nothing to flag.
	if ( '' === $base ) {
		return 'https://bsky.app';
	}

	// Ensure a scheme so wp_parse_url splits the host from any path prefix.
	if ( ! \preg_match( '#^[a-z][a-z0-9+.-]*://#i', $base ) ) {
		$base = 'https://' . \ltrim( $base, '/' );
	}

	$parts = \wp_parse_url( $base );
	$host  = \is_array( $parts ) ? \strtolower( $parts['host'] ?? '' ) : '';

	if ( '' === $host ) {
		\_doing_it_wrong(
			__FUNCTION__,
			\esc_html__( 'atmosphere_appview_host must return a host (optionally with a scheme and path prefix); falling back to bsky.app.', 'atmosphere' ),
			'unreleased'
		);
		return 'https://bsky.app';
	}

	$scheme = \strtolower( $parts['scheme'] ?? 'https' );
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		$scheme = 'https';
	}

	$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
	$prefix = \trim( $parts['path'] ?? '', '/' );

	return $scheme . '://' . $host . $port . ( '' !== $prefix ? '/' . $prefix : '' );
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
 * Count the graphemes in a string the way Bluesky's composer does.
 *
 * Bluesky measures its 300-character post cap in graphemes, so a ZWJ
 * family emoji (`👨‍👩‍👧‍👦`, many code points) counts as one. Falls back to
 * `mb_strlen()` (code points) when the `intl` extension is missing or the
 * string is invalid UTF-8 — code points are always >= graphemes, so the
 * fallback stays conservative and never under-counts a real grapheme.
 *
 * @param string $text Text to measure.
 * @return int Grapheme count.
 */
function grapheme_length( string $text ): int {
	if ( \function_exists( 'grapheme_strlen' ) ) {
		$length = \grapheme_strlen( $text );
		if ( \is_int( $length ) ) {
			return $length;
		}
	}

	return \mb_strlen( $text );
}

/**
 * Truncate text to a grapheme limit, breaking at word boundaries.
 *
 * Counts graphemes (not code points), so the cut matches Bluesky's own
 * 300-character measurement — a multi-code-point cluster like a ZWJ emoji
 * costs one against the limit and is never split into mojibake. Falls back
 * to code-point counting when `intl` is unavailable or the string is
 * invalid UTF-8.
 *
 * @param string $text   Text to truncate.
 * @param int    $limit  Maximum length in graphemes.
 * @param string $marker Ellipsis marker.
 * @return string
 */
function truncate_text( string $text, int $limit = 300, string $marker = '...' ): string {
	if ( $limit <= 0 ) {
		return '';
	}

	/*
	 * No room for the marker (e.g. a 1-grapheme budget with a "..." marker):
	 * hard-clamp to the limit without one. Skipping this guard would leave
	 * the cut length below negative, and `grapheme_substr()` / `mb_substr()`
	 * read a negative length as "drop the last N" — returning almost the
	 * whole string and overshooting the limit.
	 */
	if ( $limit <= grapheme_length( $marker ) ) {
		return truncate_graphemes( $text, $limit );
	}

	if ( \function_exists( 'grapheme_strlen' ) ) {
		$length = \grapheme_strlen( $text );

		if ( \is_int( $length ) ) {
			if ( $length <= $limit ) {
				return $text;
			}

			$cut = \grapheme_substr( $text, 0, $limit - grapheme_length( $marker ) );

			if ( \is_string( $cut ) ) {
				$last_word = \grapheme_strrpos( $cut, ' ' );

				if ( false !== $last_word && $last_word > $limit * 0.8 ) {
					$clipped = \grapheme_substr( $cut, 0, $last_word );
					if ( \is_string( $clipped ) ) {
						$cut = $clipped;
					}
				}

				return $cut . $marker;
			}
		}
	}

	// Fallback: code-point counting (intl missing or invalid UTF-8).
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
 * Whether the ActivityPub plugin is active.
 *
 * Bluesky reactions are stored as the same comment types the ActivityPub
 * plugin uses, so features that would duplicate its behavior — the reactions
 * block, and hiding reactions from the post's comment list/count — defer to
 * it when it is present.
 *
 * @return bool
 */
function is_activitypub_active(): bool {
	return \defined( 'ACTIVITYPUB_PLUGIN_VERSION' );
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
		/*
		 * `wp_unschedule_hook()`, not `wp_clear_scheduled_hook()`: the
		 * latter only removes events whose args match the given array
		 * (default: empty), so an argless call would leave every queued
		 * per-post/per-comment event (`[ $post_id ]`) in place — exactly
		 * the events that must not fire against a different connection.
		 */
		\wp_unschedule_hook( $hook );
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

	// The revoke event always carries args (the encrypted token payload),
	// so it likewise needs the args-agnostic unschedule.
	\wp_unschedule_hook( 'atmosphere_revoke_refresh_token' );
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
		&& is_supported_post_type( $post->post_type )
		&& is_sharing_enabled( $post );
}

/**
 * Whether per-post sharing to Bluesky is enabled.
 *
 * Sharing is opt-out: it defaults to on, and an author can switch a single
 * post off from the block-editor panel, which stores the
 * `atmosphere_disabled` post meta. Because this folds into
 * {@see is_post_publishable()}, switching a post off after it was shared
 * routes it through the same cleanup path as making it private — the
 * remote records are removed.
 *
 * @param \WP_Post $post Post object.
 * @return bool
 */
function is_sharing_enabled( \WP_Post $post ): bool {
	return '1' !== (string) \get_post_meta( $post->ID, ATMOSPHERE_META_DISABLED, true );
}

/**
 * Write a debug message to the PHP error log, gated behind WP_DEBUG.
 *
 * `error_log()` honours the server's `log_errors` / `error_log` directives
 * independently of `WP_DEBUG`, so unconditional calls land in production logs
 * on any site that has PHP error logging enabled but has not opted into
 * debugging. Routing every plugin log line through this helper keeps that
 * noise out of production unless debugging is intentionally turned on.
 *
 * Centralising the call also means the `[atmosphere]` prefix and the
 * newline stripping (PDS-supplied error strings can carry attacker-controlled
 * CRLF / fake prefixes that would otherwise forge log lines) live in one
 * place rather than being repeated at every call site.
 *
 * @since 1.2.0
 *
 * @param string $message Message to log, without the `[atmosphere]` prefix.
 * @return void
 */
function debug_log( string $message ): void {
	/**
	 * Filters whether an ATmosphere debug message is written to the error log.
	 *
	 * Defaults to the `WP_DEBUG` state. Return true to surface ATmosphere log
	 * lines independently of `WP_DEBUG` (useful for operators who want the
	 * genuine anomaly breadcrumbs — failed cron PDS writes, thread-rollback
	 * orphans — without enabling debugging site-wide), or false to silence
	 * them entirely.
	 *
	 * @since 1.2.0
	 *
	 * @param bool   $enabled Whether to write the message. Defaults to `WP_DEBUG`.
	 * @param string $message The message about to be logged (without the `[atmosphere]` prefix).
	 */
	$enabled = (bool) \apply_filters(
		'atmosphere_debug_log',
		\defined( 'WP_DEBUG' ) && \WP_DEBUG,
		$message
	);

	if ( ! $enabled ) {
		return;
	}

	// Collapse CRLF so a single logged message can't forge extra log lines.
	$message = \str_replace( array( "\r", "\n" ), ' ', $message );

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	\error_log( '[atmosphere] ' . $message );
}

/**
 * Whether an HTTP status code is in the Success (2xx) class.
 *
 * "Success" is the IANA registry name for the 2xx range
 * ({@link https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml}).
 * AT Protocol OAuth and PDS requests disable redirects, so any non-2xx
 * status (including a 3xx the server would have redirected) is treated
 * as a failure. Centralizes that check for the OAuth and API callers.
 *
 * @since unreleased
 *
 * @param mixed $status HTTP status code (int, or '' when the request failed).
 * @return bool True for 200-299, false otherwise.
 */
function is_success_status( $status ): bool {
	$status = (int) $status;

	return $status >= 200 && $status < 300;
}
