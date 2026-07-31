<?php
/**
 * Tests for the AT Protocol record preview resolver.
 *
 * Covers the `?atproto` selectors (`site.standard.document`,
 * `app.bsky.feed.post`, `site.standard.publication`, `all`, and unknown
 * types) and the `atmosphere_atproto_preview_transformers` extension
 * filter.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

use WP_UnitTestCase;
use Atmosphere\Transformer\Base;
use Atmosphere\Transformer\Preview;
use Atmosphere\Transformer\Threadgate;

/**
 * Preview resolver tests.
 */
class Test_Preview extends WP_UnitTestCase {

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		\update_option(
			'atmosphere_connection',
			array(
				'access_token' => 'encrypted-token',
				'did'          => 'did:plc:test123',
				'pds_endpoint' => 'https://pds.example.com',
			)
		);
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_publication_tid' );
		\remove_all_filters( 'atmosphere_atproto_preview_transformers' );

		parent::tear_down();
	}

	/**
	 * Create a published post for the preview tests.
	 *
	 * @param array $overrides Post field overrides.
	 * @return \WP_Post
	 */
	private function make_post( array $overrides = array() ): \WP_Post {
		return self::factory()->post->create_and_get(
			\array_merge(
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Preview post',
					'post_content' => 'Preview body.',
				),
				$overrides
			)
		);
	}

	/**
	 * Build a stub transformer for the extension-filter tests.
	 *
	 * @param string $type   Lexicon NSID the transformer owns.
	 * @param array  $record Record the transformer should emit.
	 * @return Base Anonymous transformer.
	 */
	private function make_transformer( string $type, array $record ): Base {
		return new class( $type, $record ) extends Base {

			/**
			 * Lexicon NSID.
			 *
			 * @var string
			 */
			private string $type;

			/**
			 * Record to emit.
			 *
			 * @var array
			 */
			private array $record;

			/**
			 * Constructor.
			 *
			 * @param string $type   Lexicon NSID.
			 * @param array  $record Record to emit.
			 */
			public function __construct( string $type, array $record ) {
				parent::__construct( null );
				$this->type   = $type;
				$this->record = $record;
			}

			/**
			 * {@inheritDoc}
			 */
			public function transform(): array {
				return $this->record;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_collection(): string {
				return $this->type;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_rkey(): string {
				return 'self';
			}
		};
	}

	/**
	 * Bare `?atproto` keeps returning the standard.site document record.
	 */
	public function test_default_returns_document_record() {
		$post    = $this->make_post();
		$payload = Preview::for_post( $post );

		$this->assertIsArray( $payload );
		$this->assertSame( 'site.standard.document', $payload['$type'] );
	}

	/**
	 * The document `$type` is accepted explicitly too.
	 */
	public function test_accepts_document_type() {
		$post    = $this->make_post();
		$payload = Preview::for_post( $post, 'site.standard.document' );

		$this->assertIsArray( $payload );
		$this->assertSame( 'site.standard.document', $payload['$type'] );
	}

	/**
	 * The post preview keeps publication out of post-scoped selectors.
	 */
	public function test_rejects_publication_type_for_post() {
		$post    = $this->make_post();
		$payload = Preview::for_post( $post, 'site.standard.publication' );

		$this->assertTrue( \is_wp_error( $payload ) );
		$this->assertSame( 'atmosphere_atproto_preview_type', $payload->get_error_code() );
	}

	/**
	 * A third-party transformer extends the post preview by its NSID.
	 */
	public function test_accepts_filtered_transformer() {
		\add_filter(
			'atmosphere_atproto_preview_transformers',
			function ( array $transformers, ?\WP_Post $post ): array {
				if ( $post instanceof \WP_Post ) {
					$transformers[] = $this->make_transformer(
						'com.example.postPreview',
						array(
							'$type'  => 'com.example.postPreview',
							'postId' => $post->ID,
						)
					);
				}

				return $transformers;
			},
			10,
			2
		);

		$post    = $this->make_post();
		$payload = Preview::for_post( $post, 'com.example.postPreview' );

		$this->assertIsArray( $payload );
		$this->assertSame( 'com.example.postPreview', $payload['$type'] );
		$this->assertSame( $post->ID, $payload['postId'] );

		$all = Preview::for_post( $post, 'all' );

		$this->assertIsArray( $all );
		$this->assertArrayHasKey( 'com.example.postPreview', $all );
		$this->assertSame( 'com.example.postPreview', $all['com.example.postPreview'][0]['$type'] );

		// The third-party transformer must not displace the built-ins.
		$this->assertArrayHasKey( 'site.standard.document', $all );
		$this->assertArrayHasKey( 'app.bsky.feed.post', $all );
	}

	/**
	 * Bare `?atproto` on the front page previews the publication record.
	 */
	public function test_publication_default_returns_publication_record() {
		$payload = Preview::for_site();

		$this->assertIsArray( $payload );
		$this->assertSame( 'site.standard.publication', $payload['$type'] );
	}

	/**
	 * The publication `$type` previews the site-level publication record.
	 */
	public function test_publication_accepts_publication_type() {
		$payload = Preview::for_site( 'site.standard.publication' );

		$this->assertIsArray( $payload );
		$this->assertSame( 'site.standard.publication', $payload['$type'] );
	}

	/**
	 * A third-party transformer extends the front-page preview by its NSID.
	 */
	public function test_publication_accepts_filtered_transformer() {
		\add_filter(
			'atmosphere_atproto_preview_transformers',
			function ( array $transformers, ?\WP_Post $post ): array {
				if ( null === $post ) {
					$transformers[] = $this->make_transformer(
						'com.example.sitePreview',
						array(
							'$type' => 'com.example.sitePreview',
							'name'  => 'Example site preview',
						)
					);
				}

				return $transformers;
			},
			10,
			2
		);

		$payload = Preview::for_site( 'com.example.sitePreview' );

		$this->assertIsArray( $payload );
		$this->assertSame( 'com.example.sitePreview', $payload['$type'] );
		$this->assertSame( 'Example site preview', $payload['name'] );
	}

	/**
	 * Post-scoped selectors do not apply to the front-page publication preview.
	 */
	public function test_publication_rejects_post_type() {
		$payload = Preview::for_site( 'site.standard.document' );

		$this->assertTrue( \is_wp_error( $payload ) );
		$this->assertSame( 'atmosphere_atproto_preview_type', $payload->get_error_code() );
	}

	/**
	 * The Bluesky `$type` previews the bsky record family for the post.
	 */
	public function test_accepts_bsky_post_type() {
		$post    = $this->make_post(
			array(
				'post_title'   => '',
				'post_content' => 'Short native Bluesky text.',
			)
		);
		$payload = Preview::for_post( $post, 'app.bsky.feed.post' );

		$this->assertIsArray( $payload );
		$this->assertSame( 'app.bsky.feed.post', $payload['$type'] );
	}

	/**
	 * `all` is a post-scoped envelope keyed by real lexicon `$type` values.
	 */
	public function test_all_returns_post_records_keyed_by_type() {
		$post    = $this->make_post();
		$payload = Preview::for_post( $post, 'all' );

		$this->assertIsArray( $payload );
		$this->assertArrayNotHasKey( 'site.standard.publication', $payload );
		$this->assertArrayHasKey( 'site.standard.document', $payload );
		$this->assertArrayHasKey( 'app.bsky.feed.post', $payload );
		$this->assertSame( 'site.standard.document', $payload['site.standard.document'][0]['$type'] );
		$this->assertSame( 'app.bsky.feed.post', $payload['app.bsky.feed.post'][0]['$type'] );
	}

	/**
	 * A gated post exposes its threadgate in the preview set.
	 */
	public function test_includes_threadgate_when_restricted() {
		$post = $this->make_post();
		\update_post_meta(
			$post->ID,
			Threadgate::META_RESTRICTION,
			array( Threadgate::AUDIENCE_FOLLOWING )
		);

		$all = Preview::for_post( $post, 'all' );
		$this->assertIsArray( $all );
		$this->assertArrayHasKey( 'app.bsky.feed.threadgate', $all );

		$record = Preview::for_post( $post, 'app.bsky.feed.threadgate' );
		$this->assertIsArray( $record );
		$this->assertSame( 'app.bsky.feed.threadgate', $record['$type'] );
		$this->assertSame(
			array( array( '$type' => 'app.bsky.feed.threadgate#followingRule' ) ),
			$record['allow']
		);
	}

	/**
	 * An ungated post publishes no threadgate, so the preview omits it —
	 * otherwise it would read as an empty `allow` ("nobody can reply").
	 */
	public function test_omits_threadgate_when_everybody() {
		$post = $this->make_post();

		$all = Preview::for_post( $post, 'all' );
		$this->assertIsArray( $all );
		$this->assertArrayNotHasKey( 'app.bsky.feed.threadgate', $all );

		$record = Preview::for_post( $post, 'app.bsky.feed.threadgate' );
		$this->assertTrue( \is_wp_error( $record ) );
		$this->assertSame( 'atmosphere_atproto_preview_type', $record->get_error_code() );
	}

	/**
	 * Unknown selectors fail clearly instead of silently falling back.
	 */
	public function test_rejects_unknown_type() {
		$post    = $this->make_post();
		$payload = Preview::for_post( $post, 'com.example.unknown' );

		$this->assertTrue( \is_wp_error( $payload ) );
		$this->assertSame( 'atmosphere_atproto_preview_type', $payload->get_error_code() );
	}

	/**
	 * A filter that forgets to return an array falls back to the built-ins
	 * instead of breaking every selector.
	 */
	public function test_non_array_filter_falls_back_to_builtins() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\Transformer\Preview::transformers' );

		\add_filter( 'atmosphere_atproto_preview_transformers', '__return_null' );

		$post    = $this->make_post();
		$payload = Preview::for_post( $post );

		$this->assertIsArray( $payload );
		$this->assertSame( 'site.standard.document', $payload['$type'] );
	}

	/*
	 * -----------------------------------------------------------------
	 * Capability policy — current_user_can_preview().
	 * -----------------------------------------------------------------
	 */

	/**
	 * Post previews are gated per object, not by the generic `edit_posts`
	 * capability: an author must not read another author's projected
	 * record — it carries the saved custom Bluesky text, which that role
	 * cannot otherwise see. Mirrors the REST pre-publish controller's
	 * `edit_post` check.
	 */
	public function test_author_cannot_preview_other_authors_post() {
		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );
		$post     = $this->make_post( array( 'post_author' => $author_a ) );

		\wp_set_current_user( $author_b );

		$this->assertFalse( Preview::current_user_can_preview( $post ) );
	}

	/**
	 * An author previews their own post.
	 */
	public function test_author_can_preview_own_post() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post   = $this->make_post( array( 'post_author' => $author ) );

		\wp_set_current_user( $author );

		$this->assertTrue( Preview::current_user_can_preview( $post ) );
	}

	/**
	 * An editor previews any author's post.
	 */
	public function test_editor_can_preview_other_authors_post() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post   = $this->make_post( array( 'post_author' => $author ) );

		\wp_set_current_user( $editor );

		$this->assertTrue( Preview::current_user_can_preview( $post ) );
	}

	/**
	 * The front-page publication preview has no post to scope to and
	 * keeps the `edit_posts` floor.
	 */
	public function test_front_page_preview_requires_edit_posts() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( Preview::current_user_can_preview( null ) );

		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertTrue( Preview::current_user_can_preview( null ) );
	}

	/**
	 * Logged-out visitors never preview.
	 */
	public function test_logged_out_user_cannot_preview() {
		\wp_set_current_user( 0 );

		$this->assertFalse( Preview::current_user_can_preview( $this->make_post() ) );
	}

	/**
	 * Filter entries that are not Base instances are ignored.
	 */
	public function test_non_base_entries_are_ignored() {
		\add_filter(
			'atmosphere_atproto_preview_transformers',
			static function ( array $transformers ): array {
				$transformers[] = new \stdClass();
				$transformers[] = 'not-a-transformer';

				return $transformers;
			}
		);

		$post    = $this->make_post();
		$payload = Preview::for_post( $post );

		$this->assertIsArray( $payload );
		$this->assertSame( 'site.standard.document', $payload['$type'] );
	}
}
