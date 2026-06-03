<?php
/**
 * Tests for the blog.pckt.content parser.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group content-parser
 */

namespace Atmosphere\Tests\Content_Parser;

require_once __DIR__ . '/trait-block-fixtures.php';

use WP_UnitTestCase;
use Atmosphere\Content_Parser\Pckt;

/**
 * Pckt parser tests.
 */
class Test_Pckt extends WP_UnitTestCase {

	use Block_Fixtures;

	/**
	 * Parser instance.
	 *
	 * @var Pckt
	 */
	private Pckt $parser;

	/**
	 * Set up fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->parser = new Pckt();
	}

	/**
	 * Reports the lexicon NSID.
	 */
	public function test_get_type() {
		$this->assertSame( 'blog.pckt.content', $this->parser->get_type() );
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
	 * Core blocks map into the inline items array.
	 */
	public function test_parse_maps_core_blocks_inline() {
		$post   = self::factory()->post->create_and_get(
			array( 'post_content' => $this->rich_block_content() )
		);
		$record = $this->parser->parse( $post->post_content, $post );

		$this->assertSame( 'blog.pckt.content', $record['$type'] );
		$this->assertArrayHasKey( 'items', $record );
		$this->assertArrayNotHasKey( 'blob', $record );

		$types = \array_map( static fn( $b ) => $b['$type'], $record['items'] );

		$this->assertContains( 'blog.pckt.block.heading', $types );
		$this->assertContains( 'blog.pckt.block.text', $types );
		$this->assertContains( 'blog.pckt.block.bulletList', $types );
		$this->assertContains( 'blog.pckt.block.blockquote', $types );
		$this->assertContains( 'blog.pckt.block.codeBlock', $types );
	}

	/**
	 * List items nest text blocks inside listItem wrappers.
	 */
	public function test_list_nests_text_in_list_items() {
		$post  = self::factory()->post->create_and_get(
			array( 'post_content' => $this->rich_block_content() )
		);
		$list  = $this->find_item( $this->parser->parse( $post->post_content, $post )['items'], 'blog.pckt.block.bulletList' );
		$first = $list['content'][0];

		$this->assertSame( 'blog.pckt.block.listItem', $first['$type'] );
		$this->assertSame( 'blog.pckt.block.text', $first['content'][0]['$type'] );
		$this->assertSame( 'First', $first['content'][0]['plaintext'] );
	}

	/**
	 * Blockquote wraps a text block in its content array.
	 */
	public function test_blockquote_wraps_text() {
		$post  = self::factory()->post->create_and_get(
			array( 'post_content' => $this->rich_block_content() )
		);
		$quote = $this->find_item( $this->parser->parse( $post->post_content, $post )['items'], 'blog.pckt.block.blockquote' );

		$this->assertSame( 'blog.pckt.block.text', $quote['content'][0]['$type'] );
		$this->assertSame( 'A quote.', $quote['content'][0]['plaintext'] );
	}

	/**
	 * An image maps via attrs with a blob: src and aspect ratio.
	 */
	public function test_image_block_attrs() {
		$attachment_id = $this->make_image_attachment( 'A photo', 1600, 900 );
		$post          = self::factory()->post->create_and_get(
			array( 'post_content' => $this->image_block( $attachment_id ) )
		);

		$image = $this->find_item( $this->parser->parse( $post->post_content, $post )['items'], 'blog.pckt.block.image' );

		$this->assertSame( 'blob:bafyfakecid', $image['attrs']['src'] );
		$this->assertSame( 'bafyfakecid', $image['attrs']['blob']['ref']['$link'] );
		$this->assertSame( 'A photo', $image['attrs']['alt'] );
		$this->assertSame(
			array(
				'width'  => 1600,
				'height' => 900,
			),
			$image['attrs']['aspectRatio']
		);
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
	 * Find the first item of a given type.
	 *
	 * @param array  $items Inline items.
	 * @param string $type  Block NSID.
	 * @return array|null
	 */
	private function find_item( array $items, string $type ): ?array {
		foreach ( $items as $item ) {
			if ( $type === $item['$type'] ) {
				return $item;
			}
		}

		return null;
	}
}
