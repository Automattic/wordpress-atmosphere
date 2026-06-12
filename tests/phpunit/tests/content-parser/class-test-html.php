<?php
/**
 * Tests for the WordPress HTML content parser.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group content-parser
 */

namespace Atmosphere\Tests\Content_Parser;

use Atmosphere\Content_Parser\Html;

/**
 * WordPress HTML parser tests.
 */
class Test_Html extends \WP_UnitTestCase {

	/**
	 * Parser instance.
	 *
	 * @var Html
	 */
	private Html $parser;

	/**
	 * Set up fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->parser = new Html();
	}

	/**
	 * Reports the lexicon NSID.
	 */
	public function test_get_type() {
		$this->assertSame( Html::TYPE, $this->parser->get_type() );
	}

	/**
	 * The HTML parser applies to any post.
	 */
	public function test_applies_to_any_post() {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => 'Plain classic content.' )
		);

		$this->assertTrue( $this->parser->applies_to( $post ) );
	}

	/**
	 * Parsing returns the rendered HTML in the spec shape.
	 */
	public function test_parse_returns_rendered_html() {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<!-- wp:paragraph --><p>Hello <strong>world</strong>.</p><!-- /wp:paragraph -->' )
		);

		$record = $this->parser->parse( $post->post_content, $post );

		$this->assertSame( Html::TYPE, $record['$type'] );
		$this->assertArrayHasKey( 'html', $record );
		$this->assertStringContainsString( '<strong>world</strong>', $record['html'] );
	}

	/**
	 * Empty content yields null so the field is omitted.
	 */
	public function test_parse_returns_null_for_empty_content() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => '' ) );

		$this->assertNull( $this->parser->parse( '', $post ) );
	}

	/**
	 * The html field is clamped to the lexicon grapheme limit.
	 */
	public function test_html_clamped_to_grapheme_limit() {
		$long = \str_repeat( 'a', Html::MAX_GRAPHEMES + 500 );
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => $long )
		);

		$record = $this->parser->parse( $post->post_content, $post );

		$count = \function_exists( 'grapheme_strlen' )
			? \grapheme_strlen( $record['html'] )
			: \mb_strlen( $record['html'] );

		$this->assertLessThanOrEqual( Html::MAX_GRAPHEMES, $count );
	}
}
