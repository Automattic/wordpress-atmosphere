<?php
/**
 * Tests for the Post_Types class and the post-type helper functions.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group post-types
 */

namespace Atmosphere\Tests;

use WP_UnitTestCase;
use Atmosphere\Post_Types;
use function Atmosphere\get_supported_post_types;
use function Atmosphere\is_supported_post_type;

/**
 * Post type support tests.
 */
class Test_Post_Types extends WP_UnitTestCase {

	/**
	 * Reset state between tests.
	 */
	public function tear_down(): void {
		\remove_post_type_support( 'page', 'atmosphere' );
		\delete_option( 'atmosphere_support_post_types' );
		\remove_all_filters( 'atmosphere_syncable_post_types' );

		parent::tear_down();
	}

	/**
	 * Default option includes the `post` type.
	 */
	public function test_default_includes_post() {
		$this->assertTrue( is_supported_post_type( 'post' ) );
	}

	/**
	 * Custom option drives the supported list.
	 */
	public function test_option_drives_supported_list() {
		\update_option( 'atmosphere_support_post_types', array( 'post', 'page' ) );

		$supported = get_supported_post_types();

		$this->assertContains( 'post', $supported );
		$this->assertContains( 'page', $supported );
		$this->assertTrue( is_supported_post_type( 'page' ) );
	}

	/**
	 * Empty option means nothing is supported (unless opted in via WP API).
	 */
	public function test_empty_option_supports_nothing() {
		\update_option( 'atmosphere_support_post_types', array() );

		$this->assertFalse( is_supported_post_type( 'post' ) );
		$this->assertSame( array(), get_supported_post_types() );
	}

	/**
	 * Third parties can opt their post types in via WP's native API.
	 */
	public function test_third_party_add_post_type_support() {
		\update_option( 'atmosphere_support_post_types', array() );
		\add_post_type_support( 'page', 'atmosphere' );

		$this->assertTrue( is_supported_post_type( 'page' ) );
		$this->assertContains( 'page', get_supported_post_types() );
	}

	/**
	 * The `atmosphere_syncable_post_types` filter still adjusts the list.
	 */
	public function test_filter_can_add_post_type() {
		\add_filter(
			'atmosphere_syncable_post_types',
			static function ( array $types ): array {
				$types[] = 'page';
				return $types;
			}
		);

		$this->assertTrue( is_supported_post_type( 'page' ) );
	}

	/**
	 * `Post_Types::sanitize()` drops unknown / non-public post types.
	 */
	public function test_sanitize_filters_unknown_post_types() {
		$result = Post_Types::sanitize( array( 'post', 'bogus_type', 'page' ) );

		$this->assertSame( array( 'post', 'page' ), $result );
	}

	/**
	 * `Post_Types::sanitize()` coerces empty input to an empty array.
	 */
	public function test_sanitize_handles_empty_input() {
		$this->assertSame( array(), Post_Types::sanitize( null ) );
		$this->assertSame( array(), Post_Types::sanitize( '' ) );
		$this->assertSame( array(), Post_Types::sanitize( array() ) );
	}

	/**
	 * `Post_Types::sanitize()` dedupes the result so the saved option is
	 * a canonical list.
	 */
	public function test_sanitize_dedupes() {
		$result = Post_Types::sanitize( array( 'post', 'page', 'post', 'page' ) );

		$this->assertSame( array( 'post', 'page' ), $result );
	}

	/**
	 * `Post_Types::sanitize()` drops non-strings and empties so callers
	 * never store junk.
	 */
	public function test_sanitize_drops_non_strings_and_empties() {
		$result = Post_Types::sanitize( array( 'post', '', null, 42, true, 'page' ) );

		$this->assertSame( array( 'post', 'page' ), $result );
	}

	/**
	 * Filter callbacks returning duplicates / non-strings / empties are
	 * normalised away so callers always get a clean string[] of unique slugs.
	 */
	public function test_get_supported_normalises_filter_output() {
		\add_filter(
			'atmosphere_syncable_post_types',
			static function (): array {
				return array( 'post', 'post', '', null, 42, 'page' );
			}
		);

		$result = get_supported_post_types();

		$this->assertSame( array( 'post', 'page' ), \array_values( $result ) );
	}
}
