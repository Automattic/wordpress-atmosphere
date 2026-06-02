<?php
/**
 * Tests for ATmosphere helper functions.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Tests;

use WP_UnitTestCase;
use function Atmosphere\parse_at_uri;
use function Atmosphere\build_at_uri;
use function Atmosphere\sanitize_text;
use function Atmosphere\truncate_text;
use function Atmosphere\truncate_graphemes;
use function Atmosphere\to_iso8601;
use function Atmosphere\is_post_publishable;
use function Atmosphere\get_connection;

/**
 * Function tests.
 */
class Test_Functions extends WP_UnitTestCase {

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
}
