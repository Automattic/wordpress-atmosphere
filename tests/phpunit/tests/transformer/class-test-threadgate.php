<?php
/**
 * Tests for the Threadgate transformer.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group transformer
 */

namespace Atmosphere\Tests\Transformer;

use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Threadgate;

/**
 * Threadgate transformer tests.
 */
class Test_Threadgate extends \WP_UnitTestCase {

	/**
	 * Seed a connected DID so transform() can build the post AT-URI.
	 */
	public function set_up(): void {
		parent::set_up();
		\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ) );
	}

	/**
	 * Clean up option/filter state between tests.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_identity' );
		\remove_all_filters( 'atmosphere_transform_threadgate' );
		parent::tear_down();
	}

	/**
	 * Build a gated post with a reserved rkey.
	 *
	 * @param array $restriction Reply-restriction tokens to store.
	 * @return \WP_Post
	 */
	private function gated_post( array $restriction ): \WP_Post {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status'   => 'publish',
				'post_date_gmt' => '2026-01-02 03:04:05',
			)
		);
		\update_post_meta( $post->ID, Post::META_TID, 'post-tid-123' );
		\update_post_meta( $post->ID, Threadgate::META_RESTRICTION, $restriction );

		return $post;
	}

	/**
	 * Unknown tokens are dropped and duplicates collapsed.
	 */
	public function test_sanitize_drops_unknown_and_dedupes() {
		$this->assertSame(
			array( Threadgate::AUDIENCE_MENTIONED, Threadgate::AUDIENCE_FOLLOWING ),
			Threadgate::sanitize_restriction(
				array( 'mentioned', 'bogus', 'following', 'mentioned' )
			)
		);
	}

	/**
	 * A non-array setting sanitizes to "everybody" (empty).
	 */
	public function test_sanitize_non_array_is_everybody() {
		$this->assertSame( array(), Threadgate::sanitize_restriction( 'nobody' ) );
	}

	/**
	 * "nobody" wins over any accompanying audience tokens.
	 */
	public function test_sanitize_nobody_collapses() {
		$this->assertSame(
			array( Threadgate::AUDIENCE_NOBODY ),
			Threadgate::sanitize_restriction(
				array( 'mentioned', 'nobody', 'following' )
			)
		);
	}

	/**
	 * An empty restriction is "everybody" and not gated.
	 */
	public function test_is_restricted_false_for_everybody() {
		$post = $this->gated_post( array() );
		$this->assertFalse( Threadgate::is_restricted( $post ) );
	}

	/**
	 * A stored audience makes the post gated.
	 */
	public function test_is_restricted_true_for_tokens() {
		$post = $this->gated_post( array( Threadgate::AUDIENCE_FOLLOWING ) );
		$this->assertTrue( Threadgate::is_restricted( $post ) );
	}

	/**
	 * "nobody" is gated and produces a present-but-empty allow list.
	 */
	public function test_transform_nobody_has_empty_allow() {
		$post   = $this->gated_post( array( Threadgate::AUDIENCE_NOBODY ) );
		$record = ( new Threadgate( $post ) )->transform();

		$this->assertSame( 'app.bsky.feed.threadgate', $record['$type'] );
		$this->assertSame( array(), $record['allow'] );
	}

	/**
	 * Audience tokens map to the matching threadgate allow rules.
	 */
	public function test_transform_audiences_map_to_rules() {
		$post   = $this->gated_post(
			array( Threadgate::AUDIENCE_MENTIONED, Threadgate::AUDIENCE_FOLLOWING )
		);
		$record = ( new Threadgate( $post ) )->transform();

		$this->assertSame(
			array(
				array( '$type' => 'app.bsky.feed.threadgate#mentionRule' ),
				array( '$type' => 'app.bsky.feed.threadgate#followingRule' ),
			),
			$record['allow']
		);
	}

	/**
	 * The record points at the post via its AT-URI and carries the
	 * post's publish time.
	 */
	public function test_transform_post_uri_and_created_at() {
		$post   = $this->gated_post( array( Threadgate::AUDIENCE_FOLLOWER ) );
		$record = ( new Threadgate( $post ) )->transform();

		$this->assertSame(
			'at://did:plc:test123/app.bsky.feed.post/post-tid-123',
			$record['post']
		);
		$this->assertSame( '2026-01-02T03:04:05.000Z', $record['createdAt'] );
	}

	/**
	 * The threadgate shares the gated post's rkey.
	 */
	public function test_get_rkey_shares_post_tid() {
		$post = $this->gated_post( array( Threadgate::AUDIENCE_FOLLOWING ) );
		$this->assertSame( 'post-tid-123', ( new Threadgate( $post ) )->get_rkey() );
	}

	/**
	 * The transform filter can override the record.
	 */
	public function test_transform_filter_override() {
		$post = $this->gated_post( array( Threadgate::AUDIENCE_FOLLOWING ) );

		\add_filter(
			'atmosphere_transform_threadgate',
			static function ( $record ) {
				$record['allow'] = array();
				return $record;
			}
		);

		$record = ( new Threadgate( $post ) )->transform();
		$this->assertSame( array(), $record['allow'] );
	}

	/**
	 * A non-array filter return falls back to the unfiltered record.
	 */
	public function test_transform_filter_non_array_falls_back() {
		$post = $this->gated_post( array( Threadgate::AUDIENCE_FOLLOWING ) );

		\add_filter( 'atmosphere_transform_threadgate', '__return_false' );

		$this->setExpectedIncorrectUsage( 'Atmosphere\Transformer\Threadgate::transform' );

		$record = ( new Threadgate( $post ) )->transform();
		$this->assertSame(
			array( array( '$type' => 'app.bsky.feed.threadgate#followingRule' ) ),
			$record['allow']
		);
	}
}
