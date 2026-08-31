<?php
/**
 * Tests for ATmosphere helper functions.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests;

use function Atmosphere\parse_at_uri;
use function Atmosphere\post_web_url;
use function Atmosphere\build_at_uri;
use function Atmosphere\appview_url;
use function Atmosphere\get_identity;
use function Atmosphere\set_identity;
use function Atmosphere\sanitize_text;
use function Atmosphere\truncate_text;
use function Atmosphere\truncate_graphemes;
use function Atmosphere\grapheme_length;
use function Atmosphere\to_iso8601;
use function Atmosphere\is_post_publishable;
use function Atmosphere\is_sharing_enabled;
use function Atmosphere\is_connection_only_mode;
use function Atmosphere\is_auto_publish_enabled;
use function Atmosphere\is_reaction_sync_enabled;
use function Atmosphere\is_reply_sync_enabled;
use function Atmosphere\get_connection;
use function Atmosphere\debug_log;
use function Atmosphere\is_comment_publishing_enabled;
use function Atmosphere\is_bluesky_post_enabled;

/**
 * Function tests.
 */
class Test_Functions extends \WP_UnitTestCase {

	/**
	 * Test parsing a valid AT-URI.
	 */
	public function test_parse_at_uri_valid() {
		$result = parse_at_uri( 'at://did:plc:abc123/app.bsky.feed.post/3k2la7b2zoq2s' );

		$this->assertIsArray( $result );
		$this->assertSame( 'did:plc:abc123', $result['did'] );
		$this->assertSame( 'app.bsky.feed.post', $result['collection'] );
		$this->assertSame( '3k2la7b2zoq2s', $result['rkey'] );
	}

	/**
	 * A well-formed post URI becomes a profile/post link.
	 */
	public function test_post_web_url_builds_the_appview_link() {
		$this->assertSame(
			'https://bsky.app/profile/did:plc:abc123/post/3k2la7',
			post_web_url( 'at://did:plc:abc123/app.bsky.feed.post/3k2la7' )
		);
	}

	/**
	 * Anything that is not exactly one of our post URIs yields no link at
	 * all, never a half-built one. `parse_at_uri()` alone would accept an
	 * empty rkey and ignore a trailing segment.
	 *
	 * @dataProvider malformed_post_uris
	 *
	 * @param string $uri The URI to reject.
	 */
	public function test_post_web_url_rejects_malformed_uris( string $uri ) {
		$this->assertSame( '', post_web_url( $uri ) );
	}

	/**
	 * URIs that must not produce a link.
	 *
	 * @return array<string, array{string}>
	 */
	public function malformed_post_uris(): array {
		return array(
			'empty rkey (trailing slash)' => array( 'at://did:plc:abc123/app.bsky.feed.post/' ),
			'empty did'                   => array( 'at:///app.bsky.feed.post/3k2la7' ),
			'extra trailing segment'      => array( 'at://did:plc:abc123/app.bsky.feed.post/3k2la7/extra' ),
			'document collection'         => array( 'at://did:plc:abc123/site.standard.document/3k2la7' ),
			'not an at uri'               => array( 'https://bsky.app/profile/did:plc:abc123/post/3k2la7' ),
			'empty string'                => array( '' ),
		);
	}

	/**
	 * Test parsing an invalid AT-URI returns false.
	 */
	public function test_parse_at_uri_invalid() {
		$this->assertFalse( parse_at_uri( 'https://example.com' ) );
		$this->assertFalse( parse_at_uri( 'at://did:plc:abc123' ) );
		$this->assertFalse( parse_at_uri( '' ) );
	}

	/**
	 * Test building an AT-URI.
	 */
	public function test_build_at_uri() {
		$uri = build_at_uri( 'did:plc:abc123', 'app.bsky.feed.post', 'rkey123' );

		$this->assertSame( 'at://did:plc:abc123/app.bsky.feed.post/rkey123', $uri );
	}

	/**
	 * Test sanitize_text strips HTML and normalises whitespace.
	 */
	public function test_sanitize_text() {
		$this->assertSame( 'Hello World', sanitize_text( '<p>Hello   World</p>' ) );
		$this->assertSame( 'a & b', sanitize_text( 'a &amp; b' ) );
	}

	/**
	 * Entity-encoded markup must not survive as live tags. WordPress stores
	 * values like the site title HTML-entity encoded, so `<b>` arrives as
	 * `&lt;b&gt;`. sanitize_text() decodes before stripping, so the decoded
	 * tag is removed rather than re-materialising as live markup in the
	 * record.
	 */
	public function test_sanitize_text_removes_entity_encoded_markup() {
		$this->assertSame( 'Bad', sanitize_text( '&lt;b&gt;Bad&lt;/b&gt;' ) );
		$this->assertSame( '', sanitize_text( '&lt;script&gt;alert(1)&lt;/script&gt;' ) );
		$this->assertStringNotContainsString( '<', sanitize_text( '&lt;img src=x onerror=alert(1)&gt;' ) );
	}

	/**
	 * Unicode whitespace (NBSP, ideographic space) collapses and trims
	 * just like ASCII whitespace. Without the `/u` regex flag a NBSP-only
	 * string would survive both the collapse and the trim and leak
	 * downstream as fake "prose."
	 */
	public function test_sanitize_text_normalises_unicode_whitespace() {
		$this->assertSame( 'A B', sanitize_text( "A\xC2\xA0\xC2\xA0B" ) );
		$this->assertSame( 'A B', sanitize_text( "A\xE3\x80\x80B" ) );
		$this->assertSame( '', sanitize_text( "\xC2\xA0\xC2\xA0" ) );
		$this->assertSame( '', sanitize_text( "\xE3\x80\x80\xE3\x80\x80" ) );
	}

	/**
	 * `/u`-mode preg_replace returns null on malformed UTF-8; the
	 * function must not TypeError when that happens. Locks in the
	 * defensive `is_string` fallback.
	 */
	public function test_sanitize_text_handles_invalid_utf8_without_fataling() {
		// 0xC3 0x28 is a malformed UTF-8 sequence (continuation byte missing).
		$result = sanitize_text( "ok \xC3\x28 still here" );
		$this->assertIsString( $result );
		$this->assertNotSame( '', $result );
	}

	/**
	 * Test truncate_text respects limit.
	 */
	public function test_truncate_text_short() {
		$this->assertSame( 'Hello', truncate_text( 'Hello', 300 ) );
	}

	/**
	 * Returns text unchanged when it already fits the grapheme budget.
	 */
	public function test_truncate_graphemes_returns_short_text_unchanged() {
		$this->assertSame( 'Hello', truncate_graphemes( 'Hello', 500 ) );
	}

	/**
	 * Hard-clamps plain ASCII text at the grapheme limit and does NOT
	 * append an ellipsis — canonical fields like publication `name`
	 * must not have their grapheme budget burned by a marker.
	 */
	public function test_truncate_graphemes_hard_clamps_without_marker() {
		$text   = \str_repeat( 'a', 600 );
		$result = truncate_graphemes( $text, 500 );

		$this->assertSame( 500, \mb_strlen( $result ) );
		$this->assertStringEndsNotWith( '…', $result );
		$this->assertStringEndsNotWith( '...', $result );
	}

	/**
	 * Empty input is returned unchanged regardless of the limit.
	 */
	public function test_truncate_graphemes_returns_empty_string_unchanged() {
		$this->assertSame( '', truncate_graphemes( '', 500 ) );
		$this->assertSame( '', truncate_graphemes( '', 0 ) );
	}

	/**
	 * Text exactly at the limit (`length === max_graphemes`) is returned
	 * unchanged — the comparison is inclusive.
	 */
	public function test_truncate_graphemes_returns_text_at_exact_limit_unchanged() {
		$text = \str_repeat( 'x', 500 );
		$this->assertSame( $text, truncate_graphemes( $text, 500 ) );
	}

	/**
	 * A negative limit clamps to an empty string. Without the guard,
	 * `grapheme_substr( 'hello', 0, -1 )` returns `'hell'` — the
	 * substring API interprets negative length as "drop N from the
	 * end", which is the opposite of a clamp.
	 */
	public function test_truncate_graphemes_returns_empty_for_negative_limit() {
		$this->assertSame( '', truncate_graphemes( 'hello', -1 ) );
		$this->assertSame( '', truncate_graphemes( 'hello', -500 ) );
	}

	/**
	 * Multi-codepoint grapheme clusters (a ZWJ emoji family) count as
	 * one grapheme each under `intl`. A string of 5 family emoji
	 * survives a 500-grapheme clamp intact even though it's many
	 * code points.
	 *
	 * Requires the `intl` extension.
	 */
	public function test_truncate_graphemes_counts_zwj_emoji_as_single_graphemes() {
		if ( ! \function_exists( 'grapheme_strlen' ) ) {
			$this->markTestSkipped( 'intl extension required for grapheme counting.' );
		}

		// "Family: man, woman, girl" — one grapheme, five code points.
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
		$text   = \str_repeat( $family, 5 );

		$result = truncate_graphemes( $text, 500 );

		$this->assertSame( $text, $result );
		$this->assertSame( 5, \grapheme_strlen( $result ) );
	}

	/**
	 * Clamping in the middle of a grapheme cluster must keep the
	 * cluster intact — never produce a broken final emoji. The clamp
	 * lands at the boundary, dropping the partial cluster entirely.
	 */
	public function test_truncate_graphemes_preserves_cluster_boundaries() {
		if ( ! \function_exists( 'grapheme_strlen' ) ) {
			$this->markTestSkipped( 'intl extension required for grapheme counting.' );
		}

		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
		// 10 families, ~50 code points, but exactly 10 graphemes.
		$text = \str_repeat( $family, 10 );

		// Clamp to 3 graphemes: result must be exactly 3 family glyphs.
		$result = truncate_graphemes( $text, 3 );

		$this->assertSame( 3, \grapheme_strlen( $result ) );
		$this->assertSame( \str_repeat( $family, 3 ), $result );
	}

	/**
	 * Test truncate_text truncates long text.
	 */
	public function test_truncate_text_long() {
		$text   = \str_repeat( 'word ', 100 );
		$result = truncate_text( $text, 50 );

		$this->assertLessThanOrEqual( 50, \mb_strlen( $result ) );
		$this->assertStringEndsWith( '...', $result );
	}

	/**
	 * Emoji-heavy text within the grapheme budget is returned untouched,
	 * even when its code-point count exceeds the limit. Bluesky measures
	 * the 300-cap in graphemes, so a family emoji (five code points) must
	 * count as one against the limit — not five.
	 */
	public function test_truncate_text_counts_emoji_as_graphemes() {
		if ( ! \function_exists( 'grapheme_strlen' ) ) {
			$this->markTestSkipped( 'intl extension required for grapheme counting.' );
		}

		// 50 family emoji: 50 graphemes, but 250 code points.
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
		$text   = \str_repeat( $family, 50 );

		$this->assertGreaterThan( 100, \mb_strlen( $text ) );
		$this->assertSame( $text, truncate_text( $text, 100 ) );
	}

	/**
	 * When emoji text does exceed the grapheme budget, the cut lands on a
	 * cluster boundary — never splitting a family emoji into mojibake — and
	 * the result, marker included, stays within the grapheme limit.
	 */
	public function test_truncate_text_truncates_on_grapheme_boundary() {
		if ( ! \function_exists( 'grapheme_strlen' ) ) {
			$this->markTestSkipped( 'intl extension required for grapheme counting.' );
		}

		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
		$text   = \str_repeat( $family, 200 ); // 200 graphemes.

		$result = truncate_text( $text, 100 );

		$this->assertLessThanOrEqual( 100, \grapheme_strlen( $result ) );
		$this->assertStringEndsWith( '...', $result );

		// The emoji body (marker stripped) is whole families, never a
		// half-cluster: stripping '...' leaves a clean run of families.
		$body = \substr( $result, 0, -3 );
		$this->assertSame( \str_repeat( $family, \grapheme_strlen( $body ) ), $body );
	}

	/**
	 * A budget too small to hold the marker hard-clamps to the limit
	 * without one, rather than letting a negative cut length return nearly
	 * the whole string and overshoot the limit.
	 */
	public function test_truncate_text_budget_smaller_than_marker() {
		$this->assertSame( '', truncate_text( 'Hello world', 0 ) );
		$this->assertSame( 'H', truncate_text( 'Hello world', 1 ) );
		$this->assertSame( 'He', truncate_text( 'Hello world', 2 ) );
	}

	/**
	 * The grapheme_length() helper counts a ZWJ family emoji as one, where
	 * mb_strlen would report its five code points.
	 */
	public function test_grapheme_length_counts_emoji_as_one() {
		if ( ! \function_exists( 'grapheme_strlen' ) ) {
			$this->markTestSkipped( 'intl extension required for grapheme counting.' );
		}

		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

		$this->assertSame( 1, grapheme_length( $family ) );
		$this->assertSame( 3, grapheme_length( 'abc' ) );

		// Other multi-code-point clusters Bluesky also counts as one.
		$this->assertSame( 1, grapheme_length( "\u{1F44D}\u{1F3FB}" ) ); // Thumbs-up + skin tone.
		$this->assertSame( 1, grapheme_length( "\u{1F1FA}\u{1F1F8}" ) ); // Flag: US.
	}

	/**
	 * Test ISO 8601 conversion.
	 */
	public function test_to_iso8601() {
		$result = to_iso8601( '2024-01-15 12:30:00' );

		$this->assertSame( '2024-01-15T12:30:00.000Z', $result );
	}

	/**
	 * Publishable posts must be public, supported, and not password-protected.
	 */
	public function test_is_post_publishable_requires_public_supported_unprotected_post() {
		$public = self::factory()->post->create_and_get(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$draft = self::factory()->post->create_and_get(
			array(
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);

		$protected = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_password' => 'secret',
			)
		);

		$zero_string_password = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_password' => '0',
			)
		);

		$page = self::factory()->post->create_and_get(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);

		$this->assertTrue( is_post_publishable( $public ) );
		$this->assertFalse( is_post_publishable( $draft ) );
		$this->assertFalse( is_post_publishable( $protected ) );
		$this->assertFalse( is_post_publishable( $zero_string_password ) );
		$this->assertFalse( is_post_publishable( $page ) );
	}

	/**
	 * Sharing is opt-out: enabled by default, and switching it off makes
	 * the post non-publishable so it is not shared (and an already-shared
	 * post routes through the cleanup path).
	 */
	public function test_per_post_disable_gates_publishing() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// Default: sharing on, post publishable.
		$this->assertTrue( is_sharing_enabled( $post ) );
		$this->assertTrue( is_post_publishable( $post ) );

		// Author switches sharing off for this post.
		\update_post_meta( $post->ID, ATMOSPHERE_META_DISABLED, '1' );

		$this->assertFalse( is_sharing_enabled( $post ) );
		$this->assertFalse( is_post_publishable( $post ) );
	}

	/**
	 * `get_connection()` returns the option array on a healthy install.
	 */
	public function test_get_connection_returns_array_for_healthy_option() {
		\update_option(
			'atmosphere_connection',
			array(
				'did'    => 'did:plc:test',
				'handle' => 'example.com',
			),
			false
		);

		$conn = get_connection();

		$this->assertIsArray( $conn );
		$this->assertSame( 'did:plc:test', $conn['did'] );

		\delete_option( 'atmosphere_connection' );
	}

	/**
	 * `get_connection()` normalises a corrupted non-array option value
	 * to an empty array. Without the coercion, the `: array` return-type
	 * declaration would raise a TypeError mid-render of `admin_notices`
	 * (Admin::maybe_render_reauth_notice composes get_connection() with
	 * the disconnect-marker gate), whitescreening the admin until the
	 * row is repaired. Repair paths (wp-cli, the Disconnect button)
	 * live in that same admin, so a crash here would be self-trapping.
	 */
	public function test_get_connection_normalises_corrupted_non_array_option() {
		\update_option( 'atmosphere_connection', 'corrupted-scalar-string', false );

		$conn = get_connection();

		$this->assertIsArray( $conn );
		$this->assertSame( array(), $conn );

		\delete_option( 'atmosphere_connection' );
	}

	/**
	 * Comment publishing retains the historical enabled default until an
	 * administrator explicitly turns them off.
	 */
	public function test_comment_publishing_option_controls_effective_state() {
		\delete_option( 'atmosphere_publish_comments' );
		$this->assertTrue( is_comment_publishing_enabled() );

		\update_option( 'atmosphere_publish_comments', '' );
		$this->assertFalse( is_comment_publishing_enabled() );

		\update_option( 'atmosphere_publish_comments', '0' );
		$this->assertFalse( is_comment_publishing_enabled() );

		\update_option( 'atmosphere_publish_comments', '1' );
		$this->assertTrue( is_comment_publishing_enabled() );

		\delete_option( 'atmosphere_publish_comments' );
	}

	/**
	 * Capture everything `debug_log()` writes to the PHP error log while
	 * running `$callback`, returning the raw log contents.
	 *
	 * @param callable $callback Code to run with the error log redirected.
	 * @return string Captured error-log contents.
	 */
	private function capture_debug_log( callable $callback ): string {
		$tmp  = \tempnam( \sys_get_temp_dir(), 'atmosphere-log' );
		$orig = \ini_get( 'error_log' );
		// Redirect error_log() to a temp file so the write is observable; restored below.
		\ini_set( 'error_log', $tmp ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		try {
			$callback();
		} finally {
			\ini_set( 'error_log', false === $orig ? '' : $orig ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = (string) \file_get_contents( $tmp );
		\unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		return $contents;
	}

	/**
	 * `debug_log()` writes a prefixed line when logging is enabled.
	 */
	public function test_debug_log_writes_when_enabled() {
		$contents = $this->capture_debug_log(
			function () {
				\add_filter( 'atmosphere_debug_log', '__return_true' );
				debug_log( 'something happened' );
				\remove_filter( 'atmosphere_debug_log', '__return_true' );
			}
		);

		$this->assertStringContainsString( '[atmosphere] something happened', $contents );
	}

	/**
	 * `debug_log()` is a no-op when logging is disabled, regardless of the
	 * server's `log_errors` / `error_log` directives.
	 */
	public function test_debug_log_noop_when_disabled() {
		$contents = $this->capture_debug_log(
			function () {
				\add_filter( 'atmosphere_debug_log', '__return_false' );
				debug_log( 'should not be logged' );
				\remove_filter( 'atmosphere_debug_log', '__return_false' );
			}
		);

		$this->assertStringNotContainsString( 'should not be logged', $contents );
	}

	/**
	 * The `atmosphere_debug_log` filter receives the WP_DEBUG default and the
	 * unprefixed message, and its return value controls whether logging runs.
	 */
	public function test_debug_log_filter_receives_default_and_message() {
		$received = array();

		$capture = function ( $enabled, $message ) use ( &$received ) {
			$received['enabled'] = $enabled;
			$received['message'] = $message;
			return false;
		};

		$this->capture_debug_log(
			function () use ( $capture ) {
				\add_filter( 'atmosphere_debug_log', $capture, 10, 2 );
				debug_log( 'filter payload' );
				\remove_filter( 'atmosphere_debug_log', $capture, 10 );
			}
		);

		$this->assertArrayHasKey( 'enabled', $received );
		$this->assertSame( \defined( 'WP_DEBUG' ) && \WP_DEBUG, $received['enabled'] );
		$this->assertSame( 'filter payload', $received['message'] );
	}

	/**
	 * `debug_log()` collapses CRLF so a single message cannot forge extra
	 * log lines (PDS-supplied error strings can carry attacker-controlled
	 * newlines and fake `[atmosphere]` prefixes).
	 */
	public function test_debug_log_collapses_newlines() {
		$contents = $this->capture_debug_log(
			function () {
				\add_filter( 'atmosphere_debug_log', '__return_true' );
				debug_log( "first line\r\n[atmosphere] forged second line" );
				\remove_filter( 'atmosphere_debug_log', '__return_true' );
			}
		);

		// Exactly one logged line carries the prefix; the forged one is folded in.
		$this->assertSame( 1, \substr_count( $contents, '[atmosphere] first line' ) );
		$this->assertStringNotContainsString( "first line\n", $contents );
		$this->assertStringNotContainsString( "first line\r", $contents );
	}

	/**
	 * Default host yields a bsky.app URL.
	 */
	public function test_appview_url_default_host() {
		$this->assertSame(
			'https://bsky.app/profile/did:plc:abc123',
			appview_url( 'profile/did:plc:abc123' )
		);
	}

	/**
	 * A filter can override the appview host.
	 */
	public function test_appview_url_filter_overrides_host() {
		$callback = static function () {
			return 'deer.social';
		};
		\add_filter( 'atmosphere_appview_host', $callback );

		$this->assertSame(
			'https://deer.social/profile/did:plc:abc123',
			appview_url( 'profile/did:plc:abc123' )
		);

		\remove_filter( 'atmosphere_appview_host', $callback );
	}

	/**
	 * The filter receives the path and context arguments.
	 */
	public function test_appview_url_filter_receives_path_and_context() {
		$seen     = array();
		$callback = static function ( $host, $path, $context ) use ( &$seen ) {
			$seen = array(
				'host'    => $host,
				'path'    => $path,
				'context' => $context,
			);
			return $host;
		};
		\add_filter( 'atmosphere_appview_host', $callback, 10, 3 );

		appview_url(
			'profile/did:plc:abc123/post/3kabc',
			array(
				'type' => 'post',
				'did'  => 'did:plc:abc123',
				'rkey' => '3kabc',
			)
		);

		\remove_filter( 'atmosphere_appview_host', $callback, 10 );

		$this->assertSame( 'bsky.app', $seen['host'] );
		$this->assertSame( 'profile/did:plc:abc123/post/3kabc', $seen['path'] );
		$this->assertSame( 'post', $seen['context']['type'] );
		$this->assertSame( 'did:plc:abc123', $seen['context']['did'] );
		$this->assertSame( '3kabc', $seen['context']['rkey'] );
	}

	/**
	 * A callback can vary the host by context type.
	 */
	public function test_appview_url_host_varies_by_context_type() {
		$callback = static function ( $host, $path, $context ) {
			return 'hashtag' === ( $context['type'] ?? '' ) ? 'bsky.app' : 'deer.social';
		};
		\add_filter( 'atmosphere_appview_host', $callback, 10, 3 );

		$profile = appview_url( 'profile/did:plc:abc123', array( 'type' => 'profile' ) );
		$hashtag = appview_url( 'hashtag/WordPress', array( 'type' => 'hashtag' ) );

		\remove_filter( 'atmosphere_appview_host', $callback, 10 );

		$this->assertSame( 'https://deer.social/profile/did:plc:abc123', $profile );
		$this->assertSame( 'https://bsky.app/hashtag/WordPress', $hashtag );
	}

	/**
	 * The helper returns an unescaped URL (callers own escaping).
	 */
	public function test_appview_url_returns_unescaped() {
		// An ampersand in the path is returned verbatim, not entity-encoded.
		$this->assertSame(
			'https://bsky.app/profile/a&b',
			appview_url( 'profile/a&b' )
		);
	}

	/**
	 * A filtered host can include a path prefix (appview on a subpath).
	 */
	public function test_appview_url_host_with_path_prefix() {
		$callback = static function () {
			return 'something.social/atblue';
		};
		\add_filter( 'atmosphere_appview_host', $callback );

		$this->assertSame(
			'https://something.social/atblue/profile/did:plc:abc123',
			appview_url( 'profile/did:plc:abc123' )
		);

		\remove_filter( 'atmosphere_appview_host', $callback );
	}

	/**
	 * A filtered host is normalized: a scheme and trailing slash are cleaned
	 * up so the join never produces doubled or empty segments.
	 */
	public function test_appview_url_host_is_normalized() {
		$callback = static function () {
			return 'https://sub.deer.social/atblue/';
		};
		\add_filter( 'atmosphere_appview_host', $callback );

		$this->assertSame(
			'https://sub.deer.social/atblue/hashtag/WordPress',
			appview_url( 'hashtag/WordPress' )
		);

		\remove_filter( 'atmosphere_appview_host', $callback );
	}

	/**
	 * A bare host with a trailing slash does not produce a double slash.
	 */
	public function test_appview_url_host_trailing_slash() {
		$callback = static function () {
			return 'deer.social/';
		};
		\add_filter( 'atmosphere_appview_host', $callback );

		$this->assertSame(
			'https://deer.social/profile/did:plc:abc123',
			appview_url( 'profile/did:plc:abc123' )
		);

		\remove_filter( 'atmosphere_appview_host', $callback );
	}

	/**
	 * An http scheme and port are preserved (e.g. for local testing).
	 */
	public function test_appview_url_preserves_http_and_port() {
		$callback = static function () {
			return 'http://localhost:3000';
		};
		\add_filter( 'atmosphere_appview_host', $callback );

		$this->assertSame(
			'http://localhost:3000/profile/did:plc:abc123',
			appview_url( 'profile/did:plc:abc123' )
		);

		\remove_filter( 'atmosphere_appview_host', $callback );
	}

	/**
	 * A non-http(s) scheme is clamped to https — including a javascript:
	 * scheme, which must never survive into the link host.
	 */
	public function test_appview_url_clamps_unsupported_scheme() {
		$ftp = static function () {
			return 'ftp://example.test';
		};
		\add_filter( 'atmosphere_appview_host', $ftp );
		$this->assertSame(
			'https://example.test/profile/did:plc:abc123',
			appview_url( 'profile/did:plc:abc123' )
		);
		\remove_filter( 'atmosphere_appview_host', $ftp );

		$js = static function () {
			return 'javascript://evil.test';
		};
		\add_filter( 'atmosphere_appview_host', $js );
		$url = appview_url( 'profile/did:plc:abc123' );
		\remove_filter( 'atmosphere_appview_host', $js );

		$this->assertSame( 'https://evil.test/profile/did:plc:abc123', $url );
		$this->assertStringNotContainsString( 'javascript', $url );
	}

	/**
	 * The host is lower-cased so a mixed-case filter return is normalized.
	 */
	public function test_appview_url_lowercases_host() {
		$callback = static function () {
			return 'Deer.Social/AtBlue';
		};
		\add_filter( 'atmosphere_appview_host', $callback );

		$this->assertSame(
			'https://deer.social/AtBlue/profile/did:plc:abc123',
			appview_url( 'profile/did:plc:abc123' )
		);

		\remove_filter( 'atmosphere_appview_host', $callback );
	}

	/**
	 * An empty filtered value falls back to the default appview silently
	 * (no _doing_it_wrong — an empty return just means "use the default").
	 */
	public function test_appview_url_falls_back_on_empty_host() {
		foreach ( array( '   ', '' ) as $empty ) {
			$callback = static function () use ( $empty ) {
				return $empty;
			};
			\add_filter( 'atmosphere_appview_host', $callback );

			$this->assertSame(
				'https://bsky.app/profile/did:plc:abc123',
				appview_url( 'profile/did:plc:abc123' )
			);

			\remove_filter( 'atmosphere_appview_host', $callback );
		}
	}

	/**
	 * A non-empty but unparseable filtered value falls back to the default
	 * appview and flags _doing_it_wrong (the callback returned garbage).
	 */
	public function test_appview_url_warns_on_malformed_host() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\appview_base_url' );

		$callback = static function () {
			return 'https://:80';
		};
		\add_filter( 'atmosphere_appview_host', $callback );

		$this->assertSame(
			'https://bsky.app/profile/did:plc:abc123',
			appview_url( 'profile/did:plc:abc123' )
		);

		\remove_filter( 'atmosphere_appview_host', $callback );
	}

	/**
	 * The atmosphere_appview_url filter can rewrite the whole URL, including
	 * the route, from the context (e.g. /account/ instead of /profile/).
	 */
	public function test_appview_url_full_url_filter_rewrites_route() {
		$callback = static function ( $url, $path, $context ) {
			if ( 'mention' === ( $context['type'] ?? '' ) ) {
				return 'https://bsky.app/account/' . $context['did'];
			}
			return $url;
		};
		\add_filter( 'atmosphere_appview_url', $callback, 10, 3 );

		$this->assertSame(
			'https://bsky.app/account/did:plc:abc123',
			appview_url(
				'profile/did:plc:abc123',
				array(
					'type' => 'mention',
					'did'  => 'did:plc:abc123',
				)
			)
		);

		\remove_filter( 'atmosphere_appview_url', $callback, 10 );
	}

	/**
	 * The atmosphere_appview_url filter receives the assembled URL, the path,
	 * and the context.
	 */
	public function test_appview_url_full_url_filter_receives_args() {
		$seen     = array();
		$callback = static function ( $url, $path, $context ) use ( &$seen ) {
			$seen = array(
				'url'     => $url,
				'path'    => $path,
				'context' => $context,
			);
			return $url;
		};
		\add_filter( 'atmosphere_appview_url', $callback, 10, 3 );

		appview_url(
			'hashtag/WordPress',
			array(
				'type' => 'hashtag',
				'tag'  => 'WordPress',
			)
		);

		\remove_filter( 'atmosphere_appview_url', $callback, 10 );

		$this->assertSame( 'https://bsky.app/hashtag/WordPress', $seen['url'] );
		$this->assertSame( 'hashtag/WordPress', $seen['path'] );
		$this->assertSame( 'hashtag', $seen['context']['type'] );
		$this->assertSame( 'WordPress', $seen['context']['tag'] );
	}

	/**
	 * Disconnect cleanup must remove queued events regardless of their
	 * args — per-post publish events carry a post ID, and a leftover
	 * event firing after a reconnect would issue applyWrites against
	 * a different repo.
	 */
	public function test_clear_scheduled_hooks_removes_events_with_args() {
		\wp_schedule_single_event( \time() + 60, 'atmosphere_publish_post', array( 123 ) );
		\wp_schedule_single_event( \time() + 60, 'atmosphere_update_post', array( 456 ) );
		\wp_schedule_single_event( \time() + 60, 'atmosphere_sync_publication' );

		\Atmosphere\clear_scheduled_hooks();

		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_publish_post', array( 123 ) ),
			'A queued per-post publish event must be cleared on disconnect.'
		);
		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_update_post', array( 456 ) ),
			'A queued per-post update event must be cleared on disconnect.'
		);
		$this->assertFalse(
			\wp_next_scheduled( 'atmosphere_sync_publication' ),
			'An argless event must still be cleared.'
		);
	}

	/**
	 * Deactivation/uninstall cleanup must also remove the queued revoke
	 * event, which always carries args (the encrypted token payload).
	 */
	public function test_clear_scheduled_hooks_all_removes_revoke_event_with_args() {
		\wp_schedule_single_event(
			\time() + 60,
			'atmosphere_revoke_refresh_token',
			array( 'ciphertext', 'https://auth.example.com/revoke' )
		);

		\Atmosphere\clear_scheduled_hooks_all();

		$this->assertFalse(
			\wp_next_scheduled(
				'atmosphere_revoke_refresh_token',
				array( 'ciphertext', 'https://auth.example.com/revoke' )
			),
			'The queued revoke event must be cleared at deactivation/uninstall.'
		);
	}

	/**
	 * The typeahead resolver defaults to Bluesky's official public appview
	 * searchActorsTypeahead endpoint.
	 */
	public function test_handle_typeahead_url_default() {
		$url = \Atmosphere\handle_typeahead_url();

		$this->assertStringContainsString( 'public.api.bsky.app', $url );
		$this->assertStringContainsString( 'searchActorsTypeahead', $url );
	}

	/**
	 * A site can repoint the endpoint through the filter.
	 */
	public function test_handle_typeahead_url_is_filterable() {
		\add_filter(
			'atmosphere_handle_typeahead_url',
			static fn () => 'https://example.test/xrpc/app.bsky.actor.searchActorsTypeahead'
		);

		$this->assertStringStartsWith( 'https://example.test/', \Atmosphere\handle_typeahead_url() );

		\remove_all_filters( 'atmosphere_handle_typeahead_url' );
	}

	/**
	 * Filtering to an empty string disables typeahead entirely.
	 */
	public function test_handle_typeahead_url_can_be_disabled() {
		\add_filter( 'atmosphere_handle_typeahead_url', '__return_empty_string' );

		$this->assertSame( '', \Atmosphere\handle_typeahead_url() );

		\remove_all_filters( 'atmosphere_handle_typeahead_url' );
	}

	/**
	 * Clean up the connection-only-mode filters after each relevant test so a
	 * lingering callback can't bleed into an unrelated test.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_connection_only_mode' );
		\remove_all_filters( 'atmosphere_should_auto_publish' );
		\remove_all_filters( 'atmosphere_should_sync_reactions' );
		\remove_all_filters( 'atmosphere_should_sync_replies' );
		\remove_all_filters( 'atmosphere_should_publish_comments' );

		parent::tear_down();
	}

	/**
	 * Connection-only mode is off unless a plugin opts in.
	 */
	public function test_connection_only_mode_off_by_default() {
		$this->assertFalse( is_connection_only_mode() );
	}

	/**
	 * A host plugin flips connection-only mode on through the filter.
	 */
	public function test_connection_only_mode_is_filterable() {
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );

		$this->assertTrue( is_connection_only_mode() );
	}

	/**
	 * Auto-publish is opt-out: on for a never-configured install.
	 */
	public function test_auto_publish_enabled_by_default() {
		$this->assertTrue( is_auto_publish_enabled() );
	}

	/**
	 * A saved "off" (any non-'1' value) turns auto-publish off.
	 */
	public function test_auto_publish_disabled_when_option_off() {
		\update_option( 'atmosphere_auto_publish', '0' );

		$this->assertFalse( is_auto_publish_enabled() );
	}

	/**
	 * Regression: an option stored programmatically as the integer 1 (rather than
	 * the string '1') must still read as enabled. The gates compare against '1',
	 * so they cast to string first — otherwise `'1' === 1` is false and a genuinely
	 * enabled feature would be mis-read as disabled. Covers all three sync gates.
	 */
	public function test_feature_gates_treat_integer_one_as_enabled() {
		\update_option( 'atmosphere_auto_publish', 1 );
		\update_option( 'atmosphere_sync_reactions', 1 );
		\update_option( 'atmosphere_sync_replies', 1 );

		$this->assertTrue( is_auto_publish_enabled() );
		$this->assertTrue( is_reaction_sync_enabled() );
		$this->assertTrue( is_reply_sync_enabled() );
	}

	/**
	 * Connection-only mode forces auto-publish off even when the stored option
	 * says on — the override is on effective behaviour, not just the default.
	 */
	public function test_auto_publish_forced_off_in_connection_only_mode() {
		\update_option( 'atmosphere_auto_publish', '1' );
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );

		$this->assertFalse( is_auto_publish_enabled() );
	}

	/**
	 * The per-feature filter is evaluated last, so a host can keep cross-posting
	 * on while otherwise running in connection-only mode.
	 */
	public function test_auto_publish_filter_can_reenable_in_connection_only_mode() {
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );
		\add_filter( 'atmosphere_should_auto_publish', '__return_true' );

		$this->assertTrue( is_auto_publish_enabled() );
	}

	/**
	 * Reaction import is opt-out and forced off in connection-only mode.
	 */
	public function test_reaction_sync_forced_off_in_connection_only_mode() {
		$this->assertTrue( is_reaction_sync_enabled() );

		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );

		$this->assertFalse( is_reaction_sync_enabled() );
	}

	/**
	 * The reaction filter has the final say over connection-only mode.
	 */
	public function test_reaction_sync_filter_can_reenable_in_connection_only_mode() {
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );
		\add_filter( 'atmosphere_should_sync_reactions', '__return_true' );

		$this->assertTrue( is_reaction_sync_enabled() );
	}

	/**
	 * Reply import is opt-out and forced off in connection-only mode.
	 */
	public function test_reply_sync_forced_off_in_connection_only_mode() {
		$this->assertTrue( is_reply_sync_enabled() );

		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );

		$this->assertFalse( is_reply_sync_enabled() );
	}

	/**
	 * The reply filter has the final say over connection-only mode.
	 */
	public function test_reply_sync_filter_can_reenable_in_connection_only_mode() {
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );
		\add_filter( 'atmosphere_should_sync_replies', '__return_true' );

		$this->assertTrue( is_reply_sync_enabled() );
	}

	/**
	 * Comment publishing (WordPress comments → Bluesky replies) is opt-out and
	 * forced off in connection-only mode, closing the outgoing lane too.
	 */
	public function test_comment_publishing_forced_off_in_connection_only_mode() {
		$this->assertTrue( is_comment_publishing_enabled() );

		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );

		$this->assertFalse( is_comment_publishing_enabled() );
	}

	/**
	 * The comment-publishing filter has the final say over connection-only mode.
	 */
	public function test_comment_publishing_filter_can_reenable_in_connection_only_mode() {
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );
		\add_filter( 'atmosphere_should_publish_comments', '__return_true' );

		$this->assertTrue( is_comment_publishing_enabled() );
	}

	/**
	 * The Bluesky-companion lane defaults to enabled.
	 *
	 * @group atmosphere
	 */
	public function test_is_bluesky_post_enabled_defaults_true() {
		$post = self::factory()->post->create_and_get();
		$this->assertTrue( is_bluesky_post_enabled( $post ) );
	}

	/**
	 * A site-wide (one-argument) callback still disables the companion post —
	 * the added post parameter must not break callbacks that ignore it.
	 *
	 * @group atmosphere
	 */
	public function test_is_bluesky_post_enabled_filter_can_disable() {
		$post = self::factory()->post->create_and_get();

		\add_filter( 'atmosphere_should_publish_bluesky_post', '__return_false' );
		$this->assertFalse( is_bluesky_post_enabled( $post ) );
		\remove_filter( 'atmosphere_should_publish_bluesky_post', '__return_false' );
	}

	/**
	 * `set_identity()` persists the canonical shape so `get_identity()`
	 * reads it back, keeping the option's structure in one place.
	 *
	 * @group atmosphere
	 */
	public function test_set_identity_round_trips() {
		\delete_option( 'atmosphere_identity' );

		$this->assertTrue(
			set_identity(
				array(
					'did'          => 'did:plc:abc123',
					'handle'       => 'me.example.com',
					'pds_endpoint' => 'https://pds.example.com',
					'extra'        => 'dropped',
				)
			)
		);

		$this->assertSame(
			array(
				'did'          => 'did:plc:abc123',
				'handle'       => 'me.example.com',
				'pds_endpoint' => 'https://pds.example.com',
			),
			get_identity(),
			'Only the three canonical fields are stored, and get_identity() reads them back.'
		);

		\delete_option( 'atmosphere_identity' );
	}

	/**
	 * A non-scalar value must not become the literal "Array", which
	 * `has_identity()` would treat as a live identity and the well-known
	 * endpoint would then serve.
	 */
	public function test_set_identity_drops_non_scalar_values() {
		set_identity(
			array(
				'did'          => array( 'nested' => 'value' ),
				'handle'       => 'example.com',
				'pds_endpoint' => 'https://pds.example.com',
			)
		);

		$stored = \get_option( 'atmosphere_identity' );

		$this->assertSame( '', $stored['did'] );
		$this->assertStringNotContainsString( 'Array', (string) $stored['did'] );
	}

	/**
	 * The helper replaces rather than merges. Pinned because the failure is
	 * silent and expensive: a partial call clears the DID, which takes
	 * `has_identity()` false and stops the well-known endpoint answering.
	 */
	public function test_set_identity_replaces_rather_than_merges() {
		set_identity(
			array(
				'did'          => 'did:plc:test123',
				'handle'       => 'old.example.com',
				'pds_endpoint' => 'https://pds.example.com',
			)
		);

		set_identity( array( 'handle' => 'new.example.com' ) );

		$stored = \get_option( 'atmosphere_identity' );

		$this->assertSame( 'new.example.com', $stored['handle'] );
		$this->assertSame( '', $stored['did'] );
	}

	/**
	 * The filter receives the post being published, so a callback can answer
	 * per post — here, gating on the post type.
	 *
	 * @group atmosphere
	 */
	public function test_is_bluesky_post_enabled_filter_receives_post() {
		$article = self::factory()->post->create_and_get( array( 'post_type' => 'post' ) );
		$page    = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );

		\add_filter(
			'atmosphere_should_publish_bluesky_post',
			static function ( $enabled, $post ) {
				return 'page' === $post->post_type ? false : $enabled;
			},
			10,
			2
		);

		$this->assertTrue( is_bluesky_post_enabled( $article ), 'Posts keep the Bluesky companion.' );
		$this->assertFalse( is_bluesky_post_enabled( $page ), 'Pages are routed document-only.' );

		\remove_all_filters( 'atmosphere_should_publish_bluesky_post' );
	}
}
