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

		$this->assertSame( '## My Heading', $result['text']['markdown'] );
	}

	/**
	 * Test heading level 3.
	 */
	public function test_converts_heading_level_3() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:heading {"level":3} --><h3>Sub Heading</h3><!-- /wp:heading -->';
		$result  = $this->parser->parse( $content, $post );

		$this->assertSame( '### Sub Heading', $result['text']['markdown'] );
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

		$this->assertSame( "```\necho \"hello\";\n```", $result['text']['markdown'] );
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

		$this->assertSame( "Before\n\n---\n\nAfter", $result['text']['markdown'] );
	}

	/**
	 * Test empty content returns null so Document can omit content.
	 */
	public function test_empty_content() {
		$post = self::factory()->post->create_and_get();

		$this->assertNull( $this->parser->parse( '', $post ) );
	}

	/**
	 * Test the atmosphere_html_to_markdown filter.
	 *
	 * Verifies the filter callback receives ($markdown, $content) so
	 * callers can inspect the raw source alongside the conversion.
	 */
	public function test_html_to_markdown_filter() {
		$received = array();

		\add_filter(
			'atmosphere_html_to_markdown',
			static function ( $markdown, $content ) use ( &$received ) {
				$received = array(
					'markdown' => $markdown,
					'content'  => $content,
				);
				return 'custom markdown';
			},
			10,
			2
		);

		$post   = self::factory()->post->create_and_get();
		$source = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
		$result = $this->parser->parse( $source, $post );

		$this->assertSame( 'custom markdown', $result['text']['markdown'] );
		$this->assertSame( 'Hello', $received['markdown'] );
		$this->assertSame( $source, $received['content'] );

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

	/**
	 * Test that sibling content after </figcaption> inside the same
	 * <figure> does not bleed into the extracted caption text.
	 */
	public function test_image_caption_does_not_include_sibling_content() {
		$post    = self::factory()->post->create_and_get();
		$content = "<!-- wp:image -->\n"
			. '<figure class="wp-block-image">'
			. '<img src="https://example.com/photo.jpg" alt="A photo" />'
			. '<figcaption>Real caption</figcaption>'
			. '<p>Should not appear in caption</p>'
			. '</figure>'
			. "\n<!-- /wp:image -->";

		$result = $this->parser->parse( $content, $post );
		$md     = $result['text']['markdown'];

		$this->assertStringContainsString( 'Real caption', $md );
		$this->assertStringNotContainsString( 'Should not appear in caption', $md );
	}

	/**
	 * Test that a post made up entirely of blocks that produce no
	 * markdown (e.g. core/spacer) returns null so Document can omit
	 * the content field.
	 */
	public function test_parse_returns_null_when_markdown_is_empty() {
		$post    = self::factory()->post->create_and_get();
		$content = "<!-- wp:spacer {\"height\":\"20px\"} -->\n"
			. '<div style="height:20px" aria-hidden="true" class="wp-block-spacer"></div>' . "\n"
			. '<!-- /wp:spacer -->';

		$this->assertNull( $this->parser->parse( $content, $post ) );
	}

	/**
	 * Test ordered list produces numbered markdown.
	 */
	public function test_listing_ordered() {
		$post    = self::factory()->post->create_and_get();
		$content = "<!-- wp:list {\"ordered\":true} -->\n<ol>"
			. '<!-- wp:list-item --><li>First</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Second</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Third</li><!-- /wp:list-item -->'
			. "</ol>\n<!-- /wp:list -->";

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( "1. First\n2. Second\n3. Third", $result['text']['markdown'] );
	}

	/**
	 * Test unordered list produces dashed markdown.
	 */
	public function test_listing_unordered() {
		$post    = self::factory()->post->create_and_get();
		$content = "<!-- wp:list -->\n<ul>"
			. '<!-- wp:list-item --><li>First</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Second</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Third</li><!-- /wp:list-item -->'
			. "</ul>\n<!-- /wp:list -->";

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( "- First\n- Second\n- Third", $result['text']['markdown'] );
	}

	/**
	 * Test ordered list skips empty items without gapping the counter.
	 */
	public function test_listing_skips_empty_items_without_gap() {
		$post    = self::factory()->post->create_and_get();
		$content = "<!-- wp:list {\"ordered\":true} -->\n<ol>"
			. '<!-- wp:list-item --><li>First</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>   </li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Third</li><!-- /wp:list-item -->'
			. "</ol>\n<!-- /wp:list -->";

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( "1. First\n2. Third", $result['text']['markdown'] );
	}

	/**
	 * Test list items preserve inline formatting.
	 */
	public function test_listing_preserves_inline_formatting() {
		$post    = self::factory()->post->create_and_get();
		$content = "<!-- wp:list -->\n<ul>"
			. '<!-- wp:list-item --><li>some <strong>bold</strong></li><!-- /wp:list-item -->'
			. "</ul>\n<!-- /wp:list -->";

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( '- some **bold**', $result['text']['markdown'] );
	}

	/**
	 * Test quote block wraps an inner paragraph in a "> " prefix.
	 */
	public function test_quote_with_inner_paragraph() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:quote --><blockquote class="wp-block-quote">'
			. '<!-- wp:paragraph --><p>Paragraph text</p><!-- /wp:paragraph -->'
			. '</blockquote><!-- /wp:quote -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( '> Paragraph text', $result['text']['markdown'] );
	}

	/**
	 * Test quote block prefixes every inner line.
	 */
	public function test_quote_prefixes_every_line() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:quote --><blockquote class="wp-block-quote">'
			. '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->'
			. '</blockquote><!-- /wp:quote -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( "> First\n> Second", $result['text']['markdown'] );
	}

	/**
	 * Test quote falls back to innerHTML when no innerBlocks are present.
	 */
	public function test_quote_innerhtml_fallback() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:quote --><blockquote class="wp-block-quote">Direct quote text</blockquote><!-- /wp:quote -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( '> Direct quote text', $result['text']['markdown'] );
	}

	/**
	 * Test core/group containers flatten inner block markdown.
	 */
	public function test_container_group() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Inside group</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( 'Inside group', $result['text']['markdown'] );
	}

	/**
	 * Test core/columns containers flatten inner block markdown.
	 */
	public function test_container_columns() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:paragraph --><p>Inside columns</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:columns -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( 'Inside columns', $result['text']['markdown'] );
	}

	/**
	 * Test core/column containers flatten inner block markdown.
	 */
	public function test_container_column() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:paragraph --><p>Inside column</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:column -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( 'Inside column', $result['text']['markdown'] );
	}

	/**
	 * Test fallback delegates to container() when innerBlocks exist.
	 */
	public function test_fallback_delegates_to_container_with_inner_blocks() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:custom/unknown --><div>'
			. '<!-- wp:paragraph --><p>Inside unknown</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:custom/unknown -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( 'Inside unknown', $result['text']['markdown'] );
	}

	/**
	 * Test image() skips blocks without an <img> tag so surrounding
	 * content renders with no empty separator.
	 *
	 * Uses a mixed fixture so a regression returning "" instead of null
	 * would produce a leading blank line and fail this exact-match
	 * assertion (the whole-post empty guard in parse() would otherwise
	 * mask the handler bug).
	 */
	public function test_image_without_img_tag_is_skipped_cleanly() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:image --><figure class="wp-block-image">'
			. '<figcaption>Just a caption</figcaption>'
			. "</figure><!-- /wp:image -->\n\n"
			. '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( 'After', $result['text']['markdown'] );
	}

	/**
	 * Test heading defaults to level 2 when attrs.level is missing.
	 */
	public function test_heading_defaults_to_level_2() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:heading --><h2>Default level</h2><!-- /wp:heading -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( '## Default level', $result['text']['markdown'] );
	}

	/**
	 * Test whitespace-only heading block is skipped cleanly.
	 *
	 * Mixed with a non-empty sibling so a regression returning "" from
	 * heading() would produce a leading blank line and fail the exact
	 * assertion (the whole-post empty guard would otherwise hide it).
	 */
	public function test_heading_whitespace_is_skipped_cleanly() {
		$post    = self::factory()->post->create_and_get();
		$content = "<!-- wp:heading --><h2>   </h2><!-- /wp:heading -->\n\n"
			. '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( 'After', $result['text']['markdown'] );
	}

	/**
	 * Test whitespace-only paragraph block is skipped cleanly.
	 *
	 * Mixed with a non-empty sibling so a regression returning "" from
	 * paragraph() would produce a leading blank line and fail the exact
	 * assertion (the whole-post empty guard would otherwise hide it).
	 */
	public function test_paragraph_whitespace_is_skipped_cleanly() {
		$post    = self::factory()->post->create_and_get();
		$content = "<!-- wp:paragraph --><p>   </p><!-- /wp:paragraph -->\n\n"
			. '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( 'After', $result['text']['markdown'] );
	}

	/**
	 * Test code block emits the configured language in the fence.
	 */
	public function test_code_emits_language_fence() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:code {"language":"php"} --><pre class="wp-block-code"><code>echo 1;</code></pre><!-- /wp:code -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertStringStartsWith( "```php\n", $result['text']['markdown'] );
	}

	/**
	 * Test code block decodes HTML entities inside the fence.
	 */
	public function test_code_decodes_html_entities() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:code --><pre class="wp-block-code"><code>&lt;div&gt;</code></pre><!-- /wp:code -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( "```\n<div>\n```", $result['text']['markdown'] );
	}

	/**
	 * Test link URLs have parentheses percent-encoded to protect markdown syntax.
	 */
	public function test_link_url_parens_percent_encoded() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:paragraph --><p>See <a href="https://en.wikipedia.org/wiki/Foo_(bar)">Foo</a>.</p><!-- /wp:paragraph -->';

		$result = $this->parser->parse( $content, $post );
		$md     = $result['text']['markdown'];

		$this->assertStringContainsString( '%28bar%29', $md );
		$this->assertStringNotContainsString( '(bar)', $md );
	}

	/**
	 * Test <br> converts to a markdown hard break (two spaces + newline).
	 */
	public function test_br_converts_to_hard_break() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:paragraph --><p>line1<br>line2</p><!-- /wp:paragraph -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertStringContainsString( "line1  \nline2", $result['text']['markdown'] );
	}

	/**
	 * Test HTML entities are decoded in inline paragraph text.
	 */
	public function test_inline_html_entities_decoded() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:paragraph --><p>AT&amp;T&#8217;s</p><!-- /wp:paragraph -->';

		$result = $this->parser->parse( $content, $post );
		$md     = $result['text']['markdown'];

		$this->assertStringContainsString( 'AT&T', $md );
		$this->assertStringContainsString( "\xE2\x80\x99", $md );
	}

	/**
	 * Test inline <img> inside a paragraph converts via inline_html_to_markdown.
	 */
	public function test_inline_image_inside_paragraph() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:paragraph --><p>Look <img src="x.jpg" alt="x"> here</p><!-- /wp:paragraph -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( 'Look ![x](x.jpg) here', $result['text']['markdown'] );
	}

	/**
	 * Test nested inline formatting (bold wrapping italic).
	 */
	public function test_nested_inline_formatting() {
		$post    = self::factory()->post->create_and_get();
		$content = '<!-- wp:paragraph --><p><strong>bold <em>italic</em></strong></p><!-- /wp:paragraph -->';

		$result = $this->parser->parse( $content, $post );

		$this->assertSame( '**bold *italic***', $result['text']['markdown'] );
	}
}
