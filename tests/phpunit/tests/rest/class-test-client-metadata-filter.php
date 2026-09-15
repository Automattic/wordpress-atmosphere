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
 * @group rest
 */

namespace Atmosphere\Tests\Rest;

use WP_UnitTestCase;
use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\Client_Authentication;
use Atmosphere\Rest\Client_Metadata_Controller;
use Atmosphere\Rest\Legacy_Client_Metadata_Controller;

/**
 * Client metadata filter validation tests.
 */
class Test_Client_Metadata_Filter extends WP_UnitTestCase {

	/**
	 * Tear down filters between tests.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'atmosphere_client_metadata' );
		\remove_all_filters( 'pre_option_blogname' );
		\delete_option( Client_Authentication::KEY_OPTION );
		parent::tear_down();
	}

	/**
	 * Without a filter, the unfiltered defaults are served and the
	 * required OAuth fields are present and the right shape.
	 */
	public function test_default_metadata_is_well_formed() {
		$response = ( new Client_Metadata_Controller() )->get_metadata();
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertIsString( $data['client_id'] );
		$this->assertNotEmpty( $data['client_id'] );
		$this->assertIsArray( $data['redirect_uris'] );
		$this->assertNotEmpty( $data['redirect_uris'] );
		$this->assertSame( Client::client_id(), $data['client_id'] );
		$this->assertSame( 'private_key_jwt', $data['token_endpoint_auth_method'] );
		$this->assertSame( 'ES256', $data['token_endpoint_auth_signing_alg'] );
		$this->assertIsArray( $data['jwks'] );
		$this->assertCount( 1, $data['jwks']['keys'] );
		$this->assertArrayNotHasKey( 'd', $data['jwks']['keys'][0] );
		$this->assertSame( Client::scopes(), $data['scope'] );
		$this->assertStringNotContainsString( 'transition:generic', $data['scope'] );
		$this->assertStringContainsString( 'repo:app.bsky.feed.post', $data['scope'] );
		$this->assertStringContainsString(
			'repo:app.bsky.feed.threadgate',
			$data['scope'],
			'Threadgates are their own record, so writing them needs its own scope.'
		);
		$this->assertStringContainsString( 'repo:site.standard.document', $data['scope'] );
		$this->assertStringContainsString( 'repo:site.standard.publication', $data['scope'] );
		$this->assertStringContainsString( 'blob:image/*', $data['scope'] );
		$this->assertStringContainsString(
			'rpc:app.bsky.actor.getProfile?aud=did:web:api.bsky.app%23bsky_appview',
			$data['scope']
		);
		$this->assertStringContainsString(
			'rpc:app.bsky.notification.listNotifications?aud=did:web:api.bsky.app%23bsky_appview',
			$data['scope']
		);
		$this->assertStringContainsString( 'identity:handle', $data['scope'] );
		$this->assertStringContainsString( 'include:site.standard.authFull', $data['scope'] );
	}

	/**
	 * Existing public-client sessions keep their immutable v1 metadata while
	 * all newly authorized sessions use the confidential v2 client.
	 */
	public function test_legacy_metadata_remains_a_public_client() {
		$response = ( new Legacy_Client_Metadata_Controller() )->get_metadata();
		$data     = $response->get_data();

		$this->assertSame( Client::legacy_client_id(), $data['client_id'] );
		$this->assertSame( 'none', $data['token_endpoint_auth_method'] );
		$this->assertArrayNotHasKey( 'jwks', $data );
		$this->assertArrayNotHasKey( 'token_endpoint_auth_signing_alg', $data );
	}

	/**
	 * Both metadata URLs must answer through the REST server, which hands
	 * the request object to the callback as its first argument.
	 */
	public function test_both_routes_dispatch_through_the_rest_server() {
		\do_action( 'rest_api_init' );
		( new Client_Metadata_Controller() )->register_routes();
		( new Legacy_Client_Metadata_Controller() )->register_routes();

		$v2 = \rest_do_request( new \WP_REST_Request( 'GET', '/atmosphere/v2/client-metadata' ) );
		$this->assertSame( 200, $v2->get_status() );
		$this->assertSame( Client::client_id(), $v2->get_data()['client_id'] );

		$legacy = \rest_do_request( new \WP_REST_Request( 'GET', '/atmosphere/v1/client-metadata' ) );
		$this->assertSame( 200, $legacy->get_status() );
		$this->assertSame( Client::legacy_client_id(), $legacy->get_data()['client_id'] );
	}

	/**
	 * An unreadable signing key must not serve a document without a key.
	 */
	public function test_unreadable_signing_key_is_a_server_error() {
		\update_option( Client_Authentication::KEY_OPTION, 'not-a-ciphertext', false );

		$response = ( new Client_Metadata_Controller() )->get_metadata();

		$this->assertSame( 500, $response->get_status() );
		$this->assertArrayNotHasKey( 'jwks', $response->get_data() );
	}

	/**
	 * The filter receives the document version so a callback can tell the
	 * two documents apart.
	 */
	public function test_filter_receives_the_document_namespace() {
		$seen = array();
		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata, $version ) use ( &$seen ) {
				$seen[] = $version;
				return $metadata;
			},
			10,
			2
		);

		( new Client_Metadata_Controller() )->get_metadata();
		( new Legacy_Client_Metadata_Controller() )->get_metadata();

		$this->assertSame( array( 'v2', 'v1' ), $seen );
	}

	/**
	 * A filter cannot change the signing algorithm the published key uses.
	 */
	public function test_filter_cannot_change_signing_alg() {
		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['token_endpoint_auth_signing_alg'] = 'RS256';
				return $metadata;
			}
		);

		$data = ( new Client_Metadata_Controller() )->get_metadata()->get_data();

		$this->assertSame( 'ES256', $data['token_endpoint_auth_signing_alg'] );
	}

	/**
	 * A filter that returns a scalar `redirect_uris` is rejected
	 * — the defaults are served instead.
	 *
	 * Regression for Copilot inline finding on class-admin.php:836.
	 */
	public function test_scalar_redirect_uris_falls_back_to_default() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Rest\\Client_Metadata_Controller::get_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['redirect_uris'] = 'https://evil.example/cb';
				return $metadata;
			}
		);

		$response = ( new Client_Metadata_Controller() )->get_metadata();
		$data     = $response->get_data();

		$this->assertIsArray( $data['redirect_uris'] );
		$this->assertStringContainsString( 'page=atmosphere', $data['redirect_uris'][0] );
	}

	/**
	 * A filter that returns a non-string `client_id` is rejected.
	 */
	public function test_non_string_client_id_falls_back_to_default() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Rest\\Client_Metadata_Controller::get_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['client_id'] = array( 'nope' );
				return $metadata;
			}
		);

		$response = ( new Client_Metadata_Controller() )->get_metadata();
		$data     = $response->get_data();

		$this->assertIsString( $data['client_id'] );
	}

	/**
	 * A filter that returns a non-array entirely is rejected.
	 */
	public function test_non_array_filter_return_falls_back_to_default() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Rest\\Client_Metadata_Controller::get_metadata' );

		\add_filter( 'atmosphere_client_metadata', static fn() => 'string-instead-of-array' );

		$response = ( new Client_Metadata_Controller() )->get_metadata();
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
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Rest\\Client_Metadata_Controller::get_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['redirect_uris'] = array( 'https://evil.example/cb' );
				return $metadata;
			}
		);

		$response = ( new Client_Metadata_Controller() )->get_metadata();
		$data     = $response->get_data();

		$this->assertStringStartsWith( \admin_url( '', 'https' ), $data['redirect_uris'][0] );
	}

	/**
	 * A `redirect_uris` entry that uses the HTTP scheme — even when it
	 * otherwise points at this site's admin — is rejected. The auth
	 * server would otherwise deliver the OAuth code over cleartext.
	 */
	public function test_http_scheme_redirect_uri_falls_back_to_default() {
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Rest\\Client_Metadata_Controller::get_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['redirect_uris'] = array(
					'http://example.org/wp-admin/options-general.php?page=atmosphere',
				);
				return $metadata;
			}
		);

		$response = ( new Client_Metadata_Controller() )->get_metadata();
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
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Rest\\Client_Metadata_Controller::get_metadata' );

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

		$response = ( new Client_Metadata_Controller() )->get_metadata();
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
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Rest\\Client_Metadata_Controller::get_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) {
				$metadata['redirect_uris'] = array( '' );
				return $metadata;
			}
		);

		$response = ( new Client_Metadata_Controller() )->get_metadata();
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
		$this->setExpectedIncorrectUsage( 'Atmosphere\\Rest\\Client_Metadata_Controller::get_metadata' );

		\add_filter(
			'atmosphere_client_metadata',
			static function ( $metadata ) use ( $bad_entry ) {
				$metadata['redirect_uris'] = array( $bad_entry );
				return $metadata;
			}
		);

		$response = ( new Client_Metadata_Controller() )->get_metadata();
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
	 * The OAuth client name is HTML-entity decoded. WordPress stores
	 * `blogname` entity-encoded (esc_html at save time), so without
	 * decoding the consent screen would render raw codes like `&#039;`.
	 */
	public function test_client_name_decodes_html_entities() {
		// Covers both a numeric entity (&#039;) and a named entity (&amp;).
		\add_filter( 'pre_option_blogname', static fn() => 'Tom &amp; Toni&#039;s blog' );

		$response = ( new Client_Metadata_Controller() )->get_metadata();
		$data     = $response->get_data();

		$this->assertSame( "Tom & Toni's blog (ATmosphere)", $data['client_name'] );
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

		$response = ( new Client_Metadata_Controller() )->get_metadata();
		$data     = $response->get_data();

		$this->assertSame( 'Custom Name', $data['client_name'] );
	}
}
