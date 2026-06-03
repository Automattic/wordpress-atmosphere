<?php
/**
 * Tests for the pub.leaflet.content parser.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group content-parser
 */

namespace Atmosphere\Tests\Content_Parser;

require_once __DIR__ . '/trait-block-fixtures.php';

use WP_UnitTestCase;
use Atmosphere\Content_Parser\Leaflet;

/**
 * Leaflet parser tests.
 */
class Test_Leaflet extends WP_UnitTestCase {

	use Block_Fixtures;

	/**
	 * Parser instance.
	 *
	 * @var Leaflet
	 */
	private Leaflet $parser;

	/**
	 * Set up fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->parser = new Leaflet();
	}

	/**
	 * Reports the lexicon NSID.
	 */
	public function test_get_type() {
		$this->assertSame( 'pub.leaflet.content', $this->parser->get_type() );
	}

	/**
	 * Applies only to block-editor posts.
	 */
	public function test_applies_to_block_posts_only() {
		$blocks  = self::factory()->post->create_and_get(
			array( 'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' )
		);
		$classic = self::factory()->post->create_and_get(
			array( 'post_content' => 'Just classic text.' )
		);

		$this->assertTrue( $this->parser->applies_to( $blocks ) );
		$this->assertFalse( $this->parser->applies_to( $classic ) );
	}

	/**
	 * Core blocks map into a single linearDocument page with wrapped blocks.
	 */
	public function test_parse_maps_core_blocks() {
		$post   = self::factory()->post->create_and_get(
			array( 'post_content' => $this->rich_block_content() )
		);
		$record = $this->parser->parse( $post->post_content, $post );

		$this->assertSame( 'pub.leaflet.content', $record['$type'] );
		$this->assertSame( 'pub.leaflet.pages.linearDocument', $record['pages'][0]['$type'] );

		$blocks = $record['pages'][0]['blocks'];
		$types  = \array_map( static fn( $b ) => $b['block']['$type'], $blocks );

		$this->assertContains( 'pub.leaflet.blocks.header', $types );
		$this->assertContains( 'pub.leaflet.blocks.text', $types );
		$this->assertContains( 'pub.leaflet.blocks.unorderedList', $types );
		$this->assertContains( 'pub.leaflet.blocks.blockquote', $types );
		$this->assertContains( 'pub.leaflet.blocks.code', $types );

		// Every block is wrapped in a `block` key (the #block def).
		foreach ( $blocks as $wrapper ) {
			$this->assertArrayHasKey( 'block', $wrapper );
			$this->assertArrayHasKey( '$type', $wrapper['block'] );
		}
	}

	/**
	 * Heading carries the level; list carries text children.
	 */
	public function test_heading_and_list_shape() {
		$post   = self::factory()->post->create_and_get(
			array( 'post_content' => $this->rich_block_content() )
		);
		$blocks = $this->parser->parse( $post->post_content, $post )['pages'][0]['blocks'];

		$header = $this->find_block( $blocks, 'pub.leaflet.blocks.header' );
		$this->assertSame( 2, $header['level'] );
		$this->assertSame( 'Title', $header['plaintext'] );

		$list = $this->find_block( $blocks, 'pub.leaflet.blocks.unorderedList' );
		$this->assertSame( 'First', $list['children'][0]['content']['plaintext'] );
		$this->assertSame( 'pub.leaflet.blocks.text', $list['children'][0]['content']['$type'] );
	}

	/**
	 * An image maps with its blob, aspect ratio, and alt text.
	 */
	public function test_image_block_carries_blob_and_aspect_ratio() {
		$attachment_id = $this->make_image_attachment( 'A photo', 1600, 900 );
		$post          = self::factory()->post->create_and_get(
			array( 'post_content' => $this->image_block( $attachment_id ) )
		);

		$blocks = $this->parser->parse( $post->post_content, $post )['pages'][0]['blocks'];
		$image  = $this->find_block( $blocks, 'pub.leaflet.blocks.image' );

		$this->assertSame( 'bafyfakecid', $image['image']['ref']['$link'] );
		$this->assertSame(
			array(
				'width'  => 1600,
				'height' => 900,
			),
			$image['aspectRatio']
		);
		$this->assertSame( 'A photo', $image['alt'] );
	}

	/**
	 * A post with no mappable blocks yields null.
	 */
	public function test_parse_returns_null_without_mappable_blocks() {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<!-- wp:spacer --><div class="wp-block-spacer"></div><!-- /wp:spacer -->' )
		);

		$this->assertNull( $this->parser->parse( $post->post_content, $post ) );
	}

	/**
	 * Find the first block of a given type in a wrapped block list.
	 *
	 * @param array  $blocks Wrapped blocks.
	 * @param string $type   Block NSID.
	 * @return array|null
	 */
	private function find_block( array $blocks, string $type ): ?array {
		foreach ( $blocks as $wrapper ) {
			if ( $type === $wrapper['block']['$type'] ) {
				return $wrapper['block'];
			}
		}

		return null;
	}
}
