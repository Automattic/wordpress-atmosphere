<?php
/**
 * Tests for the reaction sync engine.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group reaction-sync
 */

namespace Atmosphere\Tests;

use WP_UnitTestCase;
use Atmosphere\Reaction_Sync;
use Atmosphere\OAuth\DPoP;
use Atmosphere\OAuth\Encryption;
use Atmosphere\Transformer\Post as BskyPost;

/**
 * Reaction sync tests.
 */
class Test_Reaction_Sync extends WP_UnitTestCase {

	/**
	 * Reset post-type support state leaked by the resolver tests.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_support_post_types' );
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_reaction_sync_pagination' );
		\delete_option( 'atmosphere_reaction_sync_did' );
		\delete_option( '_atmosphere_reaction_sync_lock' );
		\delete_option( 'atmosphere_sync_reactions' );
		\delete_option( 'atmosphere_sync_replies' );
		\remove_all_filters( 'atmosphere_connection_only_mode' );
		\remove_all_filters( 'atmosphere_should_sync_reactions' );
		\remove_all_filters( 'atmosphere_should_sync_replies' );
		\remove_all_filters( 'pre_http_request' );
		\remove_all_filters( 'atmosphere_reply_backfill_batch_size' );
		\delete_transient( 'atmosphere_profile_' . \md5( 'did:plc:mallory' ) );

		if ( \post_type_exists( 'atmos_hidden_cpt' ) ) {
			\unregister_post_type( 'atmos_hidden_cpt' );
		}

		parent::tear_down();
	}

	/**
	 * Test that find_post_by_bsky_uri returns the correct post.
	 */
	public function test_find_post_by_bsky_uri() {
		$post_id = self::factory()->post->create();
		$uri     = 'at://did:plc:test123/app.bsky.feed.post/abc123';

		\update_post_meta( $post_id, BskyPost::META_URI, $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );

		$this->assertSame( $post_id, $method->invoke( null, $uri ) );
	}

	/**
	 * Test that find_post_by_bsky_uri falls back to the thread URI index.
	 */
	public function test_find_post_by_bsky_uri_uses_thread_uri_index() {
		$post_id   = self::factory()->post->create();
		$reply_uri = 'at://did:plc:test123/app.bsky.feed.post/reply123';

		\add_post_meta( $post_id, BskyPost::META_URI_INDEX, $reply_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );

		$this->assertSame( $post_id, $method->invoke( null, $reply_uri ) );
	}

	/**
	 * Password-protected posts should not receive public reaction/reply
	 * write-backs even if stale AT-URI meta remains.
	 */
	public function test_find_post_by_bsky_uri_skips_password_protected_posts() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$uri     = 'at://did:plc:test123/app.bsky.feed.post/protected';

		\update_post_meta( $post_id, BskyPost::META_URI, $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );

		$this->assertFalse( $method->invoke( null, $uri ) );
	}

	/**
	 * Test that find_post_by_bsky_uri returns false for unknown URI.
	 */
	public function test_find_post_by_bsky_uri_not_found() {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );

		$this->assertFalse( $method->invoke( null, 'at://did:plc:unknown/app.bsky.feed.post/xyz' ) );
	}

	/**
	 * A reaction targeting a supported non-`post` post type must resolve.
	 *
	 * Regression: `find_post_by_bsky_uri()` called `get_posts()` without a
	 * `post_type`, so WordPress defaulted the query to `post_type => 'post'`.
	 * Every like/repost/reply on a supported page or custom post type then
	 * resolved to nothing and was dropped silently — no comment row, no
	 * error, no log. Fails before the resolver is scoped to the federated
	 * post types.
	 */
	public function test_find_post_by_bsky_uri_resolves_supported_non_default_post_type() {
		\update_option( 'atmosphere_support_post_types', array( 'post', 'page' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$uri     = 'at://did:plc:test123/app.bsky.feed.post/page123';

		\update_post_meta( $post_id, BskyPost::META_URI, $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );

		$this->assertSame( $post_id, $method->invoke( null, $uri ) );
	}

	/**
	 * A supported CPT registered with `exclude_from_search` must resolve too.
	 *
	 * This is why the resolver scopes to `get_supported_post_types()` rather
	 * than the `post_type => 'any'` shortcut: `'any'` omits post types flagged
	 * `exclude_from_search`, so a naive `'any'` fix would still drop reactions
	 * on such a type. Fails both before the fix and under an `'any'`-based fix.
	 */
	public function test_find_post_by_bsky_uri_resolves_supported_exclude_from_search_cpt() {
		\register_post_type(
			'atmos_hidden_cpt',
			array(
				'public'              => true,
				'exclude_from_search' => true,
			)
		);
		\update_option( 'atmosphere_support_post_types', array( 'post', 'atmos_hidden_cpt' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'atmos_hidden_cpt',
				'post_status' => 'publish',
			)
		);
		$uri     = 'at://did:plc:test123/app.bsky.feed.post/hidden123';

		\update_post_meta( $post_id, BskyPost::META_URI, $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );

		$this->assertSame( $post_id, $method->invoke( null, $uri ) );
	}

	/**
	 * The thread-index fallback must also resolve a supported non-`post` type.
	 *
	 * `find_post_by_bsky_uri()` scopes BOTH lookups — the single-record
	 * `META_URI` key and the `META_URI_INDEX` thread fallback Publisher
	 * populates for every teaser-thread reply. The other regression tests
	 * only seed `META_URI`, so they never reach the second query. This one
	 * seeds only the index key on a `page` to guard the fallback branch
	 * against a future change that drops its `post_type` scope.
	 */
	public function test_find_post_by_bsky_uri_thread_index_resolves_supported_non_default_post_type() {
		\update_option( 'atmosphere_support_post_types', array( 'post', 'page' ) );

		$post_id   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$reply_uri = 'at://did:plc:test123/app.bsky.feed.post/pagereply123';

		\add_post_meta( $post_id, BskyPost::META_URI_INDEX, $reply_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );

		$this->assertSame( $post_id, $method->invoke( null, $reply_uri ) );
	}

	/**
	 * A reaction on an unsupported post type must NOT resolve.
	 *
	 * Pins the upper bound of the scoping: the resolver covers exactly the
	 * federated types, no more. Without this, a future widening back to
	 * `post_type => 'any'` would silently start importing reactions onto
	 * content the site never federated, and every positive test would still
	 * pass. Here `page` carries matching meta but is absent from the
	 * supported list, so the lookup must miss.
	 */
	public function test_find_post_by_bsky_uri_ignores_unsupported_post_type() {
		\update_option( 'atmosphere_support_post_types', array( 'post' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$uri     = 'at://did:plc:test123/app.bsky.feed.post/unsupported123';

		\update_post_meta( $post_id, BskyPost::META_URI, $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );

		$this->assertFalse( $method->invoke( null, $uri ) );
	}

	/**
	 * An empty supported-types list must resolve nothing, not fall back to `post`.
	 *
	 * When a site owner unticks every type, the option is stored as an empty
	 * array. Passing `post_type => array()` to `get_posts()` silently defaults
	 * to `post_type => 'post'`, which would reintroduce the original miss (and
	 * resolve reactions onto content the Publisher no longer federates). The
	 * resolver must short-circuit to false instead: a matching `post` exists
	 * here, yet nothing is federated, so nothing should resolve.
	 */
	public function test_find_post_by_bsky_uri_returns_false_when_no_supported_types() {
		\update_option( 'atmosphere_support_post_types', array() );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$uri     = 'at://did:plc:test123/app.bsky.feed.post/notypes123';

		\update_post_meta( $post_id, BskyPost::META_URI, $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_post_by_bsky_uri' );

		$this->assertFalse( $method->invoke( null, $uri ) );
	}

	/**
	 * Test that find_comment_by_source_id returns the correct comment.
	 */
	public function test_find_comment_by_source_id() {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$uri        = 'at://did:plc:reply/app.bsky.feed.post/reply123';

		\update_comment_meta( $comment_id, 'source_id', $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_comment_by_source_id' );

		$this->assertSame( $comment_id, $method->invoke( null, $uri ) );
	}

	/**
	 * Test that find_comment_by_source_id returns false for unknown URI.
	 */
	public function test_find_comment_by_source_id_not_found() {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_comment_by_source_id' );

		$this->assertFalse( $method->invoke( null, 'at://did:plc:unknown/app.bsky.feed.post/xyz' ) );
	}

	/**
	 * Test that process_reply skips duplicate URIs.
	 */
	public function test_process_reply_skips_duplicates() {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$uri        = 'at://did:plc:author/app.bsky.feed.post/reply456';

		\update_comment_meta( $comment_id, 'source_id', $uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => $uri,
			'cid'    => 'bafyrei123',
			'record' => array(
				'text'  => 'Duplicate reply',
				'reply' => array(
					'parent' => array( 'uri' => 'at://did:plc:me/app.bsky.feed.post/orig' ),
					'root'   => array( 'uri' => 'at://did:plc:me/app.bsky.feed.post/orig' ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:author',
				'handle' => 'author.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification ) );
	}

	/**
	 * Test that process_reply creates a comment for a direct reply.
	 */
	public function test_process_reply_creates_comment() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/reply789',
			'cid'    => 'bafyrei456',
			'record' => array(
				'text'      => 'Great post!',
				'createdAt' => '2026-03-21T12:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( 'Great post!', $comment->comment_content );
		$this->assertSame( 'comment', $comment->comment_type );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
		$this->assertSame( '0', $comment->comment_parent );

		// Check meta.
		$this->assertSame(
			'atproto',
			\get_comment_meta( $comment_id, 'protocol', true )
		);
		$this->assertSame(
			'at://did:plc:replier/app.bsky.feed.post/reply789',
			\get_comment_meta( $comment_id, 'source_id', true )
		);
		$this->assertSame(
			'https://bsky.app/profile/replier.bsky.social/post/reply789',
			\get_comment_meta( $comment_id, 'source_url', true )
		);
		$this->assertSame(
			'did:plc:replier',
			\get_comment_meta( $comment_id, '_atmosphere_author_did', true )
		);
	}

	/**
	 * A reply whose `text` contains a Bluesky-truncated link must be
	 * stored with the real URL resolved from the record's facets, not
	 * the lossy `bsky.app/profile/jere...` display string. Regression
	 * test for issue #132.
	 */
	public function test_process_reply_resolves_truncated_link_facet() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/linkreply',
			'cid'    => 'bafyrei789',
			'record' => array(
				'text'      => "An exact copy of my post :)\n\nbsky.app/profile/jere...",
				'createdAt' => '2026-06-11T18:31:10.876Z',
				'facets'    => array(
					array(
						'index'    => array(
							'byteStart' => 29,
							'byteEnd'   => 53,
						),
						'features' => array(
							array(
								'$type' => 'app.bsky.richtext.facet#link',
								'uri'   => 'https://bsky.app/profile/jeremy.herve.bzh/post/3mnzu7nvcss2e',
							),
						),
					),
				),
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertStringContainsString(
			'<a href="https://bsky.app/profile/jeremy.herve.bzh/post/3mnzu7nvcss2e">bsky.app/profile/jere...</a>',
			$comment->comment_content
		);
		// The plain-text portion survives intact.
		$this->assertStringContainsString( 'An exact copy of my post :)', $comment->comment_content );
	}

	/**
	 * A reply that quotes another post carries the quoted post's AT-URI in
	 * the record's `embed` (app.bsky.embed.record), not in `text`. The
	 * imported comment must surface it as a linked blockquote pointing at
	 * the quoted post's bsky.app page. Regression test for issue #133.
	 */
	public function test_process_reply_appends_quote_post_embed() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/quotereply',
			'cid'    => 'bafyreiquote',
			'record' => array(
				'text'      => 'Look at this!',
				'createdAt' => '2026-06-20T12:00:00.000Z',
				'embed'     => array(
					'$type'  => 'app.bsky.embed.record',
					'record' => array(
						'cid' => 'bafyreifz7yief4dwg7wgyotql3zkknx4nolwcl2ukio6jdld4ejn7wclfm',
						'uri' => 'at://did:plc:quoted/app.bsky.feed.post/3mnzu7nvcss2e',
					),
				),
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );

		// The original reply text survives.
		$this->assertStringContainsString( 'Look at this!', $comment->comment_content );
		// A blockquote links to the quoted post on bsky.app (DID form).
		$this->assertStringContainsString( '<blockquote', $comment->comment_content );
		$this->assertStringContainsString(
			'href="https://bsky.app/profile/did:plc:quoted/post/3mnzu7nvcss2e"',
			$comment->comment_content
		);
	}

	/**
	 * A quote that also carries media uses app.bsky.embed.recordWithMedia,
	 * which nests the quoted record one level deeper. The quoted post must
	 * still be surfaced. Regression test for issue #133.
	 */
	public function test_process_reply_appends_quote_from_record_with_media() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/quotemedia',
			'cid'    => 'bafyreiquotemedia',
			'record' => array(
				'text'      => 'Quote with an image.',
				'createdAt' => '2026-06-20T12:00:00.000Z',
				'embed'     => array(
					'$type'  => 'app.bsky.embed.recordWithMedia',
					'record' => array(
						'$type'  => 'app.bsky.embed.record',
						'record' => array(
							'cid' => 'bafyreinested',
							'uri' => 'at://did:plc:quoted/app.bsky.feed.post/withmedia',
						),
					),
					'media'  => array(
						'$type'  => 'app.bsky.embed.images',
						'images' => array(),
					),
				),
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertStringContainsString( 'Quote with an image.', $comment->comment_content );
		$this->assertStringContainsString(
			'href="https://bsky.app/profile/did:plc:quoted/post/withmedia"',
			$comment->comment_content
		);
	}

	/**
	 * A reply that is *only* a quote (empty text) must still import as a
	 * comment carrying the quote blockquote, rather than being dropped by
	 * the empty-text gate. Regression test for issue #133.
	 */
	public function test_process_reply_imports_quote_only_reply() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/quoteonly',
			'cid'    => 'bafyreiquoteonly',
			'record' => array(
				'text'      => '',
				'createdAt' => '2026-06-20T12:00:00.000Z',
				'embed'     => array(
					'$type'  => 'app.bsky.embed.record',
					'record' => array(
						'uri' => 'at://did:plc:quoted/app.bsky.feed.post/onlyquote',
					),
				),
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertStringContainsString(
			'href="https://bsky.app/profile/did:plc:quoted/post/onlyquote"',
			$comment->comment_content
		);
	}

	/**
	 * Only quoted `app.bsky.feed.post` records get a bsky.app post link;
	 * a quoted record of another collection (e.g. a feed generator or
	 * list) has no post page, so no blockquote is added — but the reply
	 * text still imports normally.
	 */
	public function test_process_reply_ignores_non_post_quote_embed() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/quotelist',
			'cid'    => 'bafyreiquotelist',
			'record' => array(
				'text'      => 'Quoting a list.',
				'createdAt' => '2026-06-20T12:00:00.000Z',
				'embed'     => array(
					'$type'  => 'app.bsky.embed.record',
					'record' => array(
						'uri' => 'at://did:plc:quoted/app.bsky.graph.list/somelist',
					),
				),
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( 'Quoting a list.', $comment->comment_content );
		$this->assertStringNotContainsString( '<blockquote', $comment->comment_content );
	}

	/**
	 * The hydrated `app.bsky.embed.record#view` shape (returned by feed /
	 * thread views) carries the quoted post's URI at the same `record.uri`
	 * path as the raw record form, and the `$type` prefix match must accept
	 * it. Locks in the documented `#view` support.
	 */
	public function test_process_reply_appends_quote_from_record_view() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/quoteview',
			'cid'    => 'bafyreiquoteview',
			'record' => array(
				'text'      => 'Quoting from a view.',
				'createdAt' => '2026-06-20T12:00:00.000Z',
				'embed'     => array(
					'$type'  => 'app.bsky.embed.record#view',
					'record' => array(
						'$type' => 'app.bsky.embed.record#viewRecord',
						'uri'   => 'at://did:plc:quoted/app.bsky.feed.post/viewquote',
					),
				),
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertStringContainsString(
			'href="https://bsky.app/profile/did:plc:quoted/post/viewquote"',
			$comment->comment_content
		);
	}

	/**
	 * The quote link is built through `appview_url()`, so a site that swaps
	 * its appview host via `atmosphere_appview_host` gets the quote link
	 * pointed at that host too — it must not be the lone hardcoded `bsky.app`.
	 */
	public function test_process_reply_quote_honors_appview_host_filter() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$filter = static fn() => 'deer.social';
		\add_filter( 'atmosphere_appview_host', $filter );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/quotefiltered',
			'cid'    => 'bafyreiquotefiltered',
			'record' => array(
				'text'      => 'Quoting on a custom appview.',
				'createdAt' => '2026-06-20T12:00:00.000Z',
				'embed'     => array(
					'$type'  => 'app.bsky.embed.record',
					'record' => array(
						'cid' => 'bafyreifiltered',
						'uri' => 'at://did:plc:quoted/app.bsky.feed.post/filteredpost',
					),
				),
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		\remove_filter( 'atmosphere_appview_host', $filter );

		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertStringContainsString(
			'href="https://deer.social/profile/did:plc:quoted/post/filteredpost"',
			$comment->comment_content
		);
		$this->assertStringNotContainsString( 'bsky.app', $comment->comment_content );
	}

	/**
	 * A malformed `embed` value (untrusted PDS JSON) must not fatal the
	 * cron sync; the reply still imports as a plain comment without a
	 * blockquote. Covers a scalar embed and a record whose `uri` is the
	 * wrong type — pinning the `is_array`/`is_string` guards.
	 *
	 * @dataProvider data_malformed_embeds
	 * @param mixed $embed Malformed embed value.
	 */
	public function test_process_reply_tolerates_malformed_embed( $embed ) {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/badembed-' . \uniqid(),
			'cid'    => 'bafyreibadembed',
			'record' => array(
				'text'      => 'Plain reply with a broken embed.',
				'createdAt' => '2026-06-20T12:00:00.000Z',
				'embed'     => $embed,
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );
		$comment = \get_comment( $comment_id );
		$this->assertSame( 'Plain reply with a broken embed.', $comment->comment_content );
		$this->assertStringNotContainsString( '<blockquote', $comment->comment_content );
	}

	/**
	 * Data provider for malformed `embed` shapes.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function data_malformed_embeds(): array {
		return array(
			'scalar embed'       => array( 'not-an-array' ),
			'non-array record'   => array(
				array(
					'$type'  => 'app.bsky.embed.record',
					'record' => 'nope',
				),
			),
			'non-string uri'     => array(
				array(
					'$type'  => 'app.bsky.embed.record',
					'record' => array( 'uri' => array() ),
				),
			),
			'unknown embed type' => array(
				array(
					'$type'  => 'app.bsky.embed.images',
					'images' => array(),
				),
			),
		);
	}

	/**
	 * A reply whose record carries a malformed `facets` value (untrusted
	 * PDS JSON) must still import as a plain comment rather than fataling
	 * the cron sync with a TypeError. Regression test for PR #134.
	 */
	public function test_process_reply_tolerates_malformed_facets() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/badfacets',
			'cid'    => 'bafyreibad',
			'record' => array(
				'text'      => 'Plain reply text.',
				'createdAt' => '2026-06-11T18:31:10.876Z',
				'facets'    => 'not-an-array',
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );
		$comment = \get_comment( $comment_id );
		$this->assertSame( 'Plain reply text.', $comment->comment_content );
	}

	/**
	 * Test that process_reply drops a reply when get_comment() returns
	 * null for the resolved parent comment ID (race: comment deleted
	 * between the meta lookup and the get_comment call). The previous
	 * "fall back to the root post" behavior caused unresolvable replies
	 * to be re-attached as top-level orphans on every sync run,
	 * looping the moderation queue indefinitely.
	 */
	public function test_process_reply_drops_when_parent_comment_row_is_missing() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/rootpost';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$parent_comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$parent_reply_uri  = 'at://did:plc:first/app.bsky.feed.post/missingparent';
		\update_comment_meta( $parent_comment_id, 'source_id', $parent_reply_uri );

		// Simulate the race: find_comment_by_source_id returns the ID,
		// but get_comment() returns null because the row is gone.
		\add_filter(
			'get_comment',
			static function ( $comment ) use ( $parent_comment_id ) {
				if ( $comment && (int) $comment->comment_ID === $parent_comment_id ) {
					return null;
				}
				return $comment;
			}
		);

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/nested',
			'cid'    => 'bafyreinested',
			'record' => array(
				'text'      => 'Nested reply',
				'createdAt' => '2026-03-21T14:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $parent_reply_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		try {
			$result = $method->invoke( null, $notification );
		} finally {
			\remove_all_filters( 'get_comment' );
		}

		$this->assertFalse( $result );
		$find_method = new \ReflectionMethod( Reaction_Sync::class, 'find_comment_by_source_id' );
		$this->assertFalse( $find_method->invoke( null, 'at://did:plc:replier/app.bsky.feed.post/nested' ) );
	}

	/**
	 * Test that process_reply drops a reply whose parent record is
	 * neither a local WP post nor a previously-synced WP comment, even
	 * when the thread root resolves to one of our posts. Reproduces the
	 * "blocked user / deleted parent" loop: previously, the reply would
	 * be re-attached as a top-level comment on the root post on every
	 * sync run, reinjecting it into the moderation queue indefinitely.
	 */
	public function test_process_reply_drops_orphan_when_parent_is_unresolved() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/threadroot';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		// Parent is a Bluesky reply that was never synced (e.g. blocked
		// user's reply was deleted). Root is our own WP post.
		$notification = array(
			'uri'    => 'at://did:plc:me/app.bsky.feed.post/myreplytoblocked',
			'cid'    => 'bafyreioauth',
			'record' => array(
				'text'      => 'Replying to a now-deleted parent.',
				'createdAt' => '2026-04-23T12:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => 'at://did:plc:blocked/app.bsky.feed.post/gone' ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:me',
				'handle' => 'me.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification ) );
		$this->assertSame( array(), \get_comments( array( 'post_id' => $post_id ) ) );
	}

	/**
	 * Test that process_reply handles nested replies.
	 */
	public function test_process_reply_nested() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		// Create a parent comment.
		$parent_comment_id = self::factory()->comment->create(
			array( 'comment_post_ID' => $post_id )
		);
		$parent_reply_uri  = 'at://did:plc:first/app.bsky.feed.post/firstreply';

		\update_comment_meta( $parent_comment_id, 'source_id', $parent_reply_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:second/app.bsky.feed.post/nestedreply',
			'cid'    => 'bafyrei789',
			'record' => array(
				'text'      => 'Nested reply!',
				'createdAt' => '2026-03-21T13:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $parent_reply_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:second',
				'handle' => 'second.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( (string) $parent_comment_id, $comment->comment_parent );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
	}

	/**
	 * A reply whose parent the admin moderated away must be dropped as an
	 * orphan, not imported under the suppressed parent — dedup sees
	 * spam/trash rows, parent resolution deliberately does not.
	 */
	public function test_process_reply_drops_child_of_moderated_parent() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$parent_comment_id = self::factory()->comment->create(
			array( 'comment_post_ID' => $post_id )
		);
		$parent_reply_uri  = 'at://did:plc:first/app.bsky.feed.post/spammedparent';

		\update_comment_meta( $parent_comment_id, 'source_id', $parent_reply_uri );
		\wp_spam_comment( $parent_comment_id );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$result = $method->invoke(
			null,
			array(
				'uri'    => 'at://did:plc:second/app.bsky.feed.post/childofspam',
				'cid'    => 'bafychildofspam',
				'record' => array(
					'text'      => 'Reply to a suppressed parent.',
					'createdAt' => '2026-03-21T13:00:00.000Z',
					'reply'     => array(
						'parent' => array( 'uri' => $parent_reply_uri ),
						'root'   => array( 'uri' => $post_uri ),
					),
				),
				'author' => array(
					'did'    => 'did:plc:second',
					'handle' => 'second.bsky.social',
				),
			)
		);

		$this->assertFalse( $result );
		$this->assertFalse(
			$this->find_comment_id_by_source_uri( 'at://did:plc:second/app.bsky.feed.post/childofspam' ),
			'The child of a moderated parent must not be imported.'
		);
	}

	/**
	 * Test that process_reply skips when no matching post is found.
	 */
	public function test_process_reply_skips_unmatched() {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:someone/app.bsky.feed.post/orphan',
			'cid'    => 'bafyrei000',
			'record' => array(
				'text'  => 'Reply to unknown post',
				'reply' => array(
					'parent' => array( 'uri' => 'at://did:plc:unknown/app.bsky.feed.post/nope' ),
					'root'   => array( 'uri' => 'at://did:plc:unknown/app.bsky.feed.post/nope' ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:someone',
				'handle' => 'someone.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification ) );
	}

	/**
	 * Test that empty text replies are skipped.
	 */
	public function test_process_reply_skips_empty_text() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost2';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:empty/app.bsky.feed.post/emptyreply',
			'cid'    => 'bafyrei111',
			'record' => array(
				'text'  => '',
				'reply' => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:empty',
				'handle' => 'empty.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification ) );
	}

	/**
	 * Test that a registered `atmosphere_should_sync_reply` callback can
	 * suppress the comment insert. The filter receives the notification,
	 * resolved post ID, and resolved parent-comment ID.
	 */
	public function test_process_reply_respects_should_sync_filter() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/filterable';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$captured = array();
		$filter   = function ( $should, $notification, $resolved_post_id, $comment_parent ) use ( &$captured ) {
			$captured = array(
				'should'         => $should,
				'notification'   => $notification,
				'post_id'        => $resolved_post_id,
				'comment_parent' => $comment_parent,
			);
			return false;
		};
		\add_filter( 'atmosphere_should_sync_reply', $filter, 10, 4 );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$reply_uri    = 'at://did:plc:me/app.bsky.feed.post/chunk2';
		$notification = array(
			'uri'    => $reply_uri,
			'cid'    => 'bafyreichunk',
			'record' => array(
				'text'      => 'Continued thought…',
				'createdAt' => '2026-03-21T12:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:me',
				'handle' => 'me.example.com',
			),
		);

		try {
			$result = $method->invoke( null, $notification );
		} finally {
			\remove_filter( 'atmosphere_should_sync_reply', $filter, 10 );
		}

		$this->assertFalse( $result );
		$this->assertTrue( $captured['should'] );
		$this->assertSame( $reply_uri, $captured['notification']['uri'] );
		$this->assertSame( $post_id, $captured['post_id'] );
		$this->assertSame( 0, $captured['comment_parent'] );

		// No comment was written.
		$comments = \get_comments( array( 'post_id' => $post_id ) );
		$this->assertSame( array(), $comments );
	}

	/**
	 * Test that the default filter value is true — no callback registered
	 * means existing behavior (comment is inserted as before). Mirrors
	 * the contract assertions of test_process_reply_creates_comment so a
	 * future regression that changes what insert_reaction writes is
	 * caught here too.
	 */
	public function test_process_reply_defaults_to_syncing() {
		$post_id   = self::factory()->post->create();
		$post_uri  = 'at://did:plc:me/app.bsky.feed.post/defaultsync';
		$reply_uri = 'at://did:plc:friend/app.bsky.feed.post/friendreply';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => $reply_uri,
			'cid'    => 'bafyreifriend',
			'record' => array(
				'text'      => 'Nice one.',
				'createdAt' => '2026-03-21T12:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:friend',
				'handle' => 'friend.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( 'Nice one.', $comment->comment_content );
		$this->assertSame( 'comment', $comment->comment_type );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
		$this->assertSame( '0', $comment->comment_parent );
		$this->assertSame( 'atproto', \get_comment_meta( $comment_id, 'protocol', true ) );
		$this->assertSame( $reply_uri, \get_comment_meta( $comment_id, 'source_id', true ) );
		$this->assertSame( 'did:plc:friend', \get_comment_meta( $comment_id, '_atmosphere_author_did', true ) );
	}

	/**
	 * Test that the filter receives the resolved nested-parent comment ID
	 * (not 0) when the reply targets an existing synced comment instead
	 * of the post itself. Locks in the contract that $comment_parent is
	 * the resolved local ID, not the AT-URI of the parent record.
	 */
	public function test_process_reply_filter_receives_nested_parent_id() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/parentpost';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$parent_comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$parent_reply_uri  = 'at://did:plc:first/app.bsky.feed.post/firstreply';
		\update_comment_meta( $parent_comment_id, 'source_id', $parent_reply_uri );

		$captured = array();
		$filter   = function ( $should, $notification, $resolved_post_id, $comment_parent ) use ( &$captured ) {
			$captured = array(
				'post_id'        => $resolved_post_id,
				'comment_parent' => $comment_parent,
			);
			return $should;
		};
		\add_filter( 'atmosphere_should_sync_reply', $filter, 10, 4 );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:second/app.bsky.feed.post/nestedreply',
			'cid'    => 'bafyreinested',
			'record' => array(
				'text'      => 'Replying to the reply.',
				'createdAt' => '2026-03-21T12:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $parent_reply_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:second',
				'handle' => 'second.bsky.social',
			),
		);

		try {
			$method->invoke( null, $notification );
		} finally {
			\remove_filter( 'atmosphere_should_sync_reply', $filter, 10 );
		}

		$this->assertSame( $post_id, $captured['post_id'] );
		$this->assertSame( $parent_comment_id, $captured['comment_parent'] );
	}

	/**
	 * Filter return values are cast via `(bool)` before deciding whether
	 * to insert. Lock in that null, 0, and '' suppress; truthy non-bool
	 * values allow the sync. Protects the cast against accidental removal.
	 *
	 * @dataProvider data_filter_falsy_returns
	 * @param mixed $falsy_return Value the callback returns.
	 */
	public function test_process_reply_treats_falsy_filter_returns_as_suppression( $falsy_return ) {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/cast-' . \uniqid();
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$filter = static fn() => $falsy_return;
		\add_filter( 'atmosphere_should_sync_reply', $filter );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:friend/app.bsky.feed.post/falsy-' . \uniqid(),
			'cid'    => 'bafyreicast',
			'record' => array(
				'text'  => 'Should be suppressed.',
				'reply' => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:friend',
				'handle' => 'friend.bsky.social',
			),
		);

		try {
			$result = $method->invoke( null, $notification );
		} finally {
			\remove_filter( 'atmosphere_should_sync_reply', $filter );
		}

		$this->assertFalse( $result );
		$this->assertSame( array(), \get_comments( array( 'post_id' => $post_id ) ) );
	}

	/**
	 * Data provider for falsy `atmosphere_should_sync_reply` returns.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function data_filter_falsy_returns(): array {
		return array(
			'null'         => array( null ),
			'zero'         => array( 0 ),
			'empty string' => array( '' ),
		);
	}

	/**
	 * Test that process_like creates a like comment.
	 */
	public function test_process_like_creates_comment() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/likedpost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );

		$notification = array(
			'uri'    => 'at://did:plc:liker/app.bsky.feed.like/like1',
			'cid'    => 'bafyreilike1',
			'record' => array(
				'createdAt' => '2026-03-21T14:00:00.000Z',
				'subject'   => array(
					'uri' => $post_uri,
					'cid' => 'bafyreimypost',
				),
			),
			'author' => array(
				'did'    => 'did:plc:liker',
				'handle' => 'liker.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification, 'like' );

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( '… liked this!', $comment->comment_content );
		$this->assertSame( 'like', $comment->comment_type );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
		$this->assertSame( '0', $comment->comment_parent );

		$this->assertSame(
			'atproto',
			\get_comment_meta( $comment_id, 'protocol', true )
		);
		$this->assertSame(
			'at://did:plc:liker/app.bsky.feed.like/like1',
			\get_comment_meta( $comment_id, 'source_id', true )
		);
		$this->assertSame(
			'',
			\get_comment_meta( $comment_id, 'source_url', true )
		);
	}

	/**
	 * Test that process_like skips an unknown subject post.
	 */
	public function test_process_like_skips_unknown_subject() {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );

		$notification = array(
			'uri'    => 'at://did:plc:liker/app.bsky.feed.like/like2',
			'cid'    => 'bafyreilike2',
			'record' => array(
				'subject' => array( 'uri' => 'at://did:plc:other/app.bsky.feed.post/notours' ),
			),
			'author' => array(
				'did'    => 'did:plc:liker',
				'handle' => 'liker.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification, 'like' ) );
	}

	/**
	 * Test that process_subject_reaction deduplicates on source_id.
	 */
	public function test_process_like_skips_duplicates() {
		$post_id     = self::factory()->post->create();
		$post_uri    = 'at://did:plc:me/app.bsky.feed.post/likedpost2';
		$like_uri    = 'at://did:plc:liker/app.bsky.feed.like/like3';
		$existing_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );
		\update_comment_meta( $existing_id, 'source_id', $like_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );

		$notification = array(
			'uri'    => $like_uri,
			'record' => array( 'subject' => array( 'uri' => $post_uri ) ),
			'author' => array(
				'did'    => 'did:plc:liker',
				'handle' => 'liker.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification, 'like' ) );
	}

	/**
	 * Test that process_subject_reaction creates a repost comment.
	 */
	public function test_process_repost_creates_comment() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/repostedpost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );

		$notification = array(
			'uri'    => 'at://did:plc:reposter/app.bsky.feed.repost/rep1',
			'cid'    => 'bafyreirepost1',
			'record' => array(
				'createdAt' => '2026-03-21T15:00:00.000Z',
				'subject'   => array(
					'uri' => $post_uri,
					'cid' => 'bafyreimypost',
				),
			),
			'author' => array(
				'did'    => 'did:plc:reposter',
				'handle' => 'reposter.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification, 'repost' );

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( '… reposted this!', $comment->comment_content );
		$this->assertSame( 'repost', $comment->comment_type );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );

		$this->assertSame(
			'at://did:plc:reposter/app.bsky.feed.repost/rep1',
			\get_comment_meta( $comment_id, 'source_id', true )
		);
		$this->assertSame(
			'',
			\get_comment_meta( $comment_id, 'source_url', true )
		);
		$this->assertSame(
			'did:plc:reposter',
			\get_comment_meta( $comment_id, '_atmosphere_author_did', true )
		);
	}

	/**
	 * Test that process_repost skips an unknown subject post.
	 */
	public function test_process_repost_skips_unknown_subject() {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );

		$notification = array(
			'uri'    => 'at://did:plc:reposter/app.bsky.feed.repost/rep2',
			'record' => array(
				'subject' => array( 'uri' => 'at://did:plc:other/app.bsky.feed.post/notours' ),
			),
			'author' => array(
				'did'    => 'did:plc:reposter',
				'handle' => 'reposter.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification, 'repost' ) );
	}

	/**
	 * Seed a fake connection and profile cache so self-sync tests can
	 * call get_did() and resolve_author() without hitting the network.
	 *
	 * @param string $did    Fake self DID to store in atmosphere_connection.
	 * @param string $handle Fake self handle (also used as display name).
	 */
	private function seed_self_identity( string $did = 'did:plc:me', string $handle = 'me.bsky.social' ): void {
		\update_option(
			'atmosphere_connection',
			array( 'did' => $did ),
			false
		);

		\set_transient(
			'atmosphere_profile_' . \md5( $did ),
			array(
				'name'   => $handle,
				'handle' => $handle,
				'avatar' => 'https://example.com/avatar.jpg',
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Test that a self-like record on one of our posts becomes a like comment.
	 */
	public function test_process_own_record_like_on_our_post() {
		$this->seed_self_identity();

		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_own_record' );

		$record = array(
			'uri'   => 'at://did:plc:me/app.bsky.feed.like/selflike1',
			'cid'   => 'bafyselflike1',
			'value' => array(
				'$type'     => 'app.bsky.feed.like',
				'createdAt' => '2026-04-20T14:00:00.000Z',
				'subject'   => array(
					'uri' => $post_uri,
					'cid' => 'bafymypost',
				),
			),
		);

		$comment_id = $method->invoke( null, $record, 'like' );

		$this->assertIsInt( $comment_id );
		$comment = \get_comment( $comment_id );
		$this->assertSame( 'like', $comment->comment_type );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
		$this->assertSame(
			'at://did:plc:me/app.bsky.feed.like/selflike1',
			\get_comment_meta( $comment_id, 'source_id', true )
		);
	}

	/**
	 * A getProfile failure while syncing an own-record reaction falls back
	 * to the locally stored identity handle, so the comment author link
	 * never degrades to a broken profile URL.
	 */
	public function test_process_own_record_falls_back_to_identity_handle_when_profile_fails() {
		/*
		 * Connection did only — no profile transient and no usable API
		 * session, so resolve_author() fails; the identity row supplies
		 * the local handle fallback.
		 */
		\update_option( 'atmosphere_connection', array( 'did' => 'did:plc:me' ), false );
		\update_option(
			'atmosphere_identity',
			array(
				'did'    => 'did:plc:me',
				'handle' => 'me.example.com',
			),
			false
		);

		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_own_record' );

		try {
			$comment_id = $method->invoke(
				null,
				array(
					'uri'   => 'at://did:plc:me/app.bsky.feed.like/fallbacklike',
					'cid'   => 'bafyfallbacklike',
					'value' => array(
						'$type'     => 'app.bsky.feed.like',
						'createdAt' => '2026-04-20T14:00:00.000Z',
						'subject'   => array(
							'uri' => $post_uri,
							'cid' => 'bafymypost',
						),
					),
				),
				'like'
			);
		} finally {
			\delete_option( 'atmosphere_connection' );
			\delete_option( 'atmosphere_identity' );
		}

		$this->assertIsInt( $comment_id );
		$this->assertStringContainsString(
			'me.example.com',
			\get_comment( $comment_id )->comment_author_url,
			'The author link must fall back to the stored identity handle.'
		);
	}

	/**
	 * A getProfile call that succeeds but hands back an empty handle falls
	 * back the same way a failed one does — otherwise our own imported
	 * reactions carry a link to an empty profile path.
	 */
	public function test_process_own_record_falls_back_to_identity_handle_when_profile_handle_is_empty() {
		\update_option( 'atmosphere_connection', array( 'did' => 'did:plc:me' ), false );
		\update_option(
			'atmosphere_identity',
			array(
				'did'    => 'did:plc:me',
				'handle' => 'me.example.com',
			),
			false
		);

		/*
		 * A cached profile short-circuits the API call, so resolve_author()
		 * returns a populated array whose handle is still empty.
		 */
		\set_transient(
			'atmosphere_profile_' . \md5( 'did:plc:me' ),
			array(
				'name'   => '',
				'handle' => '',
				'avatar' => '',
			),
			\HOUR_IN_SECONDS
		);

		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/emptyownhandlepost';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_own_record' );

		try {
			$comment_id = $method->invoke(
				null,
				array(
					'uri'   => 'at://did:plc:me/app.bsky.feed.like/emptyownhandlelike',
					'cid'   => 'bafyemptyownhandlelike',
					'value' => array(
						'$type'     => 'app.bsky.feed.like',
						'createdAt' => '2026-04-20T14:00:00.000Z',
						'subject'   => array(
							'uri' => $post_uri,
							'cid' => 'bafymypost',
						),
					),
				),
				'like'
			);
		} finally {
			\delete_transient( 'atmosphere_profile_' . \md5( 'did:plc:me' ) );
			\delete_option( 'atmosphere_connection' );
			\delete_option( 'atmosphere_identity' );
		}

		$this->assertIsInt( $comment_id );
		$this->assertSame(
			'https://bsky.app/profile/me.example.com',
			\get_comment( $comment_id )->comment_author_url,
			'An empty handle on our own profile must not produce a dead profile link.'
		);
	}

	/**
	 * Test that a self-like on someone else's post is skipped.
	 */
	public function test_process_own_record_like_on_foreign_post_is_skipped() {
		$this->seed_self_identity();

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_own_record' );

		$record = array(
			'uri'   => 'at://did:plc:me/app.bsky.feed.like/selflike2',
			'value' => array(
				'subject' => array( 'uri' => 'at://did:plc:somebodyelse/app.bsky.feed.post/theirs' ),
			),
		);

		$this->assertFalse( $method->invoke( null, $record, 'like' ) );
	}

	/**
	 * Test that a self-reply to our own post becomes a reply comment.
	 */
	public function test_process_own_record_reply_on_our_post() {
		$this->seed_self_identity();

		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/mypost2';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_own_record' );

		$record = array(
			'uri'   => 'at://did:plc:me/app.bsky.feed.post/selfreply1',
			'cid'   => 'bafyselfreply1',
			'value' => array(
				'$type'     => 'app.bsky.feed.post',
				'text'      => 'Replying to myself',
				'createdAt' => '2026-04-20T15:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
		);

		$comment_id = $method->invoke( null, $record, 'comment' );

		$this->assertIsInt( $comment_id );
		$comment = \get_comment( $comment_id );
		$this->assertSame( 'comment', $comment->comment_type );
		$this->assertSame( 'Replying to myself', $comment->comment_content );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
	}

	/**
	 * Test that a self-authored original post (no reply field) is skipped.
	 */
	public function test_process_own_record_original_post_is_skipped() {
		$this->seed_self_identity();

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_own_record' );

		$record = array(
			'uri'   => 'at://did:plc:me/app.bsky.feed.post/originalpost',
			'value' => array(
				'$type' => 'app.bsky.feed.post',
				'text'  => 'A brand new top-level post',
			),
		);

		$this->assertFalse( $method->invoke( null, $record, 'comment' ) );
	}

	/**
	 * Test that createdAt (UTC) is stored verbatim in comment_date_gmt
	 * even when the site timezone is non-UTC, without a second
	 * local→UTC conversion.
	 */
	public function test_reply_stores_createdAt_as_utc_on_non_utc_site() {
		\update_option( 'timezone_string', 'America/New_York' );

		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/tzpost';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/tzreply',
			'cid'    => 'bafyrei_tz',
			'record' => array(
				'text'      => 'Reply',
				'createdAt' => '2026-03-21T12:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$comment_id = $method->invoke( null, $notification );
		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );
		$this->assertSame( '2026-03-21 12:00:00', $comment->comment_date_gmt );

		\update_option( 'timezone_string', '' );
	}

	/**
	 * Invoke the private paginate() method.
	 *
	 * @param callable $fetch      Fetch callback.
	 * @param string   $items_key  Response items key.
	 * @param string   $option_key Watermark option.
	 * @param callable $process    Process callback.
	 */
	private function invoke_paginate( callable $fetch, string $items_key, string $option_key, callable $process ): void {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'paginate' );
		$method->invoke( null, $fetch, $items_key, $option_key, $process );
	}

	/**
	 * Test that paginate() walks every item when no watermark is stored.
	 */
	public function test_paginate_walks_full_page_without_watermark() {
		$option_key = 'atmosphere_test_paginate_fresh';
		\delete_option( $option_key );

		$fetch = static fn() => array(
			'items' => array(
				array( 'uri' => 'at://a/1' ),
				array( 'uri' => 'at://a/2' ),
				array( 'uri' => 'at://a/3' ),
			),
		);

		$seen    = array();
		$process = static function ( array $item ) use ( &$seen ) {
			$seen[] = $item['uri'];
		};

		$this->invoke_paginate( $fetch, 'items', $option_key, $process );

		// Bluesky streams newest-first; paginate processes oldest-first so a
		// reply's parent is synced before the reply that targets it.
		$this->assertSame( array( 'at://a/3', 'at://a/2', 'at://a/1' ), $seen );
		$this->assertSame( 'at://a/1', \get_option( $option_key ) );
	}

	/**
	 * Test that paginate() re-walks WATERMARK_GRACE items past the last
	 * seen URI so transient drops from a prior run get a retry.
	 */
	public function test_paginate_rewalks_grace_window_past_watermark() {
		$option_key = 'atmosphere_test_paginate_grace';
		\update_option( $option_key, 'at://a/4', false );

		$items = array();
		for ( $i = 1; $i <= 16; $i++ ) {
			$items[] = array( 'uri' => 'at://a/' . $i );
		}

		$fetch = static fn() => array( 'items' => $items );

		$seen    = array();
		$process = static function ( array $item ) use ( &$seen ) {
			$seen[] = $item['uri'];
		};

		$this->invoke_paginate( $fetch, 'items', $option_key, $process );

		// 3 items before the watermark, the watermark itself, and
		// WATERMARK_GRACE (10) items strictly past it = 14 processed.
		$expected = array(
			'at://a/1',
			'at://a/2',
			'at://a/3',
			'at://a/4',
			'at://a/5',
			'at://a/6',
			'at://a/7',
			'at://a/8',
			'at://a/9',
			'at://a/10',
			'at://a/11',
			'at://a/12',
			'at://a/13',
			'at://a/14',
		);
		// $expected lists the collected set in stream order; paginate
		// processes it oldest-first, so assert against the reverse.
		$this->assertSame( \array_reverse( $expected ), $seen );
		$this->assertSame( 'at://a/1', \get_option( $option_key ) );
	}

	/**
	 * Test that the grace window spans the page boundary — if the
	 * watermark is near the end of page 1, paginate fetches page 2 to
	 * finish the re-walk, then stops.
	 */
	public function test_paginate_grace_window_spans_pages() {
		$option_key = 'atmosphere_test_paginate_grace_pages';
		\update_option( $option_key, 'at://a/4', false );

		$pages = array(
			array(
				'items'  => array(
					array( 'uri' => 'at://a/1' ),
					array( 'uri' => 'at://a/2' ),
					array( 'uri' => 'at://a/3' ),
					array( 'uri' => 'at://a/4' ),
					array( 'uri' => 'at://a/5' ),
				),
				'cursor' => 'next',
			),
			array(
				'items' => array(
					array( 'uri' => 'at://a/6' ),
					array( 'uri' => 'at://a/7' ),
					array( 'uri' => 'at://a/8' ),
					array( 'uri' => 'at://a/9' ),
					array( 'uri' => 'at://a/10' ),
					array( 'uri' => 'at://a/11' ),
					array( 'uri' => 'at://a/12' ),
					array( 'uri' => 'at://a/13' ),
					array( 'uri' => 'at://a/14' ),
					array( 'uri' => 'at://a/15' ),
				),
			),
		);

		$fetch = static fn( ?string $cursor ) => null === $cursor ? $pages[0] : $pages[1];

		$seen    = array();
		$process = static function ( array $item ) use ( &$seen ) {
			$seen[] = $item['uri'];
		};

		$this->invoke_paginate( $fetch, 'items', $option_key, $process );

		$expected = array(
			'at://a/1',
			'at://a/2',
			'at://a/3',
			'at://a/4',
			'at://a/5',
			'at://a/6',
			'at://a/7',
			'at://a/8',
			'at://a/9',
			'at://a/10',
			'at://a/11',
			'at://a/12',
			'at://a/13',
			'at://a/14',
		);
		// $expected lists the collected set in stream order; paginate
		// processes it oldest-first, so assert against the reverse.
		$this->assertSame( \array_reverse( $expected ), $seen );
		$this->assertSame( 'at://a/1', \get_option( $option_key ) );
	}

	/**
	 * If the page budget runs out while the grace window is still armed, the
	 * run must count as complete — saving a continuation would resume past
	 * the watermark, where no older item can ever match it again, and walk
	 * the account's entire remaining history.
	 */
	public function test_paginate_completes_when_page_budget_ends_inside_grace() {
		$option_key = 'atmosphere_test_paginate_budget_grace';
		\update_option( $option_key, 'at://a/13', false );

		$pages = array();
		for ( $page = 0; $page < 5; $page++ ) {
			$items = array();
			for ( $i = 1; $i <= 3; $i++ ) {
				$items[] = array( 'uri' => 'at://a/' . ( $page * 3 + $i ) );
			}
			$pages[] = array(
				'items'  => $items,
				'cursor' => 'cursor-' . ( $page + 1 ),
			);
		}

		$fetch = static function ( ?string $cursor ) use ( $pages ) {
			if ( null === $cursor ) {
				return $pages[0];
			}

			return $pages[ (int) \substr( $cursor, 7 ) ];
		};

		$seen    = array();
		$process = static function ( array $item ) use ( &$seen ) {
			$seen[] = $item['uri'];
		};

		$this->invoke_paginate( $fetch, 'items', $option_key, $process );

		/*
		 * The watermark (13th item) arms the grace window on the final page;
		 * only two items past it fit the five-page budget. All 15 items are
		 * processed, the watermark advances, and no continuation is saved.
		 */
		$this->assertCount( 15, $seen );
		$this->assertSame( 'at://a/1', \get_option( $option_key ) );
		$this->assertSame( array(), \get_option( 'atmosphere_reaction_sync_pagination', array() ) );
	}

	/**
	 * Test that paginate() stops cleanly if fewer items than
	 * WATERMARK_GRACE remain after the watermark is hit.
	 */
	public function test_paginate_stops_when_stream_runs_out_inside_grace() {
		$option_key = 'atmosphere_test_paginate_short';
		\update_option( $option_key, 'at://a/3', false );

		$fetch = static fn() => array(
			'items' => array(
				array( 'uri' => 'at://a/1' ),
				array( 'uri' => 'at://a/2' ),
				array( 'uri' => 'at://a/3' ),
				array( 'uri' => 'at://a/4' ),
			),
		);

		$seen    = array();
		$process = static function ( array $item ) use ( &$seen ) {
			$seen[] = $item['uri'];
		};

		$this->invoke_paginate( $fetch, 'items', $option_key, $process );

		// Processed oldest-first (reverse of the newest-first stream).
		$this->assertSame( array( 'at://a/4', 'at://a/3', 'at://a/2', 'at://a/1' ), $seen );
		$this->assertSame( 'at://a/1', \get_option( $option_key ) );
	}

	/**
	 * A notification burst beyond MAX_PAGES must resume from its saved cursor
	 * instead of advancing the watermark past the unprocessed tail.
	 *
	 * The completed range is replayed once so a child from the first (newer)
	 * chunk can resolve after its parent arrives in the second (older) chunk.
	 */
	public function test_paginate_resumes_overflow_and_replays_for_cross_chunk_parents() {
		$option_key    = 'atmosphere_test_paginate_overflow';
		$old_watermark = 'at://a/old-watermark';
		$parent_uri    = 'at://a/parent';
		$child_uri     = 'at://a/child';

		\update_option( $option_key, $old_watermark, false );

		$pages = array(
			'start'  => array(
				'items'  => array( array( 'uri' => $child_uri ) ),
				'cursor' => 'page-2',
			),
			'page-2' => array(
				'items'  => array( array( 'uri' => 'at://a/filler-2' ) ),
				'cursor' => 'page-3',
			),
			'page-3' => array(
				'items'  => array( array( 'uri' => 'at://a/filler-3' ) ),
				'cursor' => 'page-4',
			),
			'page-4' => array(
				'items'  => array( array( 'uri' => 'at://a/filler-4' ) ),
				'cursor' => 'page-5',
			),
			'page-5' => array(
				'items'  => array( array( 'uri' => 'at://a/filler-5' ) ),
				'cursor' => 'page-6',
			),
			'page-6' => array(
				'items' => array(
					array( 'uri' => $parent_uri ),
					array( 'uri' => $old_watermark ),
				),
			),
		);

		$fetched = array();
		$fetch   = static function ( ?string $cursor ) use ( $pages, &$fetched ) {
			$key       = $cursor ?? 'start';
			$fetched[] = $key;

			return $pages[ $key ];
		};

		$present = array( $old_watermark => true );
		$process = static function ( array $item ) use ( $parent_uri, $child_uri, &$present ) {
			$uri = $item['uri'];

			if ( $parent_uri === $uri ) {
				$present[ $uri ] = true;
				return;
			}

			if ( $child_uri === $uri && isset( $present[ $parent_uri ] ) ) {
				$present[ $uri ] = true;
			}
		};

		// First chunk: child is attempted before the older parent exists.
		$this->invoke_paginate( $fetch, 'items', $option_key, $process );
		$this->assertArrayNotHasKey( $child_uri, $present );
		$this->assertSame( $old_watermark, \get_option( $option_key ) );
		$this->assertSame( 1, \get_option( 'atmosphere_reaction_sync_pagination' )[ $option_key ]['phase'] );

		// Finish the first pass: parent is imported, watermark still waits.
		$this->invoke_paginate( $fetch, 'items', $option_key, $process );
		$this->assertArrayHasKey( $parent_uri, $present );
		$this->assertArrayNotHasKey( $child_uri, $present );
		$this->assertSame( 2, \get_option( 'atmosphere_reaction_sync_pagination' )[ $option_key ]['phase'] );

		// Replay spans the same cap, then finishes from its saved cursor.
		$this->invoke_paginate( $fetch, 'items', $option_key, $process );
		$this->assertArrayHasKey( $child_uri, $present );
		$this->assertSame( $old_watermark, \get_option( $option_key ) );

		$this->invoke_paginate( $fetch, 'items', $option_key, $process );
		$this->assertSame( $child_uri, \get_option( $option_key ) );
		$this->assertFalse( \get_option( 'atmosphere_reaction_sync_pagination', false ) );
		$this->assertSame(
			array(
				'start',
				'page-2',
				'page-3',
				'page-4',
				'page-5',
				'page-6',
				'start',
				'page-2',
				'page-3',
				'page-4',
				'page-5',
				'page-6',
			),
			$fetched
		);
	}

	/**
	 * A continuation cursor from another connected DID must never be reused.
	 */
	public function test_paginate_discards_continuation_from_another_did() {
		$option_key = 'atmosphere_test_paginate_did';

		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:new' ), false );
		\update_option( $option_key, 'at://old/watermark', false );
		\update_option(
			'atmosphere_reaction_sync_pagination',
			array(
				$option_key => array(
					'did'       => 'did:plc:old',
					'cursor'    => 'old-cursor',
					'newest'    => 'at://old/newest',
					'last_seen' => 'at://old/watermark',
					'phase'     => 1,
					'failures'  => 0,
				),
			),
			false
		);

		$fetched_cursor = 'not-called';
		$fetch          = static function ( ?string $cursor ) use ( &$fetched_cursor ) {
			$fetched_cursor = $cursor;

			return array(
				'items' => array(
					array( 'uri' => 'at://new/newest' ),
					array( 'uri' => 'at://old/watermark' ),
				),
			);
		};

		$this->invoke_paginate( $fetch, 'items', $option_key, static function () {} );

		$this->assertNull( $fetched_cursor );
		$this->assertSame( 'at://new/newest', \get_option( $option_key ) );
		$this->assertFalse( \get_option( 'atmosphere_reaction_sync_pagination', false ) );

		\delete_option( 'atmosphere_identity' );
	}

	/**
	 * Repeated failures on a saved cursor restart safely without advancing the
	 * old watermark, so an expired server cursor cannot stall sync forever.
	 */
	public function test_paginate_discards_cursor_after_repeated_failures() {
		$option_key = 'atmosphere_test_paginate_failed_cursor';

		\update_option( $option_key, 'at://a/old', false );
		\update_option(
			'atmosphere_reaction_sync_pagination',
			array(
				$option_key => array(
					'did'       => '',
					'cursor'    => 'expired-cursor',
					'newest'    => 'at://a/new',
					'last_seen' => 'at://a/old',
					'phase'     => 1,
					'failures'  => 2,
				),
			),
			false
		);

		$this->invoke_paginate(
			static fn() => new \WP_Error( 'expired_cursor', 'Cursor expired.' ),
			'items',
			$option_key,
			static function () {}
		);

		$this->assertSame( 'at://a/old', \get_option( $option_key ) );
		$this->assertFalse( \get_option( 'atmosphere_reaction_sync_pagination', false ) );

		$fetched_cursor = 'not-called';
		$fetch          = static function ( ?string $cursor ) use ( &$fetched_cursor ) {
			$fetched_cursor = $cursor;

			return array(
				'items' => array(
					array( 'uri' => 'at://a/new' ),
					array( 'uri' => 'at://a/old' ),
				),
			);
		};

		$this->invoke_paginate( $fetch, 'items', $option_key, static function () {} );

		$this->assertNull( $fetched_cursor );
		$this->assertSame( 'at://a/new', \get_option( $option_key ) );
	}

	/**
	 * Switching the connected account clears repository-specific watermarks.
	 */
	public function test_prepare_account_state_clears_previous_did_watermarks() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:new' ), false );
		\update_option( 'atmosphere_reaction_sync_did', 'did:plc:old', false );
		\update_option( 'atmosphere_last_seen_notification', 'at://old/notification', false );
		\update_option( 'atmosphere_last_seen_own_like', 'at://old/like', false );
		\update_option( 'atmosphere_last_seen_own_repost', 'at://old/repost', false );
		\update_option( 'atmosphere_last_seen_own_post', 'at://old/post', false );
		\update_option( 'atmosphere_reaction_sync_pagination', array( 'old' => array() ), false );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'prepare_account_state' );
		$method->invoke( null );

		$this->assertSame( 'did:plc:new', \get_option( 'atmosphere_reaction_sync_did' ) );
		$this->assertFalse( \get_option( 'atmosphere_last_seen_notification', false ) );
		$this->assertFalse( \get_option( 'atmosphere_last_seen_own_like', false ) );
		$this->assertFalse( \get_option( 'atmosphere_last_seen_own_repost', false ) );
		$this->assertFalse( \get_option( 'atmosphere_last_seen_own_post', false ) );
		$this->assertFalse( \get_option( 'atmosphere_reaction_sync_pagination', false ) );

		\delete_option( 'atmosphere_identity' );
	}

	/**
	 * The reaction lock must reclaim stale rows and only release its own lease.
	 */
	public function test_reaction_lock_reclaims_stale_row_without_deleting_successor() {
		\update_option(
			'_atmosphere_reaction_sync_lock',
			(string) \wp_json_encode(
				array(
					'expires_at' => \time() - 1,
					'token'      => 'stale-worker',
				)
			),
			false
		);

		$lock   = new \ReflectionMethod( Reaction_Sync::class, 'lock' );
		$unlock = new \ReflectionMethod( Reaction_Sync::class, 'unlock' );

		$this->assertTrue( $lock->invoke( null ) );

		$successor = (string) \wp_json_encode(
			array(
				'expires_at' => \time() + 300,
				'token'      => 'successor-worker',
			)
		);

		\update_option( '_atmosphere_reaction_sync_lock', $successor, false );
		$unlock->invoke( null );

		$this->assertSame( $successor, \get_option( '_atmosphere_reaction_sync_lock' ) );
	}

	/**
	 * A targeted backfill must fail before HTTP when another sync owns the lock.
	 */
	public function test_backfill_replies_respects_active_reaction_lock() {
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:me' ), false );
		\update_option( 'atmosphere_connection', array( 'access_token' => 'test-token' ), false );
		\update_option(
			'_atmosphere_reaction_sync_lock',
			(string) \wp_json_encode(
				array(
					'expires_at' => \time() + 300,
					'token'      => 'other-worker',
				)
			),
			false
		);

		$fetched  = false;
		$tripwire = static function ( $response ) use ( &$fetched ) {
			$fetched = true;
			return $response;
		};

		\add_filter( 'pre_http_request', $tripwire );

		try {
			$result = Reaction_Sync::backfill_replies( 1 );
		} finally {
			\remove_filter( 'pre_http_request', $tripwire );
			\delete_option( 'atmosphere_connection' );
			\delete_option( 'atmosphere_identity' );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_reaction_sync_locked', $result->get_error_code() );
		$this->assertFalse( $fetched );
	}

	/**
	 * The rolling selector prioritizes posts never checked before filling the
	 * batch with the least-recently checked posts.
	 */
	public function test_get_backfill_post_ids_prioritizes_unchecked_then_oldest_checked() {
		$post_ids = self::factory()->post->create_many(
			5,
			array(
				'post_status' => 'publish',
			)
		);

		foreach ( $post_ids as $index => $post_id ) {
			\update_post_meta(
				$post_id,
				BskyPost::META_URI,
				'at://did:plc:me/app.bsky.feed.post/rolling-' . $index
			);
		}

		\update_post_meta( $post_ids[0], '_atmosphere_reply_backfill_checked_at', 200 );
		\update_post_meta( $post_ids[1], '_atmosphere_reply_backfill_checked_at', 100 );
		\update_post_meta( $post_ids[4], '_atmosphere_reply_backfill_checked_at', 50 );

		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		\update_post_meta( $draft_id, BskyPost::META_URI, 'at://did:plc:me/app.bsky.feed.post/draft' );

		$protected_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		\update_post_meta( $protected_id, BskyPost::META_URI, 'at://did:plc:me/app.bsky.feed.post/protected' );

		/*
		 * An empty root-URI meta can never backfill; it must not be
		 * selected, or it would burn a batch slot on every rotation.
		 */
		$empty_uri_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $empty_uri_id, BskyPost::META_URI, '' );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'get_backfill_post_ids' );
		$actual = $method->invoke( null, 4 );

		$this->assertSame(
			array(
				$post_ids[2],
				$post_ids[3],
				$post_ids[4],
				$post_ids[1],
			),
			$actual
		);
	}

	/**
	 * The scheduled worker checks only its configured batch, timestamps every
	 * attempt, and starts the next run with the remaining unchecked post.
	 */
	public function test_scheduled_backfill_runs_bounded_rolling_batches() {
		$post_ids = self::factory()->post->create_many(
			3,
			array(
				'post_status' => 'publish',
			)
		);
		$uris     = array();

		foreach ( $post_ids as $index => $post_id ) {
			$uris[ $post_id ] = 'at://did:plc:me/app.bsky.feed.post/scheduled-' . $index;
			\update_post_meta( $post_id, BskyPost::META_URI, $uris[ $post_id ] );
		}

		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:me' ), false );
		\update_option( 'atmosphere_connection', array( 'access_token' => 'test-token' ), false );
		\add_filter( 'atmosphere_reply_backfill_batch_size', static fn() => 2 );

		$captured = array();
		$http     = function ( $response, $args, $url ) use ( &$captured ) {
			if ( false === \strpos( $url, 'public.api.bsky.app/xrpc/app.bsky.feed.getPostThread' ) ) {
				return $response;
			}

			$query = array();
			\parse_str( (string) \wp_parse_url( $url, \PHP_URL_QUERY ), $query );

			$uri        = (string) ( $query['uri'] ?? '' );
			$captured[] = $uri;

			return $this->mock_thread_response(
				array(
					'$type'   => 'app.bsky.feed.defs#threadViewPost',
					'post'    => array( 'uri' => $uri ),
					'replies' => array(),
				)
			);
		};

		\add_filter( 'pre_http_request', $http, 10, 3 );

		try {
			Reaction_Sync::backfill_scheduled_replies();

			$this->assertSame( array( $uris[ $post_ids[0] ], $uris[ $post_ids[1] ] ), $captured );
			$this->assertGreaterThan( 0, (int) \get_post_meta( $post_ids[0], '_atmosphere_reply_backfill_checked_at', true ) );
			$this->assertGreaterThan( 0, (int) \get_post_meta( $post_ids[1], '_atmosphere_reply_backfill_checked_at', true ) );
			$this->assertSame( '', \get_post_meta( $post_ids[2], '_atmosphere_reply_backfill_checked_at', true ) );
			$this->assertFalse( \get_option( '_atmosphere_reaction_sync_lock', false ) );

			$captured = array();
			Reaction_Sync::backfill_scheduled_replies();

			$this->assertSame( $uris[ $post_ids[2] ], $captured[0] ?? '' );
			$this->assertCount( 2, $captured );
			$this->assertGreaterThan( 0, (int) \get_post_meta( $post_ids[2], '_atmosphere_reply_backfill_checked_at', true ) );
			$this->assertFalse( \get_option( '_atmosphere_reaction_sync_lock', false ) );
		} finally {
			\remove_filter( 'pre_http_request', $http, 10 );
			\delete_option( 'atmosphere_connection' );
			\delete_option( 'atmosphere_identity' );
		}
	}

	/**
	 * A targeted thread backfill imports missed parents before their children
	 * and remains idempotent when the command is repeated.
	 */
	public function test_import_thread_replies_recovers_nested_replies_idempotently() {
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$root_uri   = 'at://did:plc:me/app.bsky.feed.post/root';
		$parent_uri = 'at://did:plc:alice/app.bsky.feed.post/parent';
		$child_uri  = 'at://did:plc:bob/app.bsky.feed.post/child';

		\update_post_meta( $post_id, BskyPost::META_URI, $root_uri );
		\set_transient( 'atmosphere_profile_' . \md5( 'did:plc:alice' ), array( 'handle' => 'alice.test' ), \HOUR_IN_SECONDS );
		\set_transient( 'atmosphere_profile_' . \md5( 'did:plc:bob' ), array( 'handle' => 'bob.test' ), \HOUR_IN_SECONDS );

		$thread = array(
			'$type'   => 'app.bsky.feed.defs#threadViewPost',
			'post'    => array( 'uri' => $root_uri ),
			'replies' => array(
				array(
					'$type'   => 'app.bsky.feed.defs#threadViewPost',
					'post'    => array(
						'uri'    => $parent_uri,
						'cid'    => 'bafyparent',
						'author' => array(
							'did'    => 'did:plc:alice',
							'handle' => 'alice.test',
						),
						'record' => array(
							'text'      => 'Parent reply.',
							'createdAt' => '2026-07-10T12:00:00.000Z',
							'reply'     => array(
								'root'   => array( 'uri' => $root_uri ),
								'parent' => array( 'uri' => $root_uri ),
							),
						),
					),
					'replies' => array(
						array(
							'$type' => 'app.bsky.feed.defs#threadViewPost',
							'post'  => array(
								'uri'    => $child_uri,
								'cid'    => 'bafychild',
								'author' => array(
									'did'    => 'did:plc:bob',
									'handle' => 'bob.test',
								),
								'record' => array(
									'text'      => 'Child reply.',
									'createdAt' => '2026-07-10T11:55:00.000Z',
									'reply'     => array(
										'root'   => array( 'uri' => $root_uri ),
										'parent' => array( 'uri' => $parent_uri ),
									),
								),
							),
						),
					),
				),
			),
		);

		$method = new \ReflectionMethod( Reaction_Sync::class, 'import_thread_replies' );
		$first  = $method->invoke( null, $root_uri, $thread );

		$this->assertSame(
			array(
				'found'    => 2,
				'imported' => 2,
				'existing' => 0,
				'skipped'  => 0,
				'pending'  => 2,
			),
			$first
		);

		$parent_id = $this->find_comment_id_by_source_uri( $parent_uri );
		$child_id  = $this->find_comment_id_by_source_uri( $child_uri );

		$this->assertIsInt( $parent_id );
		$this->assertIsInt( $child_id );
		$this->assertSame( (string) $parent_id, \get_comment( $child_id )->comment_parent );

		$second = $method->invoke( null, $root_uri, $thread );
		$this->assertSame(
			array(
				'found'    => 2,
				'imported' => 0,
				'existing' => 2,
				'skipped'  => 0,
				'pending'  => 0,
			),
			$second
		);
	}

	/**
	 * Replies the admin moderated away must not be re-imported by a later
	 * thread audit — spammed and trashed comments stay visible to dedup.
	 */
	public function test_import_thread_replies_does_not_resurrect_moderated_replies() {
		$post_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$root_uri  = 'at://did:plc:me/app.bsky.feed.post/root-moderated';
		$spam_uri  = 'at://did:plc:alice/app.bsky.feed.post/spammed';
		$trash_uri = 'at://did:plc:alice/app.bsky.feed.post/trashed';

		\update_post_meta( $post_id, BskyPost::META_URI, $root_uri );
		\set_transient( 'atmosphere_profile_' . \md5( 'did:plc:alice' ), array( 'handle' => 'alice.test' ), \HOUR_IN_SECONDS );

		$reply = static function ( string $uri, string $text ) use ( $root_uri ): array {
			return array(
				'$type' => 'app.bsky.feed.defs#threadViewPost',
				'post'  => array(
					'uri'    => $uri,
					'cid'    => 'bafy' . \md5( $uri ),
					'author' => array(
						'did'    => 'did:plc:alice',
						'handle' => 'alice.test',
					),
					'record' => array(
						'text'      => $text,
						'createdAt' => '2026-07-10T12:00:00.000Z',
						'reply'     => array(
							'root'   => array( 'uri' => $root_uri ),
							'parent' => array( 'uri' => $root_uri ),
						),
					),
				),
			);
		};

		$thread = array(
			'$type'   => 'app.bsky.feed.defs#threadViewPost',
			'post'    => array( 'uri' => $root_uri ),
			'replies' => array(
				$reply( $spam_uri, 'Spam me.' ),
				$reply( $trash_uri, 'Trash me.' ),
			),
		);

		$method = new \ReflectionMethod( Reaction_Sync::class, 'import_thread_replies' );
		$first  = $method->invoke( null, $root_uri, $thread );

		$this->assertSame( 2, $first['imported'] );

		\wp_spam_comment( $this->find_comment_id_by_source_uri( $spam_uri ) );
		\wp_trash_comment( $this->find_comment_id_by_source_uri( $trash_uri ) );

		$second = $method->invoke( null, $root_uri, $thread );

		$this->assertSame(
			array(
				'found'    => 2,
				'imported' => 0,
				'existing' => 2,
				'skipped'  => 0,
				'pending'  => 0,
			),
			$second
		);
	}

	/**
	 * The public backfill path fetches getPostThread without requiring a new
	 * OAuth RPC permission, then imports the returned replies.
	 */
	public function test_backfill_replies_fetches_public_thread() {
		$post_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$root_uri  = 'at://did:plc:me/app.bsky.feed.post/root-public';
		$reply_uri = 'at://did:plc:alice/app.bsky.feed.post/reply-public';

		\update_post_meta( $post_id, BskyPost::META_URI, $root_uri );
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:me' ), false );
		\update_option( 'atmosphere_connection', array( 'access_token' => 'test-token' ), false );
		\set_transient( 'atmosphere_profile_' . \md5( 'did:plc:alice' ), array( 'handle' => 'alice.test' ), \HOUR_IN_SECONDS );

		$captured = array();
		$http     = function ( $response, $args, $url ) use ( $root_uri, $reply_uri, &$captured ) {
			if ( false === \strpos( $url, 'public.api.bsky.app/xrpc/app.bsky.feed.getPostThread' ) ) {
				return $response;
			}

			$captured = array(
				'args' => $args,
				'url'  => $url,
			);

			return $this->mock_thread_response(
				array(
					'$type'   => 'app.bsky.feed.defs#threadViewPost',
					'post'    => array( 'uri' => $root_uri ),
					'replies' => array(
						array(
							'$type' => 'app.bsky.feed.defs#threadViewPost',
							'post'  => array(
								'uri'    => $reply_uri,
								'cid'    => 'bafyreply',
								'author' => array(
									'did'    => 'did:plc:alice',
									'handle' => 'alice.test',
								),
								'record' => array(
									'text'      => 'Recovered reply.',
									'createdAt' => '2026-07-10T12:00:00.000Z',
									'reply'     => array(
										'root'   => array( 'uri' => $root_uri ),
										'parent' => array( 'uri' => $root_uri ),
									),
								),
							),
						),
					),
				)
			);
		};

		\add_filter( 'pre_http_request', $http, 10, 3 );

		try {
			$result = Reaction_Sync::backfill_replies( $post_id );
		} finally {
			\remove_filter( 'pre_http_request', $http, 10 );
			\delete_option( 'atmosphere_connection' );
			\delete_option( 'atmosphere_identity' );
		}

		$query = array();
		\parse_str( (string) \wp_parse_url( $captured['url'], \PHP_URL_QUERY ), $query );

		$this->assertSame(
			array(
				'found'    => 1,
				'imported' => 1,
				'existing' => 0,
				'skipped'  => 0,
				'pending'  => 1,
			),
			$result
		);
		$this->assertSame( $root_uri, $query['uri'] ?? '' );
		$this->assertSame( 120, $captured['args']['timeout'] );
		$this->assertSame( 0, $captured['args']['redirection'] );
		$this->assertIsInt( $this->find_comment_id_by_source_uri( $reply_uri ) );
	}

	/**
	 * Find a comment by the inbound source URI written by Reaction_Sync.
	 *
	 * Delegates to the production dedup query so the tests exercise the same
	 * lookup the sync itself uses.
	 *
	 * @param string $uri Source AT-URI.
	 * @return int|false Comment ID or false.
	 */
	private function find_comment_id_by_source_uri( string $uri ): int|false {
		$method = new \ReflectionMethod( Reaction_Sync::class, 'find_comment_by_source_id' );

		return $method->invoke( null, $uri );
	}

	/**
	 * Build a getPostThread HTTP response for the pre_http_request filter.
	 *
	 * @param array $thread Hydrated threadViewPost node.
	 * @return array WP HTTP API response array.
	 */
	private function mock_thread_response( array $thread ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
			'body'     => (string) \wp_json_encode( array( 'thread' => $thread ) ),
		);
	}

	/**
	 * A reply that arrives before the parent it targets — the normal case,
	 * since Bluesky streams newest-first — must still thread correctly within
	 * a single run.
	 *
	 * Before oldest-first processing, the child reply was reached first, its
	 * parent comment did not yet exist, and process_reply() dropped it — left
	 * to the next run's bounded WATERMARK_GRACE re-walk, which loses deeper or
	 * bursty threads. Processing oldest-first syncs the parent reply first, so
	 * the child resolves against it in the same run.
	 */
	public function test_nested_reply_arriving_before_parent_resolves_in_one_run() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/rootpost';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$parent_uri = 'at://did:plc:alice/app.bsky.feed.post/parentreply';
		$child_uri  = 'at://did:plc:bob/app.bsky.feed.post/childreply';

		// Keep resolve_author() off the network — cache both profiles.
		\set_transient( 'atmosphere_profile_' . \md5( 'did:plc:alice' ), array( 'handle' => 'alice.bsky.social' ), \HOUR_IN_SECONDS );
		\set_transient( 'atmosphere_profile_' . \md5( 'did:plc:bob' ), array( 'handle' => 'bob.bsky.social' ), \HOUR_IN_SECONDS );

		// Stream order is newest-first: the child reply (later) comes before
		// the parent reply (earlier) that it targets.
		$notifications = array(
			array(
				'reason' => 'reply',
				'uri'    => $child_uri,
				'cid'    => 'cidchild',
				'record' => array(
					'text'      => 'Replying to Alice',
					'createdAt' => '2026-03-21T12:05:00.000Z',
					'reply'     => array(
						'parent' => array( 'uri' => $parent_uri ),
						'root'   => array( 'uri' => $post_uri ),
					),
				),
				'author' => array(
					'did'    => 'did:plc:bob',
					'handle' => 'bob.bsky.social',
				),
			),
			array(
				'reason' => 'reply',
				'uri'    => $parent_uri,
				'cid'    => 'cidparent',
				'record' => array(
					'text'      => 'Replying to the post',
					'createdAt' => '2026-03-21T12:00:00.000Z',
					'reply'     => array(
						'parent' => array( 'uri' => $post_uri ),
						'root'   => array( 'uri' => $post_uri ),
					),
				),
				'author' => array(
					'did'    => 'did:plc:alice',
					'handle' => 'alice.bsky.social',
				),
			),
		);

		$dispatch = new \ReflectionMethod( Reaction_Sync::class, 'process_notification' );
		$process  = static function ( array $item ) use ( $dispatch ) {
			$dispatch->invoke( null, $item );
		};

		$option_key = 'atmosphere_test_nested_reply';
		\delete_option( $option_key );

		$this->invoke_paginate(
			static fn() => array( 'notifications' => $notifications ),
			'notifications',
			$option_key,
			$process
		);

		$find      = new \ReflectionMethod( Reaction_Sync::class, 'find_comment_by_source_id' );
		$parent_id = $find->invoke( null, $parent_uri );
		$child_id  = $find->invoke( null, $child_uri );

		$this->assertIsInt( $parent_id, 'parent reply synced' );
		$this->assertIsInt( $child_id, 'child reply synced in the same run' );

		$this->assertSame(
			(string) $parent_id,
			\get_comment( $child_id )->comment_parent,
			'child reply threads under the parent reply, not the root post'
		);
	}

	/**
	 * A reply whose URI matches an existing comment's source_id meta
	 * is skipped, even when that comment has no protocol='atproto'
	 * marker — the outbound publish path deliberately omits it.
	 */
	public function test_process_reply_skips_our_own_outbound_comment() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/rootpost';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		// Simulate a locally-published outbound comment: source_id set
		// by Publisher::publish_comment, protocol intentionally absent.
		$local_comment = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'user_id'         => 1,
			)
		);
		$reply_uri     = 'at://did:plc:me/app.bsky.feed.post/ourreply';
		\update_comment_meta( $local_comment, Reaction_Sync::META_SOURCE_ID, $reply_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$notification = array(
			'uri'    => $reply_uri,
			'cid'    => 'bafyownreply',
			'record' => array(
				'text'      => 'Our own outbound comment.',
				'createdAt' => '2026-04-23T10:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:me',
				'handle' => 'me.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification ) );

		// No second comment was inserted — only the local one exists.
		$comments = \get_comments( array( 'post_id' => $post_id ) );
		$this->assertCount( 1, $comments );
		$this->assertSame( (string) $local_comment, (string) $comments[0]->comment_ID );
	}

	/**
	 * With the reactions setting off, likes and reposts are not imported.
	 */
	public function test_reactions_setting_off_skips_import() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/reactionoff';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		\update_option( 'atmosphere_sync_reactions', '0' );

		$method       = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );
		$notification = array(
			'uri'    => 'at://did:plc:liker/app.bsky.feed.like/likeoff',
			'cid'    => 'bafyreilikeoff',
			'record' => array(
				'subject' => array( 'uri' => $post_uri ),
			),
			'author' => array(
				'did'    => 'did:plc:liker',
				'handle' => 'liker.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification, 'like' ) );

		// Query by type to pin the assertion at the storage layer.
		$this->assertCount(
			0,
			\get_comments(
				array(
					'post_id'  => $post_id,
					'type__in' => array( 'like', 'repost' ),
				)
			),
			'No like/repost row should be written when reactions are off.'
		);
	}

	/**
	 * With the replies setting off, replies are not imported.
	 */
	public function test_replies_setting_off_skips_import() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/replyoff';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		\update_option( 'atmosphere_sync_replies', '0' );

		$method       = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );
		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/replyoff',
			'cid'    => 'bafyreireplyoff',
			'record' => array(
				'text'  => 'Nice one',
				'reply' => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification ) );

		// Query by type to pin the assertion at the storage layer.
		$this->assertCount(
			0,
			\get_comments(
				array(
					'post_id'  => $post_id,
					'type__in' => array( 'comment' ),
				)
			),
			'No reply comment should be written when replies are off.'
		);
	}

	/**
	 * Connection-only mode forces reaction import off even with the stored
	 * setting on, so likes and reposts are skipped.
	 */
	public function test_connection_only_mode_skips_reaction_import() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/reactionconnonly';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		\update_option( 'atmosphere_sync_reactions', '1' );
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );

		$method       = new \ReflectionMethod( Reaction_Sync::class, 'process_subject_reaction' );
		$notification = array(
			'uri'    => 'at://did:plc:liker/app.bsky.feed.like/likeconnonly',
			'cid'    => 'bafyreilikeconnonly',
			'record' => array(
				'subject' => array( 'uri' => $post_uri ),
			),
			'author' => array(
				'did'    => 'did:plc:liker',
				'handle' => 'liker.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification, 'like' ) );

		$this->assertCount(
			0,
			\get_comments(
				array(
					'post_id'  => $post_id,
					'type__in' => array( 'like', 'repost' ),
				)
			),
			'No like/repost row should be written in connection-only mode.'
		);
	}

	/**
	 * Connection-only mode forces reply import off even with the stored setting
	 * on, so replies are skipped.
	 */
	public function test_connection_only_mode_skips_reply_import() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/replyconnonly';
		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		\update_option( 'atmosphere_sync_replies', '1' );
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );

		$method       = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );
		$notification = array(
			'uri'    => 'at://did:plc:replier/app.bsky.feed.post/replyconnonly',
			'cid'    => 'bafyreireplyconnonly',
			'record' => array(
				'text'  => 'Nice one',
				'reply' => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => array(
				'did'    => 'did:plc:replier',
				'handle' => 'replier.bsky.social',
			),
		);

		$this->assertFalse( $method->invoke( null, $notification ) );

		$this->assertCount(
			0,
			\get_comments(
				array(
					'post_id'  => $post_id,
					'type__in' => array( 'comment' ),
				)
			),
			'No reply comment should be written in connection-only mode.'
		);
	}

	/**
	 * A connected fixture whose access token and DPoP key decrypt cleanly, so
	 * sync() can get past is_connected()/access_token() to the PDS fetch.
	 */
	private function connect_site_for_sync(): void {
		\update_option(
			'atmosphere_connection',
			array(
				'access_token' => Encryption::encrypt( 'test-token' ),
				'did'          => 'did:plc:me',
				'handle'       => 'me.example.com',
				'pds_endpoint' => 'https://pds.example.com',
				'dpop_jwk'     => Encryption::encrypt( (string) \wp_json_encode( DPoP::generate_key() ) ),
				'expires_at'   => \time() + HOUR_IN_SECONDS,
			)
		);
		\update_option(
			'atmosphere_identity',
			array(
				'did'          => 'did:plc:me',
				'handle'       => 'me.example.com',
				'pds_endpoint' => 'https://pds.example.com',
			),
			true
		);
	}

	/**
	 * Capture the URL of any outgoing HTTP request and answer it with an empty,
	 * well-formed response so paginate() completes cleanly.
	 *
	 * @param string $captured_url Set to the requested URL by reference.
	 */
	private function spy_on_http( string &$captured_url ): void {
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$captured_url ) {
				$captured_url = (string) $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) \wp_json_encode(
						array(
							'notifications' => array(),
							'records'       => array(),
						)
					),
					'headers'  => array(),
				);
			},
			5,
			3
		);
	}

	/**
	 * Positive control: with a live connection and connection-only mode off,
	 * sync() reaches out to the PDS. Proves the fixture and the HTTP spy are
	 * wired, so the negative test below is meaningful rather than passing on an
	 * earlier bail.
	 */
	public function test_sync_polls_the_pds_when_not_connection_only() {
		$this->connect_site_for_sync();

		$requested_url = '';
		$this->spy_on_http( $requested_url );

		Reaction_Sync::sync();

		$this->assertNotSame( '', $requested_url, 'sync() should poll the PDS when not in connection-only mode.' );
	}

	/**
	 * Regression: connection-only mode short-circuits sync() before any PDS
	 * call, so a host embedding ATmosphere purely as a connection layer gets no
	 * hourly background polling.
	 */
	public function test_connection_only_mode_skips_pds_polling() {
		$this->connect_site_for_sync();
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );

		$requested_url = '';
		$this->spy_on_http( $requested_url );

		Reaction_Sync::sync();

		$this->assertSame( '', $requested_url, 'sync() must not touch the PDS in connection-only mode.' );
	}

	/**
	 * Regression: connection-only mode forces the sync lanes off by default, but
	 * the `atmosphere_should_sync_*` filters run last and can re-enable one. When
	 * a host does, sync() must still poll — the early bail defers to the
	 * per-feature helpers, so it no longer short-circuits on raw connection-only
	 * mode alone.
	 */
	public function test_connection_only_mode_reenabled_lane_still_polls() {
		$this->connect_site_for_sync();
		\add_filter( 'atmosphere_connection_only_mode', '__return_true' );
		\add_filter( 'atmosphere_should_sync_reactions', '__return_true' );

		$requested_url = '';
		$this->spy_on_http( $requested_url );

		Reaction_Sync::sync();

		$this->assertNotSame(
			'',
			$requested_url,
			'sync() should poll the PDS when a lane is re-enabled via the atmosphere_should_sync_* filter, even in connection-only mode.'
		);
	}

	/**
	 * Regression: a regular site (not connection-only) that unchecks BOTH sync
	 * toggles must still poll, so the per-item gates skip writes while the
	 * watermarks advance. Bailing early here — as a broader `! reactions && !
	 * replies` gate would — froze the watermarks, so re-enabling a toggle later
	 * replayed the whole off-period backlog as brand-new comments.
	 */
	public function test_sync_still_polls_with_both_toggles_off_off_connection_only() {
		$this->connect_site_for_sync();
		\update_option( 'atmosphere_sync_reactions', '' );
		\update_option( 'atmosphere_sync_replies', '' );

		$requested_url = '';
		$this->spy_on_http( $requested_url );

		Reaction_Sync::sync();

		$this->assertNotSame(
			'',
			$requested_url,
			'sync() must still poll on a regular site with both toggles off, so the off period stays skipped-for-good rather than replayed on re-enable.'
		);
	}

	/**
	 * Build a minimal reply notification aimed at a cross-posted post.
	 *
	 * @param string $post_uri  AT-URI of the local post being replied to.
	 * @param string $reply_uri AT-URI of the reply itself.
	 * @param array  $author    Author block for the notification payload.
	 * @return array
	 */
	private function reply_notification( string $post_uri, string $reply_uri, array $author ): array {
		return array(
			'uri'    => $reply_uri,
			'cid'    => 'bafyreixss',
			'record' => array(
				'text'      => 'Nice post.',
				'createdAt' => '2026-08-21T12:00:00.000Z',
				'reply'     => array(
					'parent' => array( 'uri' => $post_uri ),
					'root'   => array( 'uri' => $post_uri ),
				),
			),
			'author' => $author,
		);
	}

	/**
	 * Remote profile fields are attacker-controlled: anyone with an account
	 * on the network can set their own `displayName`. resolve_author() must
	 * strip markup before the value is cached or handed to
	 * insert_reaction().
	 */
	public function test_resolve_author_sanitizes_remote_profile_fields() {
		$did      = 'did:plc:mallory';
		$dpop_jwk = DPoP::generate_key();

		\update_option(
			'atmosphere_connection',
			array(
				'access_token'   => Encryption::encrypt( 'test-access-token' ),
				'refresh_token'  => Encryption::encrypt( 'test-refresh-token' ),
				'dpop_jwk'       => Encryption::encrypt( (string) \wp_json_encode( $dpop_jwk ) ),
				'did'            => 'did:plc:me',
				'pds_endpoint'   => 'https://pds.example.com',
				'token_endpoint' => 'https://auth.example.com/oauth/token',
				'expires_at'     => \time() + 3600,
				'needs_reauth'   => false,
			)
		);

		$http = static function ( $response, $args, $url ) {
			if ( false === \strpos( $url, 'app.bsky.actor.getProfile' ) ) {
				return $response;
			}

			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
				'body'     => (string) \wp_json_encode(
					array(
						'did'         => 'did:plc:mallory',
						'handle'      => 'mallory.test',
						'displayName' => '<img src=x onerror=alert(document.domain)>Mallory',
						'avatar'      => 'javascript:alert(document.domain)',
					)
				),
			);
		};

		\add_filter( 'pre_http_request', $http, 10, 3 );

		try {
			$method  = new \ReflectionMethod( Reaction_Sync::class, 'resolve_author' );
			$profile = $method->invoke( null, $did );
		} finally {
			\remove_filter( 'pre_http_request', $http, 10 );
		}

		$this->assertSame( 'Mallory', $profile['name'] );
		$this->assertSame( 'mallory.test', $profile['handle'] );
		$this->assertSame( '', $profile['avatar'], 'A javascript: avatar URL must not survive esc_url_raw().' );

		$this->assertSame(
			$profile,
			\get_transient( 'atmosphere_profile_' . \md5( $did ) ),
			'The cached profile must hold the sanitized values, not the raw getProfile response.'
		);
	}

	/**
	 * The sink sanitizes too, not just the boundary: `comment_author` is
	 * written with wp_insert_comment(), which runs none of the
	 * `pre_comment_*` filters, and core's get_comment_author_link()
	 * interpolates the stored column into an <a> with no escaping — on the
	 * front end via Walker_Comment and in the wp-admin Dashboard "Activity"
	 * widget, which renders comments still held for moderation.
	 *
	 * A profile cached before the boundary sanitizer existed still has to be
	 * neutralised on the way into wp_comments.
	 */
	public function test_process_reply_stores_sanitized_comment_author() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/xsspost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		\set_transient(
			'atmosphere_profile_' . \md5( 'did:plc:mallory' ),
			array(
				'name'   => '<img src=x onerror=alert(document.domain)>Mallory',
				'handle' => 'mallory.test',
			),
			\HOUR_IN_SECONDS
		);

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$comment_id = $method->invoke(
			null,
			$this->reply_notification(
				$post_uri,
				'at://did:plc:mallory/app.bsky.feed.post/xssreply',
				array(
					'did'    => 'did:plc:mallory',
					'handle' => 'mallory.test',
				)
			)
		);

		$this->assertIsInt( $comment_id );

		$comment = \get_comment( $comment_id );

		$this->assertSame( 'Mallory', $comment->comment_author );
		$this->assertStringNotContainsString( '<', $comment->comment_author );
	}

	/**
	 * A display name made up entirely of markup sanitizes down to an empty
	 * string. Store the handle rather than a nameless author, which core
	 * would render as "Anonymous".
	 */
	public function test_process_reply_falls_back_to_handle_when_display_name_is_all_markup() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/fallbackpost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		\set_transient(
			'atmosphere_profile_' . \md5( 'did:plc:mallory' ),
			array(
				'name'   => '<script>alert(document.domain)</script>',
				'handle' => 'mallory.test',
			),
			\HOUR_IN_SECONDS
		);

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$comment_id = $method->invoke(
			null,
			$this->reply_notification(
				$post_uri,
				'at://did:plc:mallory/app.bsky.feed.post/fallbackreply',
				array(
					'did'    => 'did:plc:mallory',
					'handle' => 'mallory.test',
				)
			)
		);

		$this->assertSame( 'mallory.test', \get_comment( $comment_id )->comment_author );
	}

	/**
	 * When profile resolution fails the author name falls back to the handle
	 * carried on the notification payload, which is just as untrusted as the
	 * getProfile response and must be sanitized on that path too.
	 */
	public function test_process_reply_sanitizes_handle_from_notification_payload() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/payloadpost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		/*
		 * No cached profile and no connection, so resolve_author() bails
		 * without a network call and the payload handle is all we have.
		 */
		$comment_id = $method->invoke(
			null,
			$this->reply_notification(
				$post_uri,
				'at://did:plc:mallory/app.bsky.feed.post/payloadreply',
				array(
					'did'    => 'did:plc:mallory',
					'handle' => '<img src=x onerror=alert(document.domain)>mallory.test',
				)
			)
		);

		$this->assertSame( 'mallory.test', \get_comment( $comment_id )->comment_author );
	}

	/**
	 * An ordinary display name containing `&` must be stored HTML-encoded,
	 * the way core's own comment pipeline stores it. The column is read
	 * unescaped by the XML feed templates, so a raw `&` makes
	 * /comments/feed/ non-well-formed for every consumer.
	 */
	public function test_process_reply_stores_author_name_encoded_like_core() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/ampersandpost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		\set_transient(
			'atmosphere_profile_' . \md5( 'did:plc:mallory' ),
			array(
				'name'   => 'Rock & Roll',
				'handle' => 'mallory.test',
			),
			\HOUR_IN_SECONDS
		);

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$comment_id = $method->invoke(
			null,
			$this->reply_notification(
				$post_uri,
				'at://did:plc:mallory/app.bsky.feed.post/ampersandreply',
				array(
					'did'    => 'did:plc:mallory',
					'handle' => 'mallory.test',
				)
			)
		);

		$stored = \get_comment( $comment_id )->comment_author;

		$this->assertSame( 'Rock &amp; Roll', $stored );
		$this->assertSame(
			\_wp_specialchars( 'Rock & Roll' ),
			$stored,
			'Imported names must use the same storage format as core.'
		);
	}

	/**
	 * The stored name has to survive the XML feed templates, which drop it
	 * into element content and a CDATA section with no escaping of their
	 * own. `]]>` is the deliberate-abuse version of the `&` case above.
	 */
	public function test_process_reply_stores_feed_safe_author_name() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/cdatapost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		\set_transient(
			'atmosphere_profile_' . \md5( 'did:plc:mallory' ),
			array(
				'name'   => ']]> pwned & <broken',
				'handle' => 'mallory.test',
			),
			\HOUR_IN_SECONDS
		);

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$comment_id = $method->invoke(
			null,
			$this->reply_notification(
				$post_uri,
				'at://did:plc:mallory/app.bsky.feed.post/cdatareply',
				array(
					'did'    => 'did:plc:mallory',
					'handle' => 'mallory.test',
				)
			)
		);

		$stored = \get_comment( $comment_id )->comment_author;

		$this->assertStringNotContainsString( ']]>', $stored );

		// Both shapes the comment feed templates use must stay well-formed.
		$element = '<?xml version="1.0" encoding="UTF-8"?><t>' . $stored . '</t>';
		$cdata   = '<?xml version="1.0" encoding="UTF-8"?><t><![CDATA[' . $stored . ']]></t>';

		foreach ( array(
			'element content' => $element,
			'CDATA'           => $cdata,
		) as $shape => $xml ) {
			$previous = \libxml_use_internal_errors( true );
			\libxml_clear_errors();
			$parsed = \simplexml_load_string( $xml );
			$errors = \libxml_get_errors();
			\libxml_clear_errors();
			\libxml_use_internal_errors( $previous );

			$this->assertNotFalse(
				$parsed,
				\sprintf(
					'Stored author name must be well-formed in %s: %s',
					$shape,
					$errors ? \trim( $errors[0]->message ) : ''
				)
			);
		}
	}

	/**
	 * An empty handle on the resolved profile has to fall through to the
	 * notification payload. `??` does not do that, since resolve_author()
	 * always sets the key.
	 */
	public function test_process_reply_falls_back_to_payload_handle_when_profile_handle_is_empty() {
		$post_id  = self::factory()->post->create();
		$post_uri = 'at://did:plc:me/app.bsky.feed.post/emptyhandlepost';

		\update_post_meta( $post_id, BskyPost::META_URI, $post_uri );

		\set_transient(
			'atmosphere_profile_' . \md5( 'did:plc:mallory' ),
			array(
				'name'   => '',
				'handle' => '',
			),
			\HOUR_IN_SECONDS
		);

		$method = new \ReflectionMethod( Reaction_Sync::class, 'process_reply' );

		$comment_id = $method->invoke(
			null,
			$this->reply_notification(
				$post_uri,
				'at://did:plc:mallory/app.bsky.feed.post/emptyhandlereply',
				array(
					'did'    => 'did:plc:mallory',
					'handle' => 'mallory.test',
				)
			)
		);

		$comment = \get_comment( $comment_id );

		$this->assertSame( 'mallory.test', $comment->comment_author );
		$this->assertSame(
			'https://bsky.app/profile/mallory.test',
			$comment->comment_author_url,
			'An empty profile handle must not produce a dead profile link.'
		);
	}
}
