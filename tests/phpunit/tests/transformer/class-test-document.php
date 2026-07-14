<?php
/**
 * Tests for the Document transformer content parser integration.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

require_once __DIR__ . '/class-stub-parser.php';
require_once __DIR__ . '/class-tid-decoder.php';
require_once __DIR__ . '/../content-parser/class-fake-parser.php';

use Atmosphere\Atmosphere;
use Atmosphere\Content_Parser\Html;
use Atmosphere\Content_Parser\Parser_Base;
use Atmosphere\Content_Parser\Registry;
use Atmosphere\Tests\Content_Parser\Fake_Parser;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\TID;

/**
 * Document transformer tests.
 */
class Test_Document extends \WP_UnitTestCase {

	/**
	 * Start each test from an empty registry so selection is
	 * deterministic, regardless of the bootstrap defaults.
	 */
	public function set_up(): void {
		parent::set_up();
		Parser_Base::flush_block_cache();
		Registry::reset();
	}

	/**
	 * Restore the bootstrap default parsers so later test files see the
	 * registry in its normal state.
	 */
	public function tear_down(): void {
		Registry::reset();
		Parser_Base::flush_block_cache();
		\delete_option( Registry::OPTION_FORMAT );
		\remove_all_filters( 'atmosphere_document_links' );
		\remove_all_filters( 'atmosphere_document_labels' );
		\remove_all_filters( 'atmosphere_document_contributors' );
		\remove_all_filters( 'atmosphere_use_historical_tid' );
		Atmosphere::register_default_content_parsers();
		parent::tear_down();
	}

	/**
	 * Test that content field is absent when no parser is registered.
	 */
	public function test_content_absent_without_parser() {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Some content here.' )
		);

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayNotHasKey( 'content', $record );
	}

	/**
	 * Test that content field is present when a parser is registered.
	 */
	public function test_content_present_with_registered_parser() {
		Registry::register( new Stub_Parser() );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Hello world.' )
		);

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayHasKey( 'content', $record );
		$this->assertSame( 'test.stub.parser', $record['content']['$type'] );
		$this->assertSame( 'Hello world.', $record['content']['text'] );
	}

	/**
	 * The deprecated atmosphere_content_parser filter still selects a
	 * parser, emitting a deprecation notice.
	 */
	public function test_legacy_filter_still_selects_parser() {
		$this->setExpectedDeprecated( 'atmosphere_content_parser' );

		\add_filter( 'atmosphere_content_parser', static fn() => new Stub_Parser() );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Legacy hello.' )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertArrayHasKey( 'content', $record );
		$this->assertSame( 'test.stub.parser', $record['content']['$type'] );
		$this->assertSame( 'Legacy hello.', $record['content']['text'] );

		\remove_all_filters( 'atmosphere_content_parser' );
	}

	/**
	 * The legacy filter wins over a registered parser.
	 */
	public function test_legacy_filter_beats_registry() {
		$this->setExpectedDeprecated( 'atmosphere_content_parser' );

		Registry::register( new Fake_Parser( 'test.registry' ) );
		\add_filter( 'atmosphere_content_parser', static fn() => new Stub_Parser() );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Hi.' )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertSame( 'test.stub.parser', $record['content']['$type'] );

		\remove_all_filters( 'atmosphere_content_parser' );
	}

	/**
	 * A null return from the legacy filter keeps the old "omit content"
	 * behavior instead of falling through to the registry.
	 */
	public function test_null_legacy_filter_suppresses_content() {
		$this->setExpectedDeprecated( 'atmosphere_content_parser' );

		Registry::register( new Stub_Parser() );
		\add_filter( 'atmosphere_content_parser', '__return_null' );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Hi.' )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertArrayNotHasKey( 'content', $record );

		\remove_all_filters( 'atmosphere_content_parser' );
	}

	/**
	 * The atmosphere_content_format option selects the active parser end
	 * to end through Document::transform().
	 */
	public function test_content_format_option_selects_parser() {
		Registry::register( new Fake_Parser( 'test.default' ), 10 );
		Registry::register( new Fake_Parser( 'test.chosen' ), 20 );
		\update_option( Registry::OPTION_FORMAT, 'test.chosen' );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Hi.' )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertSame( 'test.chosen', $record['content']['$type'] );

		\delete_option( Registry::OPTION_FORMAT );
	}

	/**
	 * A pinned block parser falls back to rendered HTML when render-time
	 * filters hide the saved block content.
	 */
	public function test_hidden_saved_block_content_falls_back_to_html() {
		Atmosphere::register_default_content_parsers();
		\update_option( Registry::OPTION_FORMAT, 'pub.leaflet.content' );

		$filter = static function (): string {
			return '<p>Public replacement.</p>';
		};
		\add_filter( 'the_content', $filter, \PHP_INT_MAX );

		try {
			$post = self::factory()->post->create_and_get(
				array(
					'post_content' => '<!-- wp:paragraph --><p>Private original body.</p><!-- /wp:paragraph -->',
				)
			);

			$record = ( new Document( $post ) )->transform();
			$json   = (string) \wp_json_encode( $record );

			$this->assertSame( Html::TYPE, $record['content']['$type'] );
			$this->assertStringContainsString( 'Public replacement.', $record['content']['html'] );
			$this->assertStringNotContainsString( 'Private original body.', $json );
		} finally {
			\remove_filter( 'the_content', $filter, \PHP_INT_MAX );
		}
	}

	/**
	 * Public document records do not include a Bluesky back-reference.
	 */
	public function test_document_omits_bsky_post_ref() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Public post',
				'post_content' => 'Public body.',
			)
		);

		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test/app.bsky.feed.post/public' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafypublic' );

		$record = ( new Document( $post ) )->transform();

		$this->assertArrayNotHasKey( 'bskyPostRef', $record );
	}

	/**
	 * Password-protected posts must not expose protected fields through
	 * document records, even when the transformer is called directly.
	 */
	public function test_password_protected_document_is_redacted() {
		Registry::register( new Stub_Parser() );

		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'CONFIDENTIAL-TITLE',
				'post_content'  => 'CONFIDENTIAL-BODY',
				'post_excerpt'  => 'CONFIDENTIAL-EXCERPT',
				'post_password' => 'secret',
			)
		);
		\wp_set_post_tags( $post->ID, array( 'CONFIDENTIAL-TAG' ) );

		\update_post_meta( $post->ID, Post::META_URI, 'at://did:plc:test/app.bsky.feed.post/secret' );
		\update_post_meta( $post->ID, Post::META_CID, 'bafysecret' );

		$record = ( new Document( $post ) )->transform();
		$json   = (string) \wp_json_encode( $record );

		$this->assertSame( '', $record['title'] );
		$this->assertArrayNotHasKey( 'path', $record );
		$this->assertArrayNotHasKey( 'description', $record );
		$this->assertArrayNotHasKey( 'textContent', $record );
		$this->assertArrayNotHasKey( 'content', $record );
		$this->assertArrayNotHasKey( 'tags', $record );
		$this->assertArrayNotHasKey( 'bskyPostRef', $record );
		$this->assertStringNotContainsString( 'CONFIDENTIAL', $json );
	}

	/**
	 * A literal password value of "0" is still redacted in document output.
	 */
	public function test_zero_string_password_document_is_redacted() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'CONFIDENTIAL-TITLE',
				'post_content'  => 'CONFIDENTIAL-BODY',
				'post_password' => '0',
			)
		);

		$record = ( new Document( $post ) )->transform();
		$json   = (string) \wp_json_encode( $record );

		$this->assertSame( '', $record['title'] );
		$this->assertArrayNotHasKey( 'textContent', $record );
		$this->assertStringNotContainsString( 'CONFIDENTIAL', $json );
	}

	/**
	 * Draft documents are redacted and do not expose a publishedAt timestamp.
	 */
	public function test_draft_document_is_redacted_without_published_at() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'CONFIDENTIAL-TITLE',
				'post_content' => 'CONFIDENTIAL-BODY',
			)
		);

		$record = ( new Document( $post ) )->transform();
		$json   = (string) \wp_json_encode( $record );

		$this->assertSame( '', $record['title'] );
		$this->assertArrayNotHasKey( 'publishedAt', $record );
		$this->assertArrayNotHasKey( 'textContent', $record );
		$this->assertStringNotContainsString( 'CONFIDENTIAL', $json );
	}

	/**
	 * Redacted documents must not expose the raw post object to filters.
	 */
	public function test_password_protected_document_does_not_fire_record_filter() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'CONFIDENTIAL-TITLE',
				'post_content'  => 'CONFIDENTIAL-BODY',
				'post_password' => 'secret',
			)
		);

		$called = false;
		\add_filter(
			'atmosphere_transform_document',
			static function ( array $record ) use ( &$called ): array {
				$called          = true;
				$record['title'] = 'CONFIDENTIAL-REINJECTED';
				return $record;
			}
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertSame( '', $record['title'] );
		$this->assertFalse( $called, 'Redacted documents must not expose the post object to filters.' );

		\remove_all_filters( 'atmosphere_transform_document' );
	}

	/**
	 * A non-Content_Parser return from the legacy filter preserves the
	 * old behavior by omitting content.
	 */
	public function test_invalid_legacy_filter_suppresses_content() {
		$this->setExpectedDeprecated( 'atmosphere_content_parser' );

		Registry::register( new Stub_Parser() );
		\add_filter( 'atmosphere_content_parser', static fn() => 'not a parser' );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Some content.' )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertArrayNotHasKey( 'content', $record );

		\remove_all_filters( 'atmosphere_content_parser' );
	}

	/**
	 * Test that when the parser returns null for non-empty content,
	 * the content field is omitted and the atmosphere_document_content
	 * filter is not invoked.
	 */
	public function test_content_absent_when_parser_returns_null() {
		$parser              = new Stub_Parser();
		$parser->return_null = true;

		Registry::register( $parser );

		$filter_called = false;
		\add_filter(
			'atmosphere_document_content',
			static function ( $content ) use ( &$filter_called ) {
				$filter_called = true;
				return $content;
			}
		);

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Some content.' )
		);

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayNotHasKey( 'content', $record );
		$this->assertFalse( $filter_called );

		\remove_all_filters( 'atmosphere_document_content' );
	}

	/**
	 * Parser output without a $type is rejected before publishing.
	 */
	public function test_content_absent_when_parser_omits_type() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Transformer\\Document::validate_content' );

		$parser            = new Stub_Parser();
		$parser->omit_type = true;

		Registry::register( $parser );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Some content.' )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertArrayNotHasKey( 'content', $record );
	}

	/**
	 * Parser output whose $type does not match get_type() is rejected.
	 */
	public function test_content_absent_when_parser_type_mismatches_get_type() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Transformer\\Document::validate_content' );

		$parser              = new Stub_Parser();
		$parser->output_type = 'test.other.parser';

		Registry::register( $parser );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Some content.' )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertArrayNotHasKey( 'content', $record );
	}

	/**
	 * Test that content field is absent for empty post content.
	 */
	public function test_content_absent_for_empty_content() {
		Registry::register( new Stub_Parser() );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '' )
		);

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayNotHasKey( 'content', $record );
	}

	/**
	 * Test the atmosphere_document_content filter can modify parsed content.
	 */
	public function test_document_content_filter() {
		Registry::register( new Stub_Parser() );

		\add_filter(
			'atmosphere_document_content',
			static function ( array $content ) {
				$content['modified'] = true;
				return $content;
			}
		);

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Hello.' )
		);

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayHasKey( 'content', $record );
		$this->assertTrue( $record['content']['modified'] );

		\remove_all_filters( 'atmosphere_document_content' );
	}

	/**
	 * Invalid content-filter output falls back to the parser's valid object.
	 */
	public function test_invalid_document_content_filter_falls_back_to_parser_output() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Transformer\\Document::validate_content' );

		Registry::register( new Stub_Parser() );

		\add_filter(
			'atmosphere_document_content',
			static function ( array $content ): array {
				unset( $content['$type'] );
				return $content;
			}
		);

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Hello.' )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertSame( 'test.stub.parser', $record['content']['$type'] );
		$this->assertSame( 'Hello.', $record['content']['text'] );

		\remove_all_filters( 'atmosphere_document_content' );
	}

	/**
	 * Test that site field falls back to home URL without publication TID.
	 */
	public function test_site_fallback_to_home_url() {
		\delete_option( 'atmosphere_publication_tid' );

		$post = self::factory()->post->create_and_get();

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayHasKey( 'site', $record );
		$this->assertSame( \untrailingslashit( \get_home_url() ), $record['site'] );
	}

	/**
	 * Test that site field uses AT-URI when publication TID exists.
	 */
	public function test_site_uses_at_uri_with_publication_tid() {
		\update_option( 'atmosphere_publication_tid', 'test-tid-123' );
		\update_option( 'atmosphere_did', 'did:plc:test' );

		$post = self::factory()->post->create_and_get();

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayHasKey( 'site', $record );
		$this->assertStringStartsWith( 'at://', $record['site'] );
		$this->assertStringContainsString( 'site.standard.publication', $record['site'] );
		$this->assertStringContainsString( 'test-tid-123', $record['site'] );

		\delete_option( 'atmosphere_publication_tid' );
		\delete_option( 'atmosphere_did' );
	}

	/**
	 * Extensions can add a typed links union to document records.
	 */
	public function test_document_links_filter_adds_typed_union() {
		\add_filter(
			'atmosphere_document_links',
			static fn() => array(
				'$type' => 'example.links',
				'items' => array(
					array(
						'uri'   => 'https://example.com/source',
						'label' => 'Source',
					),
				),
			)
		);

		$post   = self::factory()->post->create_and_get();
		$record = ( new Document( $post ) )->transform();

		$this->assertSame( 'example.links', $record['links']['$type'] );
		$this->assertSame( 'https://example.com/source', $record['links']['items'][0]['uri'] );
	}

	/**
	 * Extensions can add standard self-labels to document records.
	 */
	public function test_document_labels_filter_adds_self_labels() {
		\add_filter(
			'atmosphere_document_labels',
			static fn() => array(
				'$type'  => 'com.atproto.label.defs#selfLabels',
				'values' => array(
					array( 'val' => 'nudity' ),
				),
			)
		);

		$post   = self::factory()->post->create_and_get();
		$record = ( new Document( $post ) )->transform();

		$this->assertSame( 'com.atproto.label.defs#selfLabels', $record['labels']['$type'] );
		$this->assertSame( 'nudity', $record['labels']['values'][0]['val'] );
	}

	/**
	 * Extensions can add sanitized contributor records.
	 */
	public function test_document_contributors_filter_adds_sanitized_contributors() {
		\add_filter(
			'atmosphere_document_contributors',
			static fn() => array(
				array(
					'did'         => 'did:plc:editor123',
					'role'        => '<b>Editor</b>',
					'displayName' => 'Jane &amp; Team',
				),
			)
		);

		$post   = self::factory()->post->create_and_get();
		$record = ( new Document( $post ) )->transform();

		$this->assertSame(
			array(
				array(
					'did'         => 'did:plc:editor123',
					'role'        => 'Editor',
					'displayName' => 'Jane & Team',
				),
			),
			$record['contributors']
		);
	}

	/**
	 * Test the collection NSID.
	 */
	public function test_collection() {
		$post        = self::factory()->post->create_and_get();
		$transformer = new Document( $post );

		$this->assertSame( 'site.standard.document', $transformer->get_collection() );
	}

	/**
	 * By default, get_rkey() mints a historical TID for the post's
	 * original publish date, so a 2010 post lands at a 2010 rkey
	 * regardless of when the backfill runs.
	 *
	 * @covers \Atmosphere\Transformer\Document::get_rkey
	 */
	public function test_get_rkey_defaults_to_historical_tid() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_date'     => '2010-01-15 12:00:00',
				'post_date_gmt' => '2010-01-15 12:00:00',
			)
		);

		// Capture the historical rkey first so the assertion compares
		// purely TID encodings rather than the side effect of meta.
		$historical_rkey = ( new Document( $post ) )->get_rkey();
		$current_rkey    = TID::generate();

		$this->assertNotEmpty( $historical_rkey );
		$this->assertTrue( TID::is_valid( $historical_rkey ) );
		$this->assertLessThan( $current_rkey, $historical_rkey, '2010-anchored rkey must sort before a now-minted TID.' );

		// Lock the encoding contract: decoding the rkey must return the
		// same microseconds we'd get from the helper for this post.
		$expected_microseconds = TID::microseconds_from_post_date( '2010-01-15 12:00:00', $post->ID );
		$this->assertSame(
			$expected_microseconds,
			TID_Decoder::tid_to_microseconds( $historical_rkey ),
			'Decoded rkey microseconds must match microseconds_from_post_date().'
		);
	}

	/**
	 * The standard.site document's rich HTML content keeps @mention links —
	 * the document parser path renders through the_content and is NOT covered
	 * by the Bluesky-text suppression guard.
	 */
	public function test_document_content_linkifies_mentions() {
		\Atmosphere\Mention::init();
		Registry::register( new Html() );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Hello @alice.bsky.social!' )
		);

		$record = ( new Document( $post ) )->transform();

		$this->assertArrayHasKey( 'content', $record );
		$this->assertSame( Html::TYPE, $record['content']['$type'] );
		$this->assertStringContainsString(
			'class="atmosphere-mention"',
			$record['content']['html']
		);
	}

	/**
	 * Listeners returning false from the atmosphere_use_historical_tid
	 * filter fall back to the now-based TID::generate() path.
	 *
	 * @covers \Atmosphere\Transformer\Document::get_rkey
	 */
	public function test_get_rkey_filter_opt_out_uses_current_time() {
		\add_filter( 'atmosphere_use_historical_tid', '__return_false' );

		$post = self::factory()->post->create_and_get(
			array(
				'post_date'     => '2010-01-15 12:00:00',
				'post_date_gmt' => '2010-01-15 12:00:00',
			)
		);

		// A baseline current-time TID minted just before the rkey:
		// the filter-disabled rkey should sort right next to it,
		// not anywhere near a 2010 historical TID.
		$baseline        = TID::generate();
		$rkey            = ( new Document( $post ) )->get_rkey();
		$historical_2010 = TID::generate_for_time(
			TID::microseconds_from_post_date( '2010-01-15 12:00:00', $post->ID ),
			Document::TID_SALT_PREFIX . $post->ID
		);

		$this->assertGreaterThan( $baseline, $rkey, 'Opting out via filter must mint a now-based TID.' );
		$this->assertGreaterThan( $historical_2010, $rkey, 'Opted-out TID must sort after a 2010 historical TID.' );
	}

	/**
	 * Preview projections must stay read-only: a featured image whose
	 * blob has never been uploaded is omitted from the previewed record
	 * instead of triggering a PDS blob upload (and a blob-ref meta
	 * write) as a side effect of a preview GET.
	 */
	public function test_preview_records_do_not_upload_uncached_cover_image() {
		$upload_dir = \wp_upload_dir();
		$path       = $upload_dir['basedir'] . '/atmosphere-doc-preview-test.jpg';
		\file_put_contents( $path, 'LOCAL-IMAGE-BYTES' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$attachment_id = self::factory()->attachment->create_object(
			$path,
			0,
			array( 'post_mime_type' => 'image/jpeg' )
		);

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Long-form blog body.',
			)
		);
		\set_post_thumbnail( $post->ID, $attachment_id );

		$attempted     = false;
		$short_circuit = static function () use ( &$attempted ) {
			$attempted = true;
			return array( 'blob' => array( 'cid' => 'bafyupload' ) );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $short_circuit );

		$records = ( new Document( $post ) )->get_preview_records();

		\remove_filter( 'atmosphere_pre_upload_blob', $short_circuit );
		\wp_delete_file( $path );

		$this->assertFalse( $attempted, 'Previewing must not upload blobs.' );
		$this->assertArrayNotHasKey( 'coverImage', $records[0] );
		$this->assertSame( '', (string) \get_post_meta( $attachment_id, '_atmosphere_blob_ref', true ) );
	}

	/**
	 * A previously-uploaded cover image blob is reused from its cached
	 * ref in preview projections, so the previewed record — and any CID
	 * computed from it — matches what a publish would write, without a
	 * network round-trip.
	 */
	public function test_preview_records_use_cached_cover_image_blob() {
		$attachment_id = self::factory()->attachment->create_object(
			'2026/06/cached-cover.jpg',
			0,
			array( 'post_mime_type' => 'image/jpeg' )
		);

		$cached_ref = array(
			'$type'    => 'blob',
			'ref'      => array( '$link' => 'bafycachedcover' ),
			'mimeType' => 'image/jpeg',
			'size'     => 123,
		);
		\update_post_meta( $attachment_id, '_atmosphere_blob_ref', $cached_ref );

		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'A Titled Post',
				'post_content' => 'Long-form blog body.',
			)
		);
		\set_post_thumbnail( $post->ID, $attachment_id );

		$attempted     = false;
		$short_circuit = static function () use ( &$attempted ) {
			$attempted = true;
			return array( 'blob' => array( 'cid' => 'bafyupload' ) );
		};
		\add_filter( 'atmosphere_pre_upload_blob', $short_circuit );

		$records = ( new Document( $post ) )->get_preview_records();

		\remove_filter( 'atmosphere_pre_upload_blob', $short_circuit );

		$this->assertFalse( $attempted, 'A cached blob ref must not trigger an upload.' );
		$this->assertSame( $cached_ref, $records[0]['coverImage'] );
	}
}
