<?php
/**
 * Tests for the `Backfill::get_unsynced_post_ids()` helper.
 *
 * Input-parser tests for the `wp atmosphere backfill` command live in
 * {@see \Atmosphere\Tests\Cli\Test_Backfill_Command}.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group backfill
 */

namespace Atmosphere\Tests;

use WP_UnitTestCase;
use Atmosphere\Backfill;
use Atmosphere\Transformer\Document;
use function Atmosphere\get_supported_post_types;

/**
 * Backfill helper tests.
 */
class Test_Backfill extends WP_UnitTestCase {

	/**
	 * Reset any state that could leak between tests.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_syncable_post_types' );
		\remove_all_filters( 'atmosphere_backfill_query_chunk_size' );
		parent::tear_down();
	}

	/**
	 * Posts that lack the document URI meta are returned, ordered by
	 * publication date (newest first).
	 */
	public function test_returns_unsynced_posts_newest_first() {
		$older_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2020-01-01 12:00:00',
			)
		);
		$newer_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_date'   => '2024-01-01 12:00:00',
			)
		);

		$ids = Backfill::get_unsynced_post_ids( 0, get_supported_post_types() );

		$this->assertContains( $newer_id, $ids );
		$this->assertContains( $older_id, $ids );
		$this->assertLessThan(
			\array_search( $older_id, $ids, true ),
			\array_search( $newer_id, $ids, true ),
			'Newer post should sort before older post.'
		);
	}

	/**
	 * Locks the int[] return contract. A regression to string IDs
	 * (from a missed (int) cast somewhere in the chunked walk) would
	 * otherwise pass the looser `assertContains` checks elsewhere in
	 * this file silently.
	 */
	public function test_returns_only_integers() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$ids = Backfill::get_unsynced_post_ids( 0, get_supported_post_types() );

		$this->assertNotEmpty( $ids, 'Fixture should return at least one post.' );
		$this->assertContainsOnly( 'integer', $ids, true );
	}

	/**
	 * Posts that already carry the document URI meta are excluded.
	 */
	public function test_excludes_already_synced_posts() {
		$synced_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$unsynced_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\update_post_meta(
			$synced_id,
			Document::META_URI,
			'at://did:plc:test/site.standard.document/abc'
		);

		$ids = Backfill::get_unsynced_post_ids( 0, get_supported_post_types() );

		$this->assertContains( $unsynced_id, $ids );
		$this->assertNotContains( $synced_id, $ids );
	}

	/**
	 * Non-publish posts (draft, pending, private, future) and
	 * password-protected posts are excluded by the underlying query.
	 */
	public function test_excludes_non_publish_and_password_protected_posts() {
		$draft_id     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$private_id   = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$future_id    = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => \gmdate( 'Y-m-d H:i:s', \strtotime( '+1 month' ) ),
			)
		);
		$password_id  = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$published_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$ids = Backfill::get_unsynced_post_ids( 0, get_supported_post_types() );

		$this->assertContains( $published_id, $ids );
		$this->assertNotContains( $draft_id, $ids );
		$this->assertNotContains( $private_id, $ids );
		$this->assertNotContains( $future_id, $ids );
		$this->assertNotContains( $password_id, $ids );
	}

	/**
	 * The post-type filter restricts results to the supplied types.
	 */
	public function test_post_type_filter_is_respected() {
		\register_post_type(
			'atmo_test_cpt',
			array(
				'public'   => true,
				'show_ui'  => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		// Expose the CPT to the plugin's supported list.
		\add_filter(
			'atmosphere_syncable_post_types',
			static function ( array $types ): array {
				$types[] = 'atmo_test_cpt';
				return $types;
			}
		);

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$cpt_id  = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'atmo_test_cpt',
			)
		);

		$only_cpt = Backfill::get_unsynced_post_ids( 0, array( 'atmo_test_cpt' ) );

		$this->assertContains( $cpt_id, $only_cpt );
		$this->assertNotContains( $post_id, $only_cpt );

		$only_post = Backfill::get_unsynced_post_ids( 0, array( 'post' ) );

		$this->assertContains( $post_id, $only_post );
		$this->assertNotContains( $cpt_id, $only_post );

		\unregister_post_type( 'atmo_test_cpt' );
	}

	/**
	 * The limit caps the number of returned IDs. A limit of 0 (or
	 * negative) means no cap. The cap counts unsynced posts, not
	 * iterations — the paged walk must skip already-synced posts
	 * without spending limit budget on them.
	 */
	public function test_limit_caps_results() {
		$created = array();
		// Create five published posts, oldest first so the dates are stable.
		for ( $i = 0; $i < 5; $i++ ) {
			$created[] = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_date'   => \gmdate( 'Y-m-d H:i:s', \strtotime( '-' . ( 10 - $i ) . ' days' ) ),
				)
			);
		}

		// Mark the most recent post as already synced. With --limit=1
		// the helper should skip it and return the next-newest unsynced
		// post (created[3]), not the newest iterated post (created[4]).
		\update_post_meta(
			$created[4],
			Document::META_URI,
			'at://did:plc:test/site.standard.document/already-synced'
		);

		$capped = Backfill::get_unsynced_post_ids( 1, get_supported_post_types() );
		$this->assertCount( 1, $capped );
		$this->assertSame( $created[3], $capped[0], 'Limit should count unsynced posts, not iterated posts.' );

		$two = Backfill::get_unsynced_post_ids( 2, get_supported_post_types() );
		$this->assertCount( 2, $two );

		$all = Backfill::get_unsynced_post_ids( 0, get_supported_post_types() );
		// Four created posts are unsynced after marking created[4] above.
		$this->assertCount( 4, $all );

		$all_neg = Backfill::get_unsynced_post_ids( -1, get_supported_post_types() );
		$this->assertSame( $all, $all_neg );
	}

	/**
	 * The paged walk surfaces every unsynced post across page
	 * boundaries when the chunk size is small relative to the
	 * catalogue. Regression test for the OOM-on-large-catalogues fix.
	 */
	public function test_paged_walk_surfaces_results_across_chunk_boundaries() {
		// Force chunk size 5 so 12 posts spans three pages.
		\add_filter(
			'atmosphere_backfill_query_chunk_size',
			static function (): int {
				return 5;
			}
		);

		$created = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$created[] = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_date'   => \gmdate( 'Y-m-d H:i:s', \strtotime( '-' . ( 20 - $i ) . ' days' ) ),
				)
			);
		}

		$all = Backfill::get_unsynced_post_ids( 0, get_supported_post_types() );

		foreach ( $created as $id ) {
			$this->assertContains( $id, $all, "Post {$id} missing from paged walk." );
		}

		// A cap that lands mid-second-page should still return exactly
		// that many results — confirms `break 2` exits the outer
		// do/while as soon as the limit is reached.
		$seven = Backfill::get_unsynced_post_ids( 7, get_supported_post_types() );
		$this->assertCount( 7, $seven );
	}

	/**
	 * An empty post-type list returns an empty result rather than
	 * falling back to the default `post` query.
	 */
	public function test_empty_post_types_returns_empty_array() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( array(), Backfill::get_unsynced_post_ids( 0, array() ) );
		$this->assertSame( array(), Backfill::get_unsynced_post_ids( 10, array() ) );
	}

	/**
	 * Page-boundary exact-multiple regression: when the candidate set
	 * exactly fills an integer number of chunks, the do/while must
	 * exit via the `0 === $chunk_count` branch rather than producing a
	 * spurious extra query (or, worse, missing the last full chunk).
	 */
	public function test_paged_walk_handles_exact_multiple_of_chunk_size() {
		\add_filter(
			'atmosphere_backfill_query_chunk_size',
			static function (): int {
				return 5;
			}
		);

		$created = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$created[] = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_date'   => \gmdate( 'Y-m-d H:i:s', \strtotime( '-' . ( 20 - $i ) . ' days' ) ),
				)
			);
		}

		$all = Backfill::get_unsynced_post_ids( 0, get_supported_post_types() );

		$this->assertCount( 10, $all, 'Exact-multiple catalogue should return every unsynced post.' );

		foreach ( $created as $id ) {
			$this->assertContains( $id, $all, "Post {$id} missing from exact-multiple walk." );
		}
	}

	/**
	 * Combined limit + paging: the limit counter only ticks on
	 * unsynced inserts. Across a page boundary, already-synced posts
	 * in the first chunk must not consume any of the limit budget, so
	 * a `--limit=1` request returns the first unsynced post regardless
	 * of how many synced posts precede it.
	 */
	public function test_limit_only_counts_unsynced_across_page_boundary() {
		\add_filter(
			'atmosphere_backfill_query_chunk_size',
			static function (): int {
				return 2;
			}
		);

		$created = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$created[] = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_date'   => \gmdate( 'Y-m-d H:i:s', \strtotime( '-' . ( 20 - $i ) . ' days' ) ),
				)
			);
		}

		// Mark the two newest posts as already synced. With chunk size
		// 2, the unsynced helper's first page returns only synced rows;
		// the third-newest unsynced post lives on page 2.
		\update_post_meta(
			$created[5],
			Document::META_URI,
			'at://did:plc:test/site.standard.document/synced-newest'
		);
		\update_post_meta(
			$created[4],
			Document::META_URI,
			'at://did:plc:test/site.standard.document/synced-second'
		);

		$capped = Backfill::get_unsynced_post_ids( 1, get_supported_post_types() );

		$this->assertCount( 1, $capped );
		$this->assertSame(
			$created[3],
			$capped[0],
			'Limit budget should only tick on unsynced inserts, not iterated rows from the first page.'
		);
	}
}
