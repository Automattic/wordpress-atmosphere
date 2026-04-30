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
use function Atmosphere\to_iso8601;

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
	 * Test truncate_text respects limit.
	 */
	public function test_truncate_text_short() {
		$this->assertSame( 'Hello', truncate_text( 'Hello', 300 ) );
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
}
