<?php
/**
 * Tests for the comment type API polyfill.
 *
 * The polyfill mirrors two open core pull requests, so the tests pin the
 * behavior those pull requests define: the registry, the built-in types, and
 * the default excluded comment types applied to queries, counts, feeds and
 * comment paging.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group polyfill
 */

namespace Atmosphere\Tests\Polyfill;

/**
 * Comment type polyfill tests.
 */
class Test_Comment_Types extends \WP_UnitTestCase {

	/**
	 * Post the test comments belong to.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Create a post for the comment tests.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->post_id = self::factory()->post->create();
	}

	/**
	 * Unregister the test types.
	 */
	public function tear_down(): void {
		foreach ( array( 'reaction', 'private_log' ) as $comment_type ) {
			if ( \comment_type_exists( $comment_type ) ) {
				\unregister_comment_type( $comment_type );
			}
		}

		parent::tear_down();
	}

	/**
	 * The copy shipped with this plugin registers its version and is the one loaded.
	 */
	public function test_loads_the_registered_copy() {
		$file = \realpath( \dirname( __DIR__, 4 ) . '/includes/polyfill/comment-types.php' );

		$this->assertContains( $file, $GLOBALS['wp_comment_types_polyfill_versions'] );
		$this->assertSame( $file, \WP_COMMENT_TYPES_POLYFILL_FILE );
	}

	/**
	 * The built-in types are registered, and only 'note' is internal.
	 */
	public function test_built_in_comment_types() {
		foreach ( array( 'comment', 'pingback', 'trackback', 'note' ) as $comment_type ) {
			$object = \get_comment_type_object( $comment_type );

			$this->assertInstanceOf( \WP_Comment_Type::class, $object );
			$this->assertTrue( $object->_builtin );
			$this->assertSame( 'note' === $comment_type, $object->internal );
		}

		$this->assertSame( 'Pingback', \get_comment_type_object( 'pingback' )->labels->singular_name );
	}

	/**
	 * Registration returns the object, builds labels, and keeps extra args as properties.
	 */
	public function test_register_comment_type() {
		$object = \register_comment_type(
			'reaction',
			array(
				'label'      => 'Reactions',
				'extra_flag' => true,
			)
		);

		$this->assertInstanceOf( \WP_Comment_Type::class, $object );
		$this->assertSame( $object, \get_comment_type_object( 'reaction' ) );
		$this->assertSame( 'Reactions', $object->label );
		$this->assertSame( 'Reactions', $object->labels->name );
		$this->assertSame( 'Reactions', $object->labels->singular_name );
		$this->assertFalse( isset( $object->labels->all_items ) );
		$this->assertTrue( $object->extra_flag );
		$this->assertTrue( $object->public );
		$this->assertFalse( $object->internal );
		$this->assertContains( 'reaction', \get_comment_types( array( 'extra_flag' => true ) ) );
	}

	/**
	 * Names that are too long, reserved, or built in are rejected.
	 *
	 * @expectedIncorrectUsage register_comment_type
	 */
	public function test_register_comment_type_rejects_invalid_names() {
		$this->assertWPError( \register_comment_type( \str_repeat( 'a', 21 ) ) );
		$this->assertWPError( \register_comment_type( 'pings' ) );
		$this->assertWPError( \register_comment_type( 'pingback' ) );
		$this->assertTrue( \get_comment_type_object( 'pingback' )->_builtin );
	}

	/**
	 * Custom types can be unregistered, built-in types cannot.
	 */
	public function test_unregister_comment_type() {
		\register_comment_type( 'reaction' );

		$this->assertTrue( \unregister_comment_type( 'reaction' ) );
		$this->assertFalse( \comment_type_exists( 'reaction' ) );
		$this->assertWPError( \unregister_comment_type( 'reaction' ) );
		$this->assertWPError( \unregister_comment_type( 'comment' ) );
	}

	/**
	 * The excluded set holds 'note' and internal types, and is normalized after the filter.
	 */
	public function test_default_excluded_comment_types() {
		\register_comment_type( 'private_log', array( 'internal' => true ) );

		$filter = static function ( $types ) {
			return \array_merge( $types, array( 'reaction', '', 'all', 'pings', array( 'x' ), 'reaction' ) );
		};
		\add_filter( 'default_excluded_comment_types', $filter );

		$this->assertSame( array( 'note', 'private_log', 'reaction' ), \wp_get_default_excluded_comment_types() );

		\remove_filter( 'default_excluded_comment_types', $filter );
	}

	/**
	 * Excluded types stay out of default queries but can be requested explicitly.
	 */
	public function test_query_excludes_default_excluded_types() {
		\register_comment_type( 'private_log', array( 'internal' => true ) );

		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $this->post_id ) );
		$log     = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_type'    => 'private_log',
			)
		);

		$query = array(
			'post_id' => $this->post_id,
			'fields'  => 'ids',
			'orderby' => 'comment_ID',
			'order'   => 'ASC',
		);

		$this->assertSame( array( $comment ), \get_comments( $query ) );
		$this->assertSame( array( $comment ), \get_comments( \array_merge( $query, array( 'type' => 'comments' ) ) ) );
		$this->assertSame( array( $log ), \get_comments( \array_merge( $query, array( 'type' => 'private_log' ) ) ) );
		$this->assertSame( array( $log ), \get_comments( \array_merge( $query, array( 'type__in' => array( 'private_log' ) ) ) ) );
		$this->assertSame( array( $comment, $log ), \get_comments( \array_merge( $query, array( 'type' => 'all' ) ) ) );
		$this->assertSame( 1, \get_comments( \array_merge( $query, array( 'count' => true ) ) ) );
	}

	/**
	 * A change to the excluded set is not served from the query cache.
	 */
	public function test_query_cache_follows_excluded_set() {
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_type'    => 'reaction',
			)
		);

		$query = array(
			'post_id' => $this->post_id,
			'count'   => true,
		);

		$this->assertSame( 1, \get_comments( $query ) );

		$filter = static fn( $types ) => \array_merge( $types, array( 'reaction' ) );
		\add_filter( 'default_excluded_comment_types', $filter );

		$this->assertSame( 0, \get_comments( $query ) );

		\remove_filter( 'default_excluded_comment_types', $filter );
	}

	/**
	 * The stored comment count leaves excluded types out, and keeps an earlier count.
	 */
	public function test_comment_count_excludes_default_excluded_types() {
		// Drop plugin callbacks that return their own count before the polyfill runs.
		\remove_all_filters( 'pre_wp_update_comment_count_now', 5 );

		\register_comment_type( 'private_log', array( 'internal' => true ) );

		self::factory()->comment->create( array( 'comment_post_ID' => $this->post_id ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->post_id,
				'comment_type'    => 'private_log',
			)
		);

		\wp_update_comment_count_now( $this->post_id );
		$this->assertSame( '1', \get_comments_number( $this->post_id ) );

		$earlier = static fn() => 7;
		\add_filter( 'pre_wp_update_comment_count_now', $earlier, 5 );
		\wp_update_comment_count_now( $this->post_id );
		\remove_filter( 'pre_wp_update_comment_count_now', $earlier, 5 );

		$this->assertSame( '7', \get_comments_number( $this->post_id ) );
	}

	/**
	 * The comment feed WHERE clause excludes the set.
	 */
	public function test_comment_feed_where_excludes_default_excluded_types() {
		global $wpdb;

		$where = \apply_filters( 'comment_feed_where', "WHERE comment_approved = '1'" );

		$this->assertStringStartsWith( "WHERE comment_approved = '1' AND {$wpdb->comments}.comment_type NOT IN (", $where );
		$this->assertStringContainsString( "'note'", $where );
	}

	/**
	 * Comment paging counts only the comments the comment list renders.
	 */
	public function test_page_of_comment_skips_default_excluded_types() {
		\register_comment_type( 'private_log', array( 'internal' => true ) );
		\update_option( 'page_comments', 1 );
		\update_option( 'comments_per_page', 1 );

		$date = \strtotime( '-1 hour' );

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_type'     => 'private_log',
				'comment_date_gmt' => \gmdate( 'Y-m-d H:i:s', $date ),
			)
		);
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_date_gmt' => \gmdate( 'Y-m-d H:i:s', $date + 60 ),
			)
		);

		$this->assertSame( 1, \get_page_of_comment( $comment ) );
	}
}
