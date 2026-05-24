<?php
/**
 * Tests for the Backfill helper extracted in support of the WP-CLI
 * command. The CLI path and the AJAX path share
 * {@see Backfill::get_unsynced_post_ids()}, so the eligibility rules
 * exercised here apply to both surfaces.
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
	 * Non-publish posts (draft, pending, private) and password-protected
	 * posts are excluded by the underlying query.
	 */
	public function test_excludes_non_publish_and_password_protected_posts() {
		$draft_id     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$private_id   = self::factory()->post->create( array( 'post_status' => 'private' ) );
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
	 * The limit caps the number of returned IDs once unsynced posts
	 * reach the cap. A limit of 0 (or negative) means no cap.
	 */
	public function test_limit_caps_results() {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_date'   => \gmdate( 'Y-m-d H:i:s', \strtotime( '-' . ( 10 - $i ) . ' days' ) ),
				)
			);
		}

		$capped = Backfill::get_unsynced_post_ids( 2, get_supported_post_types() );
		$this->assertCount( 2, $capped );

		$all = Backfill::get_unsynced_post_ids( 0, get_supported_post_types() );
		$this->assertGreaterThanOrEqual( 5, \count( $all ) );

		$all_neg = Backfill::get_unsynced_post_ids( -1, get_supported_post_types() );
		$this->assertSame( $all, $all_neg );
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
}
