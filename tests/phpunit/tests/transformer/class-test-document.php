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

use WP_UnitTestCase;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;

/**
 * Document transformer tests.
 */
class Test_Document extends WP_UnitTestCase {

	/**
	 * Test that content field is absent when parser filter returns null.
	 */
	public function test_content_absent_without_parser() {
		\remove_all_filters( 'atmosphere_content_parser' );
		\add_filter( 'atmosphere_content_parser', '__return_null' );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Some content here.' )
		);

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayNotHasKey( 'content', $record );

		\remove_all_filters( 'atmosphere_content_parser' );
	}

	/**
	 * Test that content field is present when a parser is registered via filter.
	 */
	public function test_content_present_with_parser_filter() {
		\add_filter(
			'atmosphere_content_parser',
			static fn() => new Stub_Parser()
		);

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Hello world.' )
		);

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayHasKey( 'content', $record );
		$this->assertSame( 'test.stub.parser', $record['content']['$type'] );
		$this->assertSame( 'Hello world.', $record['content']['text'] );

		\remove_all_filters( 'atmosphere_content_parser' );
	}

	/**
	 * Password-protected posts must not expose protected fields through
	 * document records, even when the transformer is called directly.
	 */
	public function test_password_protected_document_is_redacted() {
		\add_filter(
			'atmosphere_content_parser',
			static fn() => new Stub_Parser()
		);

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

		\remove_all_filters( 'atmosphere_content_parser' );
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
	 * Test that returning null from the parser filter disables content.
	 */
	public function test_content_disabled_with_null_filter() {
		\add_filter( 'atmosphere_content_parser', '__return_null' );

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Some content.' )
		);

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayNotHasKey( 'content', $record );

		\remove_all_filters( 'atmosphere_content_parser' );
	}

	/**
	 * Test that a non-Content_Parser return from the filter is ignored.
	 */
	public function test_content_ignored_with_invalid_parser() {
		\add_filter(
			'atmosphere_content_parser',
			static fn() => 'not a parser'
		);

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Some content.' )
		);

		$transformer = new Document( $post );
		$record      = $transformer->transform();

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

		\add_filter( 'atmosphere_content_parser', static fn() => $parser );

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

		\remove_all_filters( 'atmosphere_content_parser' );
		\remove_all_filters( 'atmosphere_document_content' );
	}

	/**
	 * Test that content field is absent for empty post content.
	 */
	public function test_content_absent_for_empty_content() {
		\add_filter(
			'atmosphere_content_parser',
			static fn() => new Stub_Parser()
		);

		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '' )
		);

		$transformer = new Document( $post );
		$record      = $transformer->transform();

		$this->assertArrayNotHasKey( 'content', $record );

		\remove_all_filters( 'atmosphere_content_parser' );
	}

	/**
	 * Test the atmosphere_document_content filter can modify parsed content.
	 */
	public function test_document_content_filter() {
		\add_filter(
			'atmosphere_content_parser',
			static fn() => new Stub_Parser()
		);

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

		\remove_all_filters( 'atmosphere_content_parser' );
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
	 * Test the collection NSID.
	 */
	public function test_collection() {
		$post        = self::factory()->post->create_and_get();
		$transformer = new Document( $post );

		$this->assertSame( 'site.standard.document', $transformer->get_collection() );
	}
}
