<?php
/**
 * Tests for the Content format setting sanitizer.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group content-parser
 */

namespace Atmosphere\Tests\WP_Admin;

require_once __DIR__ . '/../content-parser/class-fake-parser.php';

use Atmosphere\Atmosphere;
use Atmosphere\Content_Parser\Registry;
use Atmosphere\Sanitize;
use Atmosphere\Tests\Content_Parser\Fake_Parser;

/**
 * Content format setting tests.
 */
class Test_Content_Format_Setting extends \WP_UnitTestCase {

	/**
	 * Register a single known parser so sanitization is deterministic.
	 */
	public function set_up(): void {
		parent::set_up();
		Registry::reset();
		Registry::register( new Fake_Parser( 'test.format' ) );
	}

	/**
	 * Restore the bootstrap defaults for later test files.
	 */
	public function tear_down(): void {
		Registry::reset();
		Atmosphere::register_default_content_parsers();
		parent::tear_down();
	}

	/**
	 * An empty value (automatic) passes through.
	 */
	public function test_empty_value_is_automatic() {
		$this->assertSame( '', Sanitize::content_format( '' ) );
	}

	/**
	 * A registered NSID is accepted.
	 */
	public function test_registered_nsid_accepted() {
		$this->assertSame( 'test.format', Sanitize::content_format( 'test.format' ) );
	}

	/**
	 * An unregistered NSID falls back to automatic.
	 */
	public function test_unregistered_nsid_rejected() {
		$this->assertSame( '', Sanitize::content_format( 'bogus.format' ) );
	}

	/**
	 * A non-string value falls back to automatic.
	 */
	public function test_non_string_rejected() {
		$this->assertSame( '', Sanitize::content_format( array( 'x' ) ) );
	}
}
