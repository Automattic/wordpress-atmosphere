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

use WP_UnitTestCase;
use Atmosphere\WP_Admin\Admin;

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
		$this->setExpectedIncorrectUsage( 'Atmosphere\\WP_Admin\\Admin::serve_client_metadata' );

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
		$this->setExpectedIncorrectUsage( 'Atmosphere\\WP_Admin\\Admin::serve_client_metadata' );

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
		$this->setExpectedIncorrectUsage( 'Atmosphere\\WP_Admin\\Admin::serve_client_metadata' );

		\add_filter( 'atmosphere_client_metadata', static fn() => 'string-instead-of-array' );

		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertIsString( $data['client_id'] );
	}

	/**
	 * A filter that injects an off-site `redirect_uris` entry is
	 * rejected — the metadata endpoint is public and the document
	 * advertises `token_endpoint_auth_method: 'none'`, so an
	 * off-site URI would be a token-leak primitive.
	 */
	public function test_offsite_redirect_uri_falls_back_to_default() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\WP_Admin\\Admin::serve_client_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['redirect_uris'] = array( 'https://evil.example/cb' );
				return $metadata;
			}
		);

		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		$this->assertStringStartsWith( \admin_url( '', 'https' ), $data['redirect_uris'][0] );
	}

	/**
	 * A `redirect_uris` entry that uses the HTTP scheme — even when it
	 * otherwise points at this site's admin — is rejected. The auth
	 * server would otherwise deliver the OAuth code over cleartext.
	 */
	public function test_http_scheme_redirect_uri_falls_back_to_default() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\WP_Admin\\Admin::serve_client_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['redirect_uris'] = array(
					'http://example.org/wp-admin/options-general.php?page=atmosphere',
				);
				return $metadata;
			}
		);

		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		$this->assertCount( 1, $data['redirect_uris'] );
		$this->assertStringStartsWith( 'https://', $data['redirect_uris'][0] );
	}

	/**
	 * A filter where ANY entry is off-site disqualifies the entire
	 * filter result — defaults are served. Mixed valid + invalid is
	 * not "partial use the valid ones."
	 */
	public function test_mixed_valid_invalid_redirect_uris_falls_back_to_default() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\WP_Admin\\Admin::serve_client_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['redirect_uris'] = array(
					\admin_url( 'options-general.php?page=atmosphere', 'https' ),
					'https://evil.example/cb',
				);
				return $metadata;
			}
		);

		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		// Should be the default single-entry list, not a 2-entry list.
		$this->assertCount( 1, $data['redirect_uris'] );
		$this->assertStringStartsWith( \admin_url( '', 'https' ), $data['redirect_uris'][0] );
	}

	/**
	 * A filter that puts an empty string into `redirect_uris` is
	 * rejected. An empty entry would otherwise pass `!empty()` on
	 * the parent array because the array itself has one element.
	 */
	public function test_empty_string_redirect_uri_falls_back_to_default() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\WP_Admin\\Admin::serve_client_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['redirect_uris'] = array( '' );
				return $metadata;
			}
		);

		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		$this->assertNotEmpty( $data['redirect_uris'][0] );
	}

	/**
	 * A filter that puts a non-string (`null`, nested array) into
	 * `redirect_uris` is rejected.
	 *
	 * @dataProvider provide_non_string_redirect_uri_entries
	 *
	 * @param mixed $bad_entry Entry to inject.
	 */
	public function test_non_string_redirect_uri_entry_falls_back_to_default( $bad_entry ) {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\WP_Admin\\Admin::serve_client_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) use ( $bad_entry ) {
				$metadata['redirect_uris'] = array( $bad_entry );
				return $metadata;
			}
		);

		$response = Admin::serve_client_metadata();
		$data     = $response->get_data();

		$this->assertIsString( $data['redirect_uris'][0] );
	}

	/**
	 * Data provider — non-string `redirect_uris` entries.
	 *
	 * @return array<string, array{0:mixed}>
	 */
	public function provide_non_string_redirect_uri_entries(): array {
		return array(
			'null'         => array( null ),
			'integer'      => array( 42 ),
			'nested-array' => array( array( 'nested' ) ),
			'bool'         => array( true ),
		);
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
