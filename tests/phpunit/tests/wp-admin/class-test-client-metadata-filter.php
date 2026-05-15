<?php
/**
 * Tests for the `atmosphere_client_metadata` filter validation.
 *
 * A misbehaving filter that returns a malformed metadata array (or
 * one where required keys are the wrong type) must not break the
 * OAuth client metadata endpoint — the unfiltered defaults must be
 * served instead.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use WP_REST_Request;
use WP_UnitTestCase;
use Atmosphere\Admin;

/**
 * Client metadata filter validation tests.
 */
class Test_Client_Metadata_Filter extends WP_UnitTestCase {

	/**
	 * Tear down filters between tests.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_client_metadata' );
		parent::tear_down();
	}

	/**
	 * Without a filter, the unfiltered defaults are served and the
	 * required OAuth fields are present and the right shape.
	 */
	public function test_default_metadata_is_well_formed() {
		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertIsString( $data['client_id'] );
		$this->assertNotEmpty( $data['client_id'] );
		$this->assertIsArray( $data['redirect_uris'] );
		$this->assertNotEmpty( $data['redirect_uris'] );
	}

	/**
	 * A filter that returns a scalar `redirect_uris` is rejected
	 * — the defaults are served instead.
	 *
	 * Regression for Copilot inline finding on class-admin.php:836.
	 */
	public function test_scalar_redirect_uris_falls_back_to_default() {
		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['redirect_uris'] = 'https://evil.example/cb';
				return $metadata;
			}
		);

		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		$this->assertIsArray( $data['redirect_uris'] );
		$this->assertStringContainsString( 'page=atmosphere', $data['redirect_uris'][0] );
	}

	/**
	 * A filter that returns a non-string `client_id` is rejected.
	 */
	public function test_non_string_client_id_falls_back_to_default() {
		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['client_id'] = array( 'nope' );
				return $metadata;
			}
		);

		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		$this->assertIsString( $data['client_id'] );
	}

	/**
	 * A filter that returns a non-array entirely is rejected.
	 */
	public function test_non_array_filter_return_falls_back_to_default() {
		\add_filter( 'atmosphere_client_metadata', static fn() => 'string-instead-of-array' );

		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertIsString( $data['client_id'] );
	}

	/**
	 * A filter that returns a properly-shaped array IS applied —
	 * the validation must not block the legitimate extension point.
	 */
	public function test_well_formed_filter_return_is_applied() {
		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['client_name'] = 'Custom Name';
				return $metadata;
			}
		);

		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		$this->assertSame( 'Custom Name', $data['client_name'] );
	}
}
