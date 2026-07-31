<?php
/**
 * Tests for the Jetpack paid-content integration.
 *
 * Regression coverage for the leak where subscriber-only / paywalled post
 * bodies were serialized in full into public AT Protocol records.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group integrations
 */

namespace Atmosphere\Tests\Integrations;

use Atmosphere\Atmosphere;
use Atmosphere\Content_Parser\Parser_Base;
use Atmosphere\Content_Parser\Registry;
use Atmosphere\Integrations\Jetpack;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;

use function Atmosphere\get_publishable_content;

/**
 * Jetpack gating integration tests.
 */
class Test_Jetpack extends \WP_UnitTestCase {

	/**
	 * Serialized content with a split-point paywall block.
	 *
	 * @var string
	 */
	private const SPLIT_CONTENT = "<!-- wp:paragraph --><p>Public intro paragraph.</p><!-- /wp:paragraph -->\n<!-- wp:jetpack/paywall /-->\n<!-- wp:paragraph --><p>Secret paywalled body.</p><!-- /wp:paragraph -->";

	/**
	 * Serialized content with an inline premium-content region.
	 *
	 * @var string
	 */
	private const INLINE_CONTENT = "<!-- wp:paragraph --><p>Public before region.</p><!-- /wp:paragraph -->\n<!-- wp:premium-content/container -->\n<!-- wp:premium-content/logged-out-view --><!-- wp:paragraph --><p>Logged out teaser.</p><!-- /wp:paragraph --><!-- /wp:premium-content/logged-out-view -->\n<!-- wp:premium-content/subscriber-view --><!-- wp:paragraph --><p>Secret subscriber body.</p><!-- /wp:paragraph --><!-- /wp:premium-content/subscriber-view -->\n<!-- /wp:premium-content/container -->\n<!-- wp:paragraph --><p>Public after region.</p><!-- /wp:paragraph -->";

	/**
	 * Start each test with the integration registered and a deterministic
	 * parser registry.
	 */
	public function set_up(): void {
		parent::set_up();
		Parser_Base::flush_block_cache();
		Registry::reset();
		Atmosphere::register_default_content_parsers();
		Jetpack::init();
	}

	/**
	 * Restore global state.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_publishable_content' );
		Registry::reset();
		Parser_Base::flush_block_cache();
		Atmosphere::register_default_content_parsers();
		parent::tear_down();
	}

	/**
	 * A post gated to a non-public level with a simple body yields a WP_Post
	 * whose publishable content is empty.
	 *
	 * @param string $level   Access level meta value.
	 * @param string $content Post content.
	 * @return \WP_Post
	 */
	private function gated_post( string $level, string $content = 'Full secret story body.' ): \WP_Post {
		// Empty excerpt so the body-derived excerpt path is exercised; a
		// manually authored excerpt is public teaser text and is preserved.
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => $content,
				'post_excerpt' => '',
			)
		);
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', $level );
		Parser_Base::flush_block_cache();

		return $post;
	}

	// ---------------------------------------------------------------------
	// Unit coverage of the filter itself.
	// ---------------------------------------------------------------------

	/**
	 * An ungated post's content passes through untouched.
	 */
	public function test_public_post_content_unchanged() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => 'A public story.' ) );

		$this->assertSame( 'A public story.', get_publishable_content( $post ) );
	}

	/**
	 * A post gated to 'subscribers' publishes no body.
	 */
	public function test_whole_post_subscribers_yields_empty() {
		$post = $this->gated_post( 'subscribers' );

		$this->assertSame( '', get_publishable_content( $post ) );
	}

	/**
	 * A post gated to 'paid_subscribers' publishes no body.
	 */
	public function test_whole_post_paid_subscribers_yields_empty() {
		$post = $this->gated_post( 'paid_subscribers' );

		$this->assertSame( '', get_publishable_content( $post ) );
	}

	/**
	 * An unrecognised access level fails closed (treated as gated).
	 */
	public function test_unknown_access_level_fails_closed() {
		$post = $this->gated_post( 'some-future-tier' );

		$this->assertSame( '', get_publishable_content( $post ) );
	}

	/**
	 * A split-point post keeps only the content above the paywall marker.
	 */
	public function test_split_point_keeps_only_public_portion() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => self::SPLIT_CONTENT ) );
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'subscribers' );

		$result = get_publishable_content( $post );

		$this->assertStringContainsString( 'Public intro paragraph.', $result );
		$this->assertStringNotContainsString( 'Secret paywalled body.', $result );
		$this->assertStringNotContainsString( 'jetpack/paywall', $result );
	}

	/**
	 * An inline premium-content region is removed, the rest preserved.
	 */
	public function test_inline_region_stripped_rest_preserved() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => self::INLINE_CONTENT ) );

		$result = get_publishable_content( $post );

		$this->assertStringNotContainsString( 'Secret subscriber body.', $result );
		$this->assertStringContainsString( 'Public before region.', $result );
		$this->assertStringContainsString( 'Public after region.', $result );
		$this->assertStringContainsString( 'Logged out teaser.', $result );
	}

	// ---------------------------------------------------------------------
	// End-to-end coverage through the record transformers.
	// ---------------------------------------------------------------------

	/**
	 * A whole-post-gated post produces a document with no body fields.
	 */
	public function test_document_has_no_body_for_gated_post() {
		$post   = $this->gated_post( 'subscribers' );
		$record = ( new Document( $post ) )->transform();

		$this->assertArrayNotHasKey( 'textContent', $record );
		$this->assertArrayNotHasKey( 'content', $record );
		$this->assertArrayNotHasKey( 'description', $record );
		// The title is public and remains as a discoverable stub.
		$this->assertArrayHasKey( 'title', $record );

		$this->assertStringNotContainsString( 'Full secret story body.', (string) \wp_json_encode( $record ) );
	}

	/**
	 * A whole-post-gated post still shares a Bluesky teaser (title + link),
	 * but never the body.
	 */
	public function test_bluesky_post_is_teaser_only_for_gated_post() {
		$post   = $this->gated_post( 'subscribers' );
		$record = ( new Post( $post ) )->transform();

		$encoded = (string) \wp_json_encode( $record );

		$this->assertStringNotContainsString( 'Full secret story body.', $encoded );
		$this->assertStringContainsString( \get_the_title( $post ), $encoded );
	}

	/**
	 * A manually authored excerpt is public teaser text and is preserved on a
	 * gated post, while the gated body is still withheld.
	 */
	public function test_manual_excerpt_preserved_for_gated_post() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => 'Full secret story body.',
				'post_excerpt' => 'A public teaser the author wrote.',
			)
		);
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'subscribers' );

		$record = ( new Document( $post ) )->transform();

		$this->assertSame( 'A public teaser the author wrote.', $record['description'] ?? '' );
		$this->assertStringNotContainsString( 'Full secret story body.', (string) \wp_json_encode( $record ) );
	}

	/**
	 * A split-point document keeps the public intro, drops the gated body.
	 */
	public function test_document_split_point_keeps_public_drops_gated() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => self::SPLIT_CONTENT ) );
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'subscribers' );
		Parser_Base::flush_block_cache();

		$encoded = (string) \wp_json_encode( ( new Document( $post ) )->transform() );

		$this->assertStringContainsString( 'Public intro paragraph.', $encoded );
		$this->assertStringNotContainsString( 'Secret paywalled body.', $encoded );
	}

	/**
	 * An inline-gated document drops the subscriber region, keeps the rest.
	 */
	public function test_document_inline_drops_subscriber_region() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => self::INLINE_CONTENT ) );
		Parser_Base::flush_block_cache();

		$encoded = (string) \wp_json_encode( ( new Document( $post ) )->transform() );

		$this->assertStringNotContainsString( 'Secret subscriber body.', $encoded );
		$this->assertStringContainsString( 'Public before region.', $encoded );
		$this->assertStringContainsString( 'Public after region.', $encoded );
	}

	// ---------------------------------------------------------------------
	// Edge cases in the block-stripping logic.
	// ---------------------------------------------------------------------

	/**
	 * Assert a fixture strips all secrets, keeps all public text, and yields
	 * content that re-parses to valid blocks.
	 *
	 * @param string   $content Post content.
	 * @param string[] $secrets Strings that must not survive.
	 * @param string[] $kept    Strings that must survive.
	 */
	private function assert_stripped( string $content, array $secrets, array $kept ): void {
		$post = self::factory()->post->create_and_get( array( 'post_content' => $content ) );

		$result = get_publishable_content( $post );

		foreach ( $secrets as $secret ) {
			$this->assertStringNotContainsString( $secret, $result );
		}
		foreach ( $kept as $survivor ) {
			$this->assertStringContainsString( $survivor, $result );
		}

		// The stripped output must round-trip cleanly: re-serializing the
		// parsed blocks reproduces it, proving no innerContent misalignment.
		$this->assertSame( $result, \serialize_blocks( \parse_blocks( $result ) ) );
	}

	/**
	 * Multiple subscriber-view blocks in one container are all removed.
	 */
	public function test_strips_multiple_subscriber_views() {
		$content = "<!-- wp:premium-content/container -->\n"
			. "<!-- wp:premium-content/subscriber-view --><!-- wp:paragraph --><p>Secret one.</p><!-- /wp:paragraph --><!-- /wp:premium-content/subscriber-view -->\n"
			. "<!-- wp:premium-content/logged-out-view --><!-- wp:paragraph --><p>Public teaser.</p><!-- /wp:paragraph --><!-- /wp:premium-content/logged-out-view -->\n"
			. "<!-- wp:premium-content/subscriber-view --><!-- wp:paragraph --><p>Secret two.</p><!-- /wp:paragraph --><!-- /wp:premium-content/subscriber-view -->\n"
			. '<!-- /wp:premium-content/container -->';

		$this->assert_stripped( $content, array( 'Secret one.', 'Secret two.' ), array( 'Public teaser.' ) );
	}

	/**
	 * A subscriber-view nested inside another block is removed via recursion.
	 */
	public function test_strips_nested_subscriber_view() {
		$content = "<!-- wp:group --><div class=\"wp-block-group\">\n"
			. "<!-- wp:paragraph --><p>Group public text.</p><!-- /wp:paragraph -->\n"
			. "<!-- wp:premium-content/subscriber-view --><!-- wp:paragraph --><p>Nested secret text.</p><!-- /wp:paragraph --><!-- /wp:premium-content/subscriber-view -->\n"
			. '</div><!-- /wp:group -->';

		$this->assert_stripped( $content, array( 'Nested secret text.' ), array( 'Group public text.' ) );
	}

	/**
	 * Two sibling containers each have their subscriber region removed.
	 */
	public function test_strips_sibling_containers() {
		$content = "<!-- wp:premium-content/container -->\n"
			. "<!-- wp:premium-content/subscriber-view --><!-- wp:paragraph --><p>Secret A.</p><!-- /wp:paragraph --><!-- /wp:premium-content/subscriber-view -->\n"
			. "<!-- /wp:premium-content/container -->\n"
			. "<!-- wp:paragraph --><p>Between containers.</p><!-- /wp:paragraph -->\n"
			. "<!-- wp:premium-content/container -->\n"
			. "<!-- wp:premium-content/subscriber-view --><!-- wp:paragraph --><p>Secret B.</p><!-- /wp:paragraph --><!-- /wp:premium-content/subscriber-view -->\n"
			. '<!-- /wp:premium-content/container -->';

		$this->assert_stripped( $content, array( 'Secret A.', 'Secret B.' ), array( 'Between containers.' ) );
	}

	/**
	 * A bare subscriber-view with no container wrapper is still removed.
	 */
	public function test_strips_bare_subscriber_view_without_container() {
		$content = "<!-- wp:paragraph --><p>Public paragraph.</p><!-- /wp:paragraph -->\n"
			. '<!-- wp:premium-content/subscriber-view --><!-- wp:paragraph --><p>Bare secret.</p><!-- /wp:paragraph --><!-- /wp:premium-content/subscriber-view -->';

		$this->assert_stripped( $content, array( 'Bare secret.' ), array( 'Public paragraph.' ) );
	}

	/**
	 * A paywall block carrying attributes is still detected and split on.
	 */
	public function test_split_detects_paywall_block_with_attributes() {
		$content = "<!-- wp:paragraph --><p>Public lede.</p><!-- /wp:paragraph -->\n"
			. "<!-- wp:jetpack/paywall {\"tierId\":123} /-->\n"
			. '<!-- wp:paragraph --><p>Gated remainder.</p><!-- /wp:paragraph -->';

		// No access-level meta set: detection must not depend on it.
		$post = self::factory()->post->create_and_get( array( 'post_content' => $content ) );

		$result = get_publishable_content( $post );

		$this->assertStringContainsString( 'Public lede.', $result );
		$this->assertStringNotContainsString( 'Gated remainder.', $result );
	}
}
