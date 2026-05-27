<?php
/**
 * Tests for the Publication transformer.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

use WP_UnitTestCase;
use Atmosphere\Transformer\Publication;

/**
 * Publication transformer tests.
 */
class Test_Publication extends WP_UnitTestCase {

	/**
	 * Test hex_to_rgb with a full hex color.
	 */
	public function test_hex_to_rgb_full() {
		$rgb = Publication::hex_to_rgb( '#ff8800' );

		$this->assertSame( 255, $rgb['r'] );
		$this->assertSame( 136, $rgb['g'] );
		$this->assertSame( 0, $rgb['b'] );
	}

	/**
	 * Test hex_to_rgb with shorthand hex.
	 */
	public function test_hex_to_rgb_shorthand() {
		$rgb = Publication::hex_to_rgb( '#f80' );

		$this->assertSame( 255, $rgb['r'] );
		$this->assertSame( 136, $rgb['g'] );
		$this->assertSame( 0, $rgb['b'] );
	}

	/**
	 * Test hex_to_rgb rejects invalid input.
	 */
	public function test_hex_to_rgb_invalid() {
		$this->assertNull( Publication::hex_to_rgb( 'not-a-color' ) );
		$this->assertNull( Publication::hex_to_rgb( 'var(--wp-color)' ) );
	}

	/**
	 * Test that the publication TID is stable across calls.
	 */
	public function test_publication_tid_stable() {
		\delete_option( Publication::OPTION_TID );

		$pub  = new Publication( null );
		$rkey = $pub->get_rkey();

		$this->assertNotEmpty( $rkey );
		$this->assertSame( $rkey, $pub->get_rkey() );
	}

	/**
	 * Test the collection NSID.
	 */
	public function test_collection() {
		$pub = new Publication( null );

		$this->assertSame( 'site.standard.publication', $pub->get_collection() );
	}

	/**
	 * The site icon populates the spec-compliant `icon` field, not the
	 * legacy non-spec `avatar` field.
	 *
	 * Seeds the `_atmosphere_blob_ref` cache so the transformer resolves
	 * the blob from post meta instead of performing a network upload.
	 */
	public function test_site_icon_maps_to_icon_field() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'site-icon.png',
				'post_mime_type' => 'image/png',
			),
			0,
			array(
				'post_title' => 'Site icon',
			)
		);

		$blob = array(
			'cid'      => 'bafyicon',
			'mimeType' => 'image/png',
			'size'     => 4096,
		);
		\update_post_meta( $attachment_id, '_atmosphere_blob_ref', $blob );
		\update_option( 'site_icon', $attachment_id );

		$record = ( new Publication( null ) )->transform();

		$this->assertSame( 'site.standard.publication', $record['$type'] );
		$this->assertSame( $blob, $record['icon'], 'Site icon should populate the spec `icon` field.' );
		$this->assertArrayNotHasKey( 'avatar', $record, 'The non-spec `avatar` field must not be present.' );
	}

	/**
	 * When a site icon is set but its blob cannot be uploaded, the `icon`
	 * key is omitted rather than written as null. The attachment has no
	 * backing file and no cached blob ref, so upload_image_blob() returns
	 * null at its `! $file` guard without a network call.
	 */
	public function test_site_icon_omitted_when_blob_upload_fails() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'missing.png',
				'post_mime_type' => 'image/png',
			),
			0,
			array(
				'post_title' => 'Site icon',
			)
		);
		\delete_post_meta( $attachment_id, '_wp_attached_file' );
		\update_option( 'site_icon', $attachment_id );

		$record = ( new Publication( null ) )->transform();

		$this->assertArrayNotHasKey( 'icon', $record );
	}

	/**
	 * The publication record uses the spec field `name` and omits the
	 * non-spec `displayName` field carried over from the bsky profile shape.
	 */
	public function test_record_omits_non_spec_display_name() {
		$record = ( new Publication( null ) )->transform();

		$this->assertArrayHasKey( 'name', $record );
		$this->assertArrayNotHasKey( 'displayName', $record );
	}

	/**
	 * The name and description are HTML-entity decoded before they reach
	 * the record. WordPress stores `blogname` / `blogdescription`
	 * entity-encoded (esc_html at save time), so the raw values carry
	 * `&#039;`, `&amp;`, etc. Use `pre_option_*` filters to inject the
	 * stored (encoded) form deterministically.
	 */
	public function test_name_and_description_decode_html_entities() {
		\add_filter( 'pre_option_blogname', static fn() => 'Toni&#039;s blog' );
		\add_filter( 'pre_option_blogdescription', static fn() => 'Tom &amp; Jerry' );

		try {
			$record = ( new Publication( null ) )->transform();
		} finally {
			\remove_all_filters( 'pre_option_blogname' );
			\remove_all_filters( 'pre_option_blogdescription' );
		}

		$this->assertSame( "Toni's blog", $record['name'] );
		$this->assertSame( 'Tom & Jerry', $record['description'] );
	}

	/**
	 * End-to-end proof against WordPress's real storage path: a site name
	 * and tagline saved with special characters round-trip to clean text
	 * in the record.
	 *
	 * `update_option()` runs the value through `sanitize_option()`, which
	 * `esc_html()`s `blogname` / `blogdescription` exactly once. `esc_html()`
	 * is idempotent (`_wp_specialchars()` defaults to `$double_encode =
	 * false`), so the stored value is always single-encoded — a single
	 * `html_entity_decode()` pass in `sanitize_text()` fully decodes it.
	 * This is the realistic counterpart to the `pre_option_*` test above,
	 * which injects an arbitrary encoded string directly.
	 */
	public function test_name_and_description_round_trip_real_option_values() {
		\update_option( 'blogname', "Toni's blog & Co" );
		\update_option( 'blogdescription', "Books, coffee & friends'" );

		$record = ( new Publication( null ) )->transform();

		$this->assertSame( "Toni's blog & Co", $record['name'] );
		$this->assertSame( "Books, coffee & friends'", $record['description'] );
	}
}
