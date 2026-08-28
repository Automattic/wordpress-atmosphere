<?php
/**
 * Helper functions for ATmosphere.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Client;

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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
 * The handle typeahead endpoint queried as the user types a handle (an
 * `app.bsky.actor.searchActorsTypeahead` XRPC endpoint).
 *
 * Used by both the Settings → ATmosphere connect field and the Settings →
 * Connectors card. Defaults to Bluesky's official unauthenticated public
 * appview (`public.api.bsky.app`), which is CORS-enabled so the browser can
 * call it directly. Centralized and filterable the same way {@see appview_url()}
 * centralizes the appview host: a site can point it elsewhere — e.g. a
 * network-wide index such as `typeahead.waow.tech` — or return an empty string
 * to disable typeahead entirely and fall back to manual handle entry.
 *
 * @since 2.1.0
 *
 * @return string The typeahead XRPC endpoint, or '' to disable typeahead.
 */
function handle_typeahead_url(): string {
	/**
	 * Filters the handle typeahead endpoint used across the plugin's admin UI.
	 *
	 * @since 2.1.0
	 *
	 * @param string $url Default typeahead XRPC endpoint. Return '' to disable
	 *                    typeahead and require manual handle entry.
	 */
	$url = (string) \apply_filters(
		'atmosphere_handle_typeahead_url',
		'https://public.api.bsky.app/xrpc/app.bsky.actor.searchActorsTypeahead'
	);

	return \esc_url_raw( $url );
}

/**
 * Normalise a submitted AT Protocol handle for resolution.
 *
 * Sanitises the raw value and strips a leading `@`: Bluesky surfaces handles as
 * `@alice.bsky.social`, so people naturally type the `@`, but the resolver
 * expects a bare DNS-style identifier and rejects the prefixed form. Shared by
 * both connect entry points — the settings-page sanitize callback
 * ({@see Sanitize::handle()}) and the Connectors REST route
 * ({@see \Atmosphere\Rest\Admin\Connection_Controller::authorize()}) — so the two
 * flows normalise identically by construction.
 *
 * @param mixed $value Raw submitted handle.
 * @return string Sanitised, bare handle.
 */
function normalize_handle( $value ): string {
	return \ltrim( \sanitize_text_field( (string) $value ), '@' );
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
 * Whether local WordPress comments may be published to Bluesky as replies.
 *
 * Unsaved installs default to enabled. The stored per-site preference is
 * resolved first; the `atmosphere_should_publish_comments` filter then
 * has the final say, so host plugins can override the effective behavior
 * without touching the saved option.
 *
 * @since 2.1.0
 *
 * @return bool
 */
function is_comment_publishing_enabled(): bool {
	$enabled = feature_option_enabled( 'atmosphere_publish_comments' );

	/**
	 * Filters whether local WordPress comments may be published to Bluesky as replies.
	 *
	 * Runs last, so it has the final say over the stored setting and
	 * {@see is_connection_only_mode()} — a host plugin can force outgoing
	 * writes off (or back on) regardless of the saved preference. The override
	 * is on effective behavior, not the option, so the stored preference
	 * survives untouched.
	 *
	 * @since 2.1.0
	 *
	 * @param bool $enabled Whether comment publishing is enabled.
	 */
	return (bool) \apply_filters( 'atmosphere_should_publish_comments', $enabled );
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
 * The OAuth scopes the server granted, when known.
 *
 * Null means the connection predates scope storage and has not been
 * refreshed since, or the server did not say. Callers must treat null as
 * "no information", not as "nothing granted".
 *
 * @since unreleased
 *
 * @return string[]|null Granted scope tokens, or null when unknown.
 */
function connection_scopes(): ?array {
	$scope = (string) ( get_connection()['scope'] ?? '' );

	if ( '' === \trim( $scope ) ) {
		return null;
	}

	return \array_values( \array_filter( \explode( ' ', $scope ) ) );
}

/**
 * Whether reply restrictions need a reconnect before they can be written.
 *
 * True only when the site is connected, the granted scope is known, and
 * the threadgate scope is not in it. A connection whose scope is unknown
 * is allowed through: the write is attempted, and a rejection surfaces
 * through the normal publish error. Hiding a working feature on every
 * pre-existing install would be worse than the occasional failed write.
 *
 * @since unreleased
 *
 * @return bool
 */
function threadgate_needs_reconnect(): bool {
	if ( ! is_connected() ) {
		return false;
	}

	$scopes = connection_scopes();

	return null !== $scopes && ! \in_array( Client::THREADGATE_SCOPE, $scopes, true );
}

/**
 * Whether the operator explicitly disconnected the site.
 *
 * The explicit-disconnect marker only counts when the connection row is
 * genuinely empty. `Client::disconnect()` deletes `atmosphere_connection`
 * before any other admin request can land, so a missing connection
 * alongside the marker is a true operator-initiated disconnect. After a
 * refresh failure, the connection row stays put (with `needs_reauth`
 * set) — if a stale marker from an earlier disconnect survived (e.g. a
 * `delete_option` silently failed at a cache layer), the connection's
 * presence outs the marker as stale and callers should fall through to
 * their failure copy, which is the accurate framing.
 *
 * @since 2.1.0
 *
 * @return bool
 */
function is_operator_disconnected(): bool {
	return (bool) \get_option( Client::DISCONNECTED_OPTION, false ) && empty( get_connection() );
}

/**
 * Why the connection was flagged for reauth.
 *
 * Canonical values are the `Client::REAUTH_REASON_*` constants:
 * `key_changed` (encryption key material changed under the stored
 * tokens) and `decrypt_failed` (tokens unreadable with an unchanged
 * key). An empty string means no specific cause was recorded — legacy
 * rows and plain session expiry.
 *
 * @since 2.1.0
 *
 * @return string
 */
function get_reauth_reason(): string {
	return (string) ( get_connection()['reauth_reason'] ?? '' );
}

/**
 * Lead sentence explaining why the connection needs a reconnect.
 *
 * Single source for the cause copy so every surface that reads the
 * `reauth_reason` marker — the admin reconnect notice and the Site
 * Health test — explains the same failure with the same words. Each
 * caller appends its own consequence/action tail; copy edits and
 * translations happen once, here.
 *
 * @since 2.1.0
 *
 * @return string Translated, unescaped sentence.
 */
function reauth_reason_lead(): string {
	switch ( get_reauth_reason() ) {
		case Client::REAUTH_REASON_KEY_CHANGED:
			return \__( 'Your site’s security keys have changed — this can happen after a migration, or when a security plugin rotates them on a schedule — so ATmosphere can no longer read its saved Bluesky login.', 'atmosphere' );
		case Client::REAUTH_REASON_DECRYPT_FAILED:
			return \__( 'ATmosphere can no longer read its saved Bluesky login.', 'atmosphere' );
		default:
			return \__( 'Your Bluesky session has expired.', 'atmosphere' );
	}
}

/**
 * URL of the ATmosphere settings page.
 *
 * Single source for the settings-page location so reconnect prompts and
 * editor surfaces don't each hardcode the page slug.
 *
 * @since 2.1.0
 *
 * @return string Unescaped admin URL; escape at the call site.
 */
function settings_url(): string {
	return \admin_url( 'options-general.php?page=atmosphere' );
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
		'atmosphere_backfill_replies',
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
 * Whether the plugin is running purely as a connection layer for another plugin.
 *
 * A host plugin that embeds ATmosphere only to reuse its AT Protocol
 * connection — driving everything through the Settings → Connectors card and
 * its own UI — can return true from the `atmosphere_connection_only_mode`
 * filter. In that mode ATmosphere stops acting on its own: automatic
 * cross-posting ({@see is_auto_publish_enabled()}), reaction import
 * ({@see is_reaction_sync_enabled()}), reply import
 * ({@see is_reply_sync_enabled()}), and publishing local comments as Bluesky
 * replies ({@see is_comment_publishing_enabled()}) are all off, and the
 * plugin's own Settings → ATmosphere screen is hidden
 * ({@see \Atmosphere\WP_Admin\Admin::is_settings_page_visible()}).
 *
 * This is a hard override of the *effective* behaviour, not merely a change of
 * default: it forces those features off regardless of the stored per-site
 * option, so the outcome does not depend on whether the site previously saved a
 * value. Each behavioural lane keeps its own dedicated filter, evaluated last, so
 * a host that wants to re-enable one (say, keep cross-posting while suppressing
 * reactions) still can. Settings-page visibility, by contrast, follows
 * connection-only mode directly, with no separate override.
 *
 * @since 2.1.0
 *
 * @return bool True when ATmosphere is embedded purely as a connection layer.
 */
function is_connection_only_mode(): bool {
	/**
	 * Filters whether ATmosphere runs purely as a connection layer.
	 *
	 * Return true when another plugin embeds ATmosphere solely to reuse its
	 * AT Protocol connection. Automatic cross-posting, reaction import,
	 * reply import, and comment publishing then default off, and Settings →
	 * ATmosphere is hidden. The per-feature filters
	 * ({@see 'atmosphere_should_auto_publish'},
	 * {@see 'atmosphere_should_sync_reactions'},
	 * {@see 'atmosphere_should_sync_replies'}, and
	 * {@see 'atmosphere_should_publish_comments'}) are evaluated afterwards and
	 * have the final say, so individual behavioural lanes can still be re-enabled.
	 *
	 * @since 2.1.0
	 *
	 * @param bool $connection_only Whether ATmosphere is embedded as a connection layer only. Default false.
	 */
	return (bool) \apply_filters( 'atmosphere_connection_only_mode', false );
}

/**
 * Resolve a per-feature opt-out flag through the two layers every behavioural
 * lane shares before its own filter: the stored option (default on), then a
 * hard off in {@see is_connection_only_mode()}.
 *
 * Each lane still applies its own literal `atmosphere_should_*` filter to the
 * result at its call site — kept there so the hook stays greppable and
 * documented — so this centralises only the shared option-read + connection-only
 * override, preventing the four copies from drifting apart.
 *
 * @param string $option Option name storing the opt-out preference.
 * @return bool Effective state before the per-lane filter runs.
 */
function feature_option_enabled( string $option ): bool {
	$enabled = '1' === (string) \get_option( $option, '1' );

	return is_connection_only_mode() ? false : $enabled;
}

/**
 * Whether posts are automatically cross-posted to Bluesky on publish.
 *
 * Resolves the effective auto-publish state from three layers, in order: the
 * stored `atmosphere_auto_publish` option (opt-out — on unless the user turned
 * it off), forced off in {@see is_connection_only_mode()}, and finally the
 * `atmosphere_should_auto_publish` filter, which has the last word so a host in
 * connection-only mode can re-enable cross-posting on its own terms.
 *
 * @since 2.1.0
 *
 * @return bool
 */
function is_auto_publish_enabled(): bool {
	$enabled = feature_option_enabled( 'atmosphere_auto_publish' );

	/**
	 * Filters whether posts are automatically cross-posted to Bluesky on publish.
	 *
	 * Evaluated after the stored option and {@see is_connection_only_mode()},
	 * so it is the final authority: a host that keeps ATmosphere in
	 * connection-only mode but still wants automatic cross-posting can return
	 * true here.
	 *
	 * @since 2.1.0
	 *
	 * @param bool $enabled Whether auto-publish is effectively enabled.
	 */
	return (bool) \apply_filters( 'atmosphere_should_auto_publish', $enabled );
}

/**
 * Whether Bluesky likes and reposts are imported as comments.
 *
 * Same three-layer resolution as {@see is_auto_publish_enabled()}: the stored
 * `atmosphere_sync_reactions` option, forced off in
 * {@see is_connection_only_mode()}, then the `atmosphere_should_sync_reactions`
 * filter as the final say.
 *
 * @since 2.1.0
 *
 * @return bool
 */
function is_reaction_sync_enabled(): bool {
	$enabled = feature_option_enabled( 'atmosphere_sync_reactions' );

	/**
	 * Filters whether Bluesky likes and reposts are imported as comments.
	 *
	 * Evaluated after the stored option and {@see is_connection_only_mode()},
	 * so it has the final say over the effective reaction-import state.
	 *
	 * @since 2.1.0
	 *
	 * @param bool $enabled Whether reaction import is effectively enabled.
	 */
	return (bool) \apply_filters( 'atmosphere_should_sync_reactions', $enabled );
}

/**
 * Whether Bluesky replies are imported as comments.
 *
 * Same three-layer resolution as {@see is_auto_publish_enabled()}: the stored
 * `atmosphere_sync_replies` option, forced off in
 * {@see is_connection_only_mode()}, then the `atmosphere_should_sync_replies`
 * filter as the final say.
 *
 * @since 2.1.0
 *
 * @return bool
 */
function is_reply_sync_enabled(): bool {
	$enabled = feature_option_enabled( 'atmosphere_sync_replies' );

	/**
	 * Filters whether Bluesky replies are imported as comments.
	 *
	 * Evaluated after the stored option and {@see is_connection_only_mode()},
	 * so it has the final say over the effective reply-import state.
	 *
	 * @since 2.1.0
	 *
	 * @param bool $enabled Whether reply import is effectively enabled.
	 */
	return (bool) \apply_filters( 'atmosphere_should_sync_replies', $enabled );
}

/**
 * Whether the `site.standard.publication` record is written/refreshed automatically.
 *
 * Establishing the site's standard.site publication is ATmosphere acting on its
 * own, so a host embedding it purely as a connection layer shouldn't get a
 * public publication record written to the connected repo the moment a user
 * connects. Unlike the other lanes this has no stored user option — it defaults
 * on, is forced off in {@see is_connection_only_mode()}, and a dedicated filter
 * has the final say so a host can opt back in.
 *
 * @since 2.1.0
 *
 * @return bool
 */
function is_publication_sync_enabled(): bool {
	$enabled = ! is_connection_only_mode();

	/**
	 * Filters whether the site.standard.publication record is synced automatically.
	 *
	 * Runs after {@see is_connection_only_mode()}, so it has the final say: a host
	 * running ATmosphere as a connection layer can re-enable publication upkeep.
	 *
	 * @since 2.1.0
	 *
	 * @param bool $enabled Whether publication sync is effectively enabled.
	 */
	return (bool) \apply_filters( 'atmosphere_should_sync_publication', $enabled );
}

/**
 * Whether a companion Bluesky feed post is published alongside the document.
 *
 * When this returns false, ATmosphere publishes only the
 * `site.standard.document` record for a post — no `app.bsky.feed.post`
 * companion — across backfill, auto-publish, and edit-updates. Use it to run a
 * site as a standard.site publication that never cross-posts to Bluesky.
 *
 * Forward-only: it governs new writes and does not remove Bluesky posts
 * published before it was enabled.
 *
 * Unlike the other lane helpers (see {@see is_publication_sync_enabled()}), this
 * one has no connection-only pass. Connection-only mode only forces *automatic*
 * publishing off ({@see is_auto_publish_enabled()}); manual paths such as the
 * Backfill CLI still call {@see \Atmosphere\Publisher} directly, so this helper
 * can — and should — still run in that mode. That is deliberate: it shapes
 * *what* a publish writes, not *whether* the site publishes, so a host embedded
 * as a connection layer that runs a manual backfill can still choose
 * document-only output. Forcing it off in connection-only mode would take that
 * choice away. It is therefore a pure filter, not the
 * "option → force off in connection-only → filter last" contract.
 *
 * The post is passed to the filter so a callback can answer per post.
 *
 * @since unreleased
 *
 * @param \WP_Post $post The post being published.
 * @return bool True when the Bluesky companion post should be published. Default true.
 */
function is_bluesky_post_enabled( \WP_Post $post ): bool {
	/**
	 * Filters whether a companion Bluesky feed post is published alongside the
	 * site.standard.document record.
	 *
	 * Return false to publish documents only. Forward-only: it does not remove
	 * Bluesky posts published before it was enabled.
	 *
	 * @since unreleased
	 *
	 * @param bool     $enabled Whether to publish the Bluesky companion post. Default true.
	 * @param \WP_Post $post    The post being published.
	 */
	return (bool) \apply_filters( 'atmosphere_should_publish_bluesky_post', true, $post );
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
 * @since 2.0.0
 *
 * @param mixed $status HTTP status code (int, or '' when the request failed).
 * @return bool True for 200-299, false otherwise.
 */
function is_success_status( $status ): bool {
	$status = (int) $status;

	return $status >= 200 && $status < 300;
}
