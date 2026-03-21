<?php
/**
 * Tests for the Markpub content parser.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group content-parser
 */

namespace Atmosphere\Tests\Content_Parser;

use WP_UnitTestCase;
use Atmosphere\Content_Parser\Markpub;

/**
 * Markpub parser tests.
 */
class Test_Markpub extends WP_UnitTestCase {

	/**
	 * Parser instance.
	 *
	 * @var Markpub
	 */
	private Markpub $parser;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->parser = new Markpub();
	}

	/**
	 * Test get_type returns the markpub NSID.
	 */
	public function test_get_type() {
		$this->assertSame( 'at.markpub.markdown', $this->parser->get_type() );
	}

	/**
	 * Test parse returns correct top-level structure.
	 */
	public function test_parse_returns_correct_structure() {
		$post   = self::factory()->post->create_and_get();
		$result = $this->parser->parse(
			'<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
			$post
		);

		$this->assertArrayHasKey( '$type', $result );
		$this->assertSame( 'at.markpub.markdown', $result['$type'] );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertSame( 'at.markpub.text', $result['text']['$type'] );
		$this->assertArrayHasKey( 'markdown', $result['text'] );
		$this->assertSame( 'gfm', $result['flavor'] );
		$this->assertContains( 'strikethrough', $result['extensions'] );
	}

	/**
	 * Test paragraph conversion.
	 */
	public function test_converts_paragraphs() {
		$post    = self::factory()->post->create_and_get();
		$content = "<!-- wp:paragraph -->\n<p>First paragraph</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Second paragraph</p>\n<!-- /wp:paragraph -->";

		$result   = $this->parser->parse( $content, $post );
		$markdown = $result['text']['markdown'];

		$this->assertStringContainsString( 'First paragraph', $markdown );
		$this->assertStringContainsString( 'Second paragraph', $markdown );
		$this->assertStringNotContainsString( '<p>', $markdown );
	}

	/**
	 * Test heading conversion.
	 */
	public function test_converts_headings() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:heading {"level":2} --><h2>My Heading</h2><!-- /wp:heading -->';
		$result  = $this->parser->parse( $content, $post );

		$this->assertStringContainsString( '## My Heading', $result['text']['markdown'] );
	}

	/**
	 * Test heading level 3.
	 */
	public function test_converts_heading_level_3() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:heading {"level":3} --><h3>Sub Heading</h3><!-- /wp:heading -->';
		$result  = $this->parser->parse( $content, $post );

		$this->assertStringContainsString( '### Sub Heading', $result['text']['markdown'] );
	}

	/**
	 * Test link conversion in a paragraph.
	 */
	public function test_converts_links() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:paragraph --><p>Visit <a href="https://example.com">Example</a> today.</p><!-- /wp:paragraph -->';
		$result  = $this->parser->parse( $content, $post );

		$this->assertStringContainsString( '[Example](https://example.com)', $result['text']['markdown'] );
	}

	/**
	 * Test bold conversion.
	 */
	public function test_converts_bold() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:paragraph --><p>This is <strong>bold</strong> text.</p><!-- /wp:paragraph -->';
		$result  = $this->parser->parse( $content, $post );

		$this->assertStringContainsString( '**bold**', $result['text']['markdown'] );
	}

	/**
	 * Test italic conversion.
	 */
	public function test_converts_italic() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:paragraph --><p>This is <em>italic</em> text.</p><!-- /wp:paragraph -->';
		$result  = $this->parser->parse( $content, $post );

		$this->assertStringContainsString( '*italic*', $result['text']['markdown'] );
	}

	/**
	 * Test image block conversion.
	 */
	public function test_converts_images() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/photo.jpg" alt="A photo" /></figure><!-- /wp:image -->';
		$result  = $this->parser->parse( $content, $post );

		$this->assertStringContainsString( '![A photo](https://example.com/photo.jpg)', $result['text']['markdown'] );
	}

	/**
	 * Test code block conversion.
	 */
	public function test_converts_code_blocks() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:code --><pre class="wp-block-code"><code>echo "hello";</code></pre><!-- /wp:code -->';
		$result  = $this->parser->parse( $content, $post );
		$md      = $result['text']['markdown'];

		$this->assertStringContainsString( '```', $md );
		$this->assertStringContainsString( 'echo "hello";', $md );
	}

	/**
	 * Test inline code conversion.
	 */
	public function test_converts_inline_code() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:paragraph --><p>Use the <code>parse()</code> method.</p><!-- /wp:paragraph -->';
		$result  = $this->parser->parse( $content, $post );

		$this->assertStringContainsString( '`parse()`', $result['text']['markdown'] );
	}

	/**
	 * Test separator block becomes horizontal rule.
	 */
	public function test_converts_separator() {
		$post    = self::factory()->post->create_and_get();
		$content = "<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->\n\n"
			. "<!-- wp:separator --><hr class=\"wp-block-separator\"/><!-- /wp:separator -->\n\n"
			. '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->';
		$result  = $this->parser->parse( $content, $post );

		$this->assertStringContainsString( '---', $result['text']['markdown'] );
	}

	/**
	 * Test empty content produces empty markdown.
	 */
	public function test_empty_content() {
		$post   = self::factory()->post->create_and_get();
		$result = $this->parser->parse( '', $post );

		$this->assertSame( '', $result['text']['markdown'] );
	}

	/**
	 * Test the atmosphere_html_to_markdown filter.
	 */
	public function test_html_to_markdown_filter() {
		\add_filter(
			'atmosphere_html_to_markdown',
			static fn() => 'custom markdown',
			10,
			2
		);

		$post   = self::factory()->post->create_and_get();
		$result = $this->parser->parse(
			'<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			$post
		);

		$this->assertSame( 'custom markdown', $result['text']['markdown'] );

		\remove_all_filters( 'atmosphere_html_to_markdown' );
	}

	/**
	 * Test strikethrough conversion.
	 */
	public function test_converts_strikethrough() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:paragraph --><p>This is <del>deleted</del> text.</p><!-- /wp:paragraph -->';
		$result  = $this->parser->parse( $content, $post );

		$this->assertStringContainsString( '~~deleted~~', $result['text']['markdown'] );
	}

	/**
	 * Test classic (non-block) content is handled as fallback.
	 */
	public function test_classic_content_fallback() {
		$post   = self::factory()->post->create_and_get();
		$result = $this->parser->parse( '<p>Classic editor content with <strong>bold</strong>.</p>', $post );
		$md     = $result['text']['markdown'];

		$this->assertStringContainsString( '**bold**', $md );
		$this->assertStringContainsString( 'Classic editor content', $md );
	}
}
