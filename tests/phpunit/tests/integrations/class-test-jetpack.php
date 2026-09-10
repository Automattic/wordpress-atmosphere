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
use Atmosphere\Integrations\Load;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post;

use function Atmosphere\get_publishable_content;
use function Atmosphere\is_post_gated;
use function Atmosphere\render_publishable_content;

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
		\remove_all_filters( 'atmosphere_is_post_gated' );
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
	 * The integration registers its filter when Jetpack is active.
	 *
	 * Regression guard: Jetpack loads `Jetpack_Memberships` lazily, so keying
	 * registration off that class left the filter unregistered on real sites.
	 * `Load::register()` must hook the filter whenever Jetpack is active
	 * (`JETPACK__VERSION` is defined at plugin load, well before this runs).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_integration_registers_when_jetpack_active() {
		if ( ! \defined( 'JETPACK__VERSION' ) ) {
			\define( 'JETPACK__VERSION', '13.0-test' );
		}
		\remove_all_filters( 'atmosphere_publishable_content' );

		Load::register();

		$this->assertNotFalse( \has_filter( 'atmosphere_publishable_content' ) );
	}

	/**
	 * A paywall block carrying attributes is still detected and split on when
	 * the post is gated.
	 */
	public function test_split_detects_paywall_block_with_attributes() {
		$content = "<!-- wp:paragraph --><p>Public lede.</p><!-- /wp:paragraph -->\n"
			. "<!-- wp:jetpack/paywall {\"tierId\":123} /-->\n"
			. '<!-- wp:paragraph --><p>Gated remainder.</p><!-- /wp:paragraph -->';

		$post = self::factory()->post->create_and_get( array( 'post_content' => $content ) );
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'subscribers' );

		$result = get_publishable_content( $post );

		$this->assertStringContainsString( 'Public lede.', $result );
		$this->assertStringNotContainsString( 'Gated remainder.', $result );
	}

	/**
	 * A paywall block on a public post is inert: Jetpack renders the whole post
	 * to everyone when the access level is empty / everybody, so we must not
	 * truncate at the block. A stray or imported block on a public post would
	 * otherwise silently drop the body to a teaser.
	 */
	public function test_paywall_block_on_public_post_is_not_truncated() {
		$content = "<!-- wp:paragraph --><p>Public lede.</p><!-- /wp:paragraph -->\n"
			. "<!-- wp:jetpack/paywall /-->\n"
			. '<!-- wp:paragraph --><p>Still public remainder.</p><!-- /wp:paragraph -->';

		// No access-level meta: the post is public, so nothing is gated.
		$post = self::factory()->post->create_and_get( array( 'post_content' => $content ) );

		$result = get_publishable_content( $post );

		$this->assertStringContainsString( 'Public lede.', $result );
		$this->assertStringContainsString( 'Still public remainder.', $result );
	}

	/**
	 * A paywall block nested below the top level cannot be split safely. On a
	 * gated post we fail closed (publish nothing); on a public post the block is
	 * inert and the whole body stays. Regression guard for a nested block
	 * emptying a post that is not gated at all.
	 */
	public function test_nested_paywall_block_gated_fails_closed_public_kept() {
		$content = "<!-- wp:group --><div class=\"wp-block-group\">\n"
			. "<!-- wp:paragraph --><p>Grouped lede.</p><!-- /wp:paragraph -->\n"
			. "<!-- wp:jetpack/paywall /-->\n"
			. "<!-- wp:paragraph --><p>Grouped remainder.</p><!-- /wp:paragraph -->\n"
			. '</div><!-- /wp:group -->';

		$public = self::factory()->post->create_and_get( array( 'post_content' => $content ) );
		$this->assertStringContainsString( 'Grouped remainder.', get_publishable_content( $public ) );

		$gated = self::factory()->post->create_and_get( array( 'post_content' => $content ) );
		\update_post_meta( $gated->ID, '_jetpack_newsletter_access', 'subscribers' );
		$this->assertSame( '', get_publishable_content( $gated ) );
	}

	/**
	 * An inline subscriber-only region is stripped even on a public post: the
	 * premium-content block gates its own region regardless of the post's
	 * whole-post access level.
	 */
	public function test_inline_region_stripped_on_public_post() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => self::INLINE_CONTENT ) );

		$result = get_publishable_content( $post );

		$this->assertStringNotContainsString( 'Secret subscriber body.', $result );
		$this->assertStringContainsString( 'Public before region.', $result );
		$this->assertStringContainsString( 'Public after region.', $result );
	}

	// ---------------------------------------------------------------------
	// Transformer fallbacks and render seam.
	// ---------------------------------------------------------------------

	/**
	 * A whole-post-gated short-form post with a featured image must not ship a
	 * bare, contextless image. Its body is gated away, so the short-form path
	 * falls back to the link-card composition that still carries a link home
	 * instead of publishing the (public) featured image on its own.
	 */
	public function test_gated_titleless_post_with_featured_image_uses_link_card() {
		$thumb = self::factory()->attachment->create();
		$post  = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'Secret body.',
				'post_excerpt' => '',
			)
		);
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'subscribers' );
		\set_post_thumbnail( $post, $thumb );

		// The embed filter reports the composition strategy the record actually
		// used; projection stands in a placeholder blob so build_images_embed()
		// would otherwise return the featured image and hide the fallback.
		$captured = array();
		\add_filter(
			'atmosphere_post_embed',
			static function ( $embed, $post_obj, $strategy ) use ( &$captured ) {
				$captured[] = array(
					'strategy' => $strategy,
					'type'     => \is_array( $embed ) ? ( $embed['$type'] ?? null ) : null,
				);
				return $embed;
			},
			10,
			3
		);

		( new Post( $post ) )->project();

		\remove_all_filters( 'atmosphere_post_embed' );

		$this->assertNotEmpty( $captured );
		$last = \end( $captured );
		$this->assertSame( 'link-card', $last['strategy'] );
		$this->assertNotSame( 'app.bsky.embed.images', $last['type'] );
	}

	/**
	 * The render seam unhooks Jetpack's `the_content` paywall for the duration
	 * of a publishable render and restores it afterwards, so the paywall's
	 * subscribe form never overwrites the already-narrowed body.
	 */
	public function test_render_seam_suspends_and_restores_paywall_filter() {
		$callback = 'Automattic\Jetpack\Extensions\Subscriptions\add_paywall';
		\add_filter( 'the_content', $callback, 8 );
		$post = self::factory()->post->create_and_get();

		$this->assertSame( 8, \has_filter( 'the_content', $callback ) );

		\do_action( 'atmosphere_pre_render_publishable_content', $post );
		$this->assertFalse( \has_filter( 'the_content', $callback ), 'paywall filter suspended during render' );

		\do_action( 'atmosphere_post_render_publishable_content', $post );
		$this->assertSame( 8, \has_filter( 'the_content', $callback ), 'paywall filter restored after render' );

		\remove_filter( 'the_content', $callback, 8 );
	}

	/**
	 * The pre-publish preview gates on the *unsaved* access level so a paid post
	 * in progress previews as a teaser, matching what it would publish, rather
	 * than reading the still-public last save.
	 */
	public function test_preview_projection_reflects_unsaved_access_level() {
		$request = new \WP_REST_Request( 'POST', '/' );
		$request->set_param( 'accessLevel', 'subscribers' );

		$post = self::factory()->post->create_and_get( array( 'post_content' => 'Draft paid body.' ) );
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'everybody' );

		\do_action( 'atmosphere_pre_projection', $post, $request );
		$this->assertSame( '', get_publishable_content( $post ), 'unsaved subscriber level gates the preview' );
		\do_action( 'atmosphere_post_projection', $post );

		// The override is scoped to the projection: an unrelated public post
		// (distinct content, so a distinct cache key) still publishes in full.
		$public = self::factory()->post->create_and_get( array( 'post_content' => 'Saved public body.' ) );
		$this->assertSame( 'Saved public body.', get_publishable_content( $public ) );
	}

	/**
	 * A blank `accessLevel` must NOT override the saved gate. A dispatched
	 * request fills the param with its registered `''` default, so
	 * `has_param( 'accessLevel' )` is true even when the client never sent a
	 * level (an older cached editor, or a post type whose access meta the editor
	 * does not expose). Overriding with that blank value would read as
	 * "everybody" and make a saved paid post preview as public, then publish
	 * only a teaser. Treating blank as "not provided" falls back to the saved
	 * access level and fails closed.
	 */
	public function test_preview_projection_ignores_blank_access_level_override() {
		$request = new \WP_REST_Request( 'POST', '/' );
		// Simulate the dispatched default: the client sent nothing, so the param
		// resolves to its '' default.
		$request->set_param( 'accessLevel', '' );

		$post = self::factory()->post->create_and_get( array( 'post_content' => 'Saved paid body.' ) );
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'subscribers' );

		\do_action( 'atmosphere_pre_projection', $post, $request );
		$this->assertSame( '', get_publishable_content( $post ), 'blank access level falls back to the saved gated meta' );
		\do_action( 'atmosphere_post_projection', $post );
	}

	/**
	 * Gating a post mid-process must not return the cached, more permissive
	 * answer. The publishable-content memo keys on post ID + content hash, but
	 * the output also turns on the stored access level, so the integration folds
	 * that level into the key. Without it, the public body memoized before the
	 * change would be served back after it.
	 */
	public function test_memo_reflects_in_process_access_level_change() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => 'Body that can be gated.' ) );
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'everybody' );

		// Public: the full body federates and is memoized.
		$this->assertSame( 'Body that can be gated.', get_publishable_content( $post ) );

		// Gate the same post in the same process.
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'subscribers' );
		$this->assertSame( '', get_publishable_content( $post ), 'gating mid-process must not return the cached public body' );
	}

	/**
	 * The integration registers on WordPress.com Simple, where the membership
	 * blocks ship via jetpack-mu-wpcom without JETPACK__VERSION ever being
	 * defined. Keying registration off Jetpack's constant alone left gated
	 * posts federating in full there.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_integration_registers_on_wpcom_simple() {
		if ( \defined( 'JETPACK__VERSION' ) || \class_exists( 'Jetpack_Memberships' ) ) {
			$this->markTestSkipped( 'Cannot isolate IS_WPCOM while another Jetpack signal is present.' );
		}

		if ( ! \defined( 'IS_WPCOM' ) ) {
			\define( 'IS_WPCOM', true );
		}
		\remove_all_filters( 'atmosphere_publishable_content' );

		Load::register();

		$this->assertNotFalse( \has_filter( 'atmosphere_publishable_content' ) );
	}

	/**
	 * A gated split post whose only content above the paywall is whitespace is
	 * still fully gated: `blocks_above_paywall()` keeps the leading freeform
	 * block, so the publishable content serialises to whitespace rather than an
	 * exact `''`. `is_body_gated()` must trim before comparing, or a titleless
	 * post with a featured image slips past the link-card fallback and ships as
	 * a bare, contextless image.
	 */
	public function test_gated_split_post_with_whitespace_above_paywall_uses_link_card() {
		$content = "\n<!-- wp:jetpack/paywall /-->\n<!-- wp:paragraph --><p>Secret paywalled body.</p><!-- /wp:paragraph -->";
		$thumb   = self::factory()->attachment->create();
		$post    = self::factory()->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => $content,
				'post_excerpt' => '',
			)
		);
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'subscribers' );
		// Set the thumbnail meta directly: set_post_thumbnail() deletes it when
		// the attachment has no real image file (as factory attachments do), and
		// the featured image must be present for the bare-image path to exist.
		\update_post_meta( $post->ID, '_thumbnail_id', $thumb );

		// The publishable content is whitespace-only, so a strict `'' ===` check
		// would read it as a real, publishable body.
		$this->assertNotSame( '', get_publishable_content( $post ), 'guard: content above the paywall is whitespace, not empty' );
		$this->assertSame( '', \trim( get_publishable_content( $post ) ), 'guard: that whitespace trims to nothing' );

		$captured = array();
		\add_filter(
			'atmosphere_post_embed',
			static function ( $embed, $post_obj, $strategy ) use ( &$captured ) {
				$captured[] = array(
					'strategy' => $strategy,
					'type'     => \is_array( $embed ) ? ( $embed['$type'] ?? null ) : null,
				);
				return $embed;
			},
			10,
			3
		);

		( new Post( $post ) )->project();

		\remove_all_filters( 'atmosphere_post_embed' );

		$this->assertNotEmpty( $captured );
		$last = \end( $captured );
		$this->assertSame( 'link-card', $last['strategy'] );
		$this->assertNotSame( 'app.bsky.embed.images', $last['type'] );
	}

	/**
	 * A preview projection keeps the post's real ID and can leave its content
	 * untouched while flipping only the unsaved access level. The publishable
	 * content memo keys on ID + content hash, so without the access override in
	 * the key the overridden preview would collide with — and return — the
	 * saved post's cached public body, leaking it into the preview and, if a
	 * second consumer ran in the same request, into the record.
	 */
	public function test_preview_override_does_not_collide_with_saved_post_cache() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => 'Shared body.' ) );
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'everybody' );

		// Saved, public: full body federates and is memoized.
		$this->assertSame( 'Shared body.', get_publishable_content( $post ), 'public save federates in full' );

		// Same post, same content, but the editor flips the level to subscribers
		// in an unsaved preview. The override must recompute, not reuse the
		// cached public body under a colliding key.
		$request = new \WP_REST_Request( 'POST', '/' );
		$request->set_param( 'accessLevel', 'subscribers' );

		\do_action( 'atmosphere_pre_projection', $post, $request );
		$this->assertSame( '', get_publishable_content( $post ), 'unsaved subscriber override must not reuse the public cache entry' );
		\do_action( 'atmosphere_post_projection', $post );

		// Override lifted: the saved public value is served again.
		$this->assertSame( 'Shared body.', get_publishable_content( $post ), 'override lifts cleanly after the projection' );
	}

	/**
	 * A fully gated body renders to nothing even when an unconditional
	 * `the_content` appender (sharing buttons, a CTA) adds boilerplate to
	 * every render: none of it may ship as the record body of a gated post,
	 * while a public post keeps the appender output like the front end does.
	 */
	public function test_fully_gated_post_renders_to_nothing_despite_appender() {
		$post     = $this->gated_post( 'subscribers' );
		$appender = static function ( $content ) {
			return $content . '<p>Share this: Facebook Twitter</p>';
		};
		\add_filter( 'the_content', $appender );

		$gated_html  = render_publishable_content( $post );
		$public      = self::factory()->post->create_and_get( array( 'post_content' => 'Public body.' ) );
		$public_html = render_publishable_content( $public );

		\remove_filter( 'the_content', $appender );

		$this->assertSame( '', $gated_html, 'no appender boilerplate may ship for a gated body' );
		$this->assertStringContainsString( 'Share this:', $public_html, 'a public post keeps the appender output' );
	}

	/**
	 * A whole-post access level gates the comment thread even when the body
	 * comparison sees nothing: an empty-content (title + featured image)
	 * subscribers post narrows no bytes but is still subscriber-only.
	 */
	public function test_gated_post_with_empty_content_is_flagged_gated() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => '' ) );
		\update_post_meta( $post->ID, '_jetpack_newsletter_access', 'subscribers' );

		$this->assertTrue( is_post_gated( $post ), 'empty-content subscribers post counts as gated' );

		$public = self::factory()->post->create_and_get( array( 'post_content' => 'Public body.' ) );

		$this->assertFalse( is_post_gated( $public ), 'an ungated post does not' );
	}
}
