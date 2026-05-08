<?php
/**
 * Tests for the domain-handle service.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group handle
 */

namespace Atmosphere\Tests;

use Atmosphere\Handle;
use WP_UnitTestCase;

/**
 * Domain-handle tests.
 */
class Test_Handle extends WP_UnitTestCase {

	/**
	 * Test that public constants hold the expected string values.
	 */
	public function test_constants(): void {
		$this->assertSame( 'atmosphere_domain_handle_enabled', Handle::FILTER_ENABLED );
		$this->assertSame( 'atmosphere_pre_update_handle', Handle::FILTER_PRE_UPDATE );
		$this->assertSame( 'atmosphere_previous_handle', Handle::OPTION_PREVIOUS_HANDLE );
		$this->assertSame( 'atmosphere', Handle::NOTICE_SETTING );
	}
}
