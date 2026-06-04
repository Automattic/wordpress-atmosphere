<?php
/**
 * Tests for the content-parser Registry.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group content-parser
 */

namespace Atmosphere\Tests\Content_Parser;

require_once __DIR__ . '/class-fake-parser.php';
require_once __DIR__ . '/class-minimal-parser.php';

use Atmosphere\Atmosphere;
use Atmosphere\Content_Parser\Registry;

/**
 * Registry tests.
 */
class Test_Registry extends \WP_UnitTestCase {

	/**
	 * Start from an empty registry.
	 */
	public function set_up(): void {
		parent::set_up();
		Registry::reset();
	}

	/**
	 * Restore the bootstrap defaults for later test files.
	 */
	public function tear_down(): void {
		Registry::reset();
		\delete_option( Registry::OPTION_FORMAT );
		Atmosphere::register_default_content_parsers();
		parent::tear_down();
	}

	/**
	 * Round-trips register() and has().
	 */
	public function test_register_and_has() {
		$this->assertFalse( Registry::has( 'test.a' ) );

		Registry::register( new Fake_Parser( 'test.a' ) );

		$this->assertTrue( Registry::has( 'test.a' ) );
	}

	/**
	 * Re-registering the same NSID replaces the entry (no duplicate).
	 */
	public function test_register_same_type_replaces() {
		Registry::register( new Fake_Parser( 'test.a' ), 30 );
		Registry::register( new Fake_Parser( 'test.a' ), 10 );

		$this->assertCount( 1, Registry::all() );
	}

	/**
	 * Lists parsers sorted by ascending priority.
	 */
	public function test_all_sorted_by_priority() {
		Registry::register( new Fake_Parser( 'test.late' ), 30 );
		Registry::register( new Fake_Parser( 'test.early' ), 10 );
		Registry::register( new Fake_Parser( 'test.mid' ), 20 );

		$this->assertSame(
			array( 'test.early', 'test.mid', 'test.late' ),
			\array_keys( Registry::all() )
		);
	}

	/**
	 * Removing a parser drops it from the registry.
	 */
	public function test_unregister() {
		Registry::register( new Fake_Parser( 'test.a' ) );
		Registry::unregister( 'test.a' );

		$this->assertFalse( Registry::has( 'test.a' ) );
	}

	/**
	 * Selection picks the lowest-priority applicable parser by default.
	 */
	public function test_select_picks_lowest_priority() {
		Registry::register( new Fake_Parser( 'test.low', true ), 10 );
		Registry::register( new Fake_Parser( 'test.high', true ), 20 );

		$post = self::factory()->post->create_and_get();

		$this->assertSame( 'test.low', Registry::select( $post )->get_type() );
	}

	/**
	 * Selection skips parsers that do not apply.
	 */
	public function test_select_skips_inapplicable() {
		Registry::register( new Fake_Parser( 'test.skip', false ), 10 );
		Registry::register( new Fake_Parser( 'test.ok', true ), 20 );

		$post = self::factory()->post->create_and_get();

		$this->assertSame( 'test.ok', Registry::select( $post )->get_type() );
	}

	/**
	 * Parsers without optional applies_to() remain applicable.
	 */
	public function test_select_accepts_minimal_parser_without_applies_to() {
		Registry::register( new Minimal_Parser() );

		$post = self::factory()->post->create_and_get();

		$this->assertSame( 'test.minimal', Registry::select( $post )->get_type() );
	}

	/**
	 * The configured format wins over priority when applicable.
	 */
	public function test_select_honors_configured_format() {
		Registry::register( new Fake_Parser( 'test.default', true ), 10 );
		Registry::register( new Fake_Parser( 'test.chosen', true ), 20 );

		\update_option( Registry::OPTION_FORMAT, 'test.chosen' );

		$post = self::factory()->post->create_and_get();

		$this->assertSame( 'test.chosen', Registry::select( $post )->get_type() );
	}

	/**
	 * A configured format that doesn't apply falls back to priority.
	 */
	public function test_select_falls_back_when_configured_format_inapplicable() {
		Registry::register( new Fake_Parser( 'test.default', true ), 10 );
		Registry::register( new Fake_Parser( 'test.chosen', false ), 20 );

		\update_option( Registry::OPTION_FORMAT, 'test.chosen' );

		$post = self::factory()->post->create_and_get();

		$this->assertSame( 'test.default', Registry::select( $post )->get_type() );
	}

	/**
	 * Selection returns null when nothing applies.
	 */
	public function test_select_returns_null_when_empty() {
		$post = self::factory()->post->create_and_get();

		$this->assertNull( Registry::select( $post ) );
	}
}
