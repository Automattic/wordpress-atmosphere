<?php
/**
 * Tests for the AT Protocol resolver — SSRF and URL-validation surface.
 *
 * Walks the handle → DID → DID Document → PDS → Auth Server chain and
 * confirms that every URL produced from attacker-controlled response
 * data is rejected unless it is a plain HTTPS URL pointing at a
 * publicly-routable host.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group oauth
 */

namespace Atmosphere\Tests\OAuth;

use WP_UnitTestCase;
use Atmosphere\OAuth\Resolver;

/**
 * Resolver SSRF / URL-validation tests.
 */
class Test_Resolver extends WP_UnitTestCase {

	/**
	 * Tear down filters between tests.
	 */
	public function tear_down(): void {
		\remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Stub the next HTTP response with a fixed body for a URL substring.
	 *
	 * @param string $url_match Substring to match against the request URL.
	 * @param int    $status    HTTP status code.
	 * @param mixed  $body      Response body (array → JSON encoded).
	 */
	private function stub_response( string $url_match, int $status, $body ): void {
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( $url_match, $status, $body ) {
				if ( false !== \strpos( $url, $url_match ) ) {
					return array(
						'response' => array( 'code' => $status ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
						'body'     => \is_array( $body ) ? (string) \wp_json_encode( $body ) : (string) $body,
					);
				}

				return $response;
			},
			10,
			3
		);
	}

	/**
	 * `handle_to_did` rejects malformed handles before any network /
	 * DNS lookup.
	 */
	public function test_handle_to_did_rejects_non_dns_handle() {
		$result = Resolver::handle_to_did( 'http://evil.example/' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_invalid_handle', $result->get_error_code() );
	}

	/**
	 * A handle that's just a hostname (no dot) should be rejected — the
	 * AT Protocol spec requires at least two labels, and a single-label
	 * "handle" like `localhost` is a classic SSRF entry point.
	 */
	public function test_handle_to_did_rejects_single_label_handle() {
		$result = Resolver::handle_to_did( 'localhost' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_invalid_handle', $result->get_error_code() );
	}

	/**
	 * `resolve_did` rejects `did:web:<invalid-host>` before constructing
	 * the .well-known URL — a leading `.`, a percent-encoded host, or
	 * an IP-literal must not reach `wp_safe_remote_get`.
	 */
	public function test_resolve_did_rejects_invalid_did_web_host() {
		$result = Resolver::resolve_did( 'did:web:%6c%6f%63%61%6c%68%6f%73%74' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_invalid_did', $result->get_error_code() );
	}

	/**
	 * `resolve_did` rejects unsupported DID methods.
	 */
	public function test_resolve_did_rejects_unsupported_method() {
		$result = Resolver::resolve_did( 'did:key:zXyz' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsupported_did', $result->get_error_code() );
	}

	/**
	 * `pds_from_did_doc` rejects a `serviceEndpoint` pointing at an
	 * internal host. WordPress core's URL-safety gate blocks
	 * loopback / private-IP destinations; the resolver must surface a
	 * clean `WP_Error` rather than handing the URL downstream.
	 */
	public function test_pds_from_did_doc_rejects_internal_endpoint() {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => 'http://127.0.0.1:8888',
				),
			),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_pds', $result->get_error_code() );
	}

	/**
	 * `pds_from_did_doc` rejects a non-HTTPS PDS endpoint even when
	 * the host would resolve publicly — the spec requires HTTPS.
	 */
	public function test_pds_from_did_doc_rejects_non_https_endpoint() {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => 'http://pds.example.com',
				),
			),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_pds', $result->get_error_code() );
	}

	/**
	 * `pds_from_did_doc` rejects an `at://` or otherwise exotic scheme.
	 */
	public function test_pds_from_did_doc_rejects_exotic_scheme() {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => 'file:///etc/passwd',
				),
			),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_pds', $result->get_error_code() );
	}

	/**
	 * `pds_from_did_doc` returns the endpoint when it is a plain
	 * HTTPS URL pointing at a public host.
	 */
	public function test_pds_from_did_doc_accepts_public_https_endpoint() {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => 'https://pds.example.com',
				),
			),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertSame( 'https://pds.example.com', $result );
	}

	/**
	 * `discover_auth_server` rejects an `authorization_servers[0]`
	 * issuer URL that points at an internal host.
	 */
	public function test_discover_auth_server_rejects_internal_issuer() {
		$this->stub_response(
			'oauth-protected-resource',
			200,
			array( 'authorization_servers' => array( 'http://127.0.0.1:8888' ) )
		);

		$result = Resolver::discover_auth_server( 'https://pds.example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_auth_server', $result->get_error_code() );
	}

	/**
	 * `discover_auth_server` rejects an auth-server `token_endpoint`
	 * pointing at an internal host — even if the issuer URL itself
	 * was safe, the response body can poison the next hop.
	 */
	public function test_discover_auth_server_rejects_internal_token_endpoint() {
		$this->stub_response(
			'oauth-protected-resource',
			200,
			array( 'authorization_servers' => array( 'https://auth.example.com' ) )
		);

		$this->stub_response(
			'oauth-authorization-server',
			200,
			array(
				'token_endpoint'         => 'http://169.254.169.254/latest/meta-data/',
				'authorization_endpoint' => 'https://auth.example.com/oauth/authorize',
			)
		);

		$result = Resolver::discover_auth_server( 'https://pds.example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_token_endpoint', $result->get_error_code() );
	}

	/**
	 * `discover_auth_server` rejects a non-HTTPS `authorization_endpoint`.
	 */
	public function test_discover_auth_server_rejects_non_https_auth_endpoint() {
		$this->stub_response(
			'oauth-protected-resource',
			200,
			array( 'authorization_servers' => array( 'https://auth.example.com' ) )
		);

		$this->stub_response(
			'oauth-authorization-server',
			200,
			array(
				'token_endpoint'         => 'https://auth.example.com/oauth/token',
				'authorization_endpoint' => 'http://auth.example.com/oauth/authorize',
			)
		);

		$result = Resolver::discover_auth_server( 'https://pds.example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_auth_endpoint', $result->get_error_code() );
	}

	/**
	 * `discover_auth_server` rejects an unsafe PAR endpoint when
	 * advertised.
	 */
	public function test_discover_auth_server_rejects_unsafe_par_endpoint() {
		$this->stub_response(
			'oauth-protected-resource',
			200,
			array( 'authorization_servers' => array( 'https://auth.example.com' ) )
		);

		$this->stub_response(
			'oauth-authorization-server',
			200,
			array(
				'token_endpoint'                        => 'https://auth.example.com/oauth/token',
				'authorization_endpoint'                => 'https://auth.example.com/oauth/authorize',
				'pushed_authorization_request_endpoint' => 'http://127.0.0.1:8888/par',
			)
		);

		$result = Resolver::discover_auth_server( 'https://pds.example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_par_endpoint', $result->get_error_code() );
	}

	/**
	 * `discover_auth_server` returns the metadata when every URL in
	 * the chain is a plain HTTPS URL on a public host.
	 */
	public function test_discover_auth_server_accepts_clean_chain() {
		$this->stub_response(
			'oauth-protected-resource',
			200,
			array( 'authorization_servers' => array( 'https://auth.example.com' ) )
		);

		$this->stub_response(
			'oauth-authorization-server',
			200,
			array(
				'token_endpoint'         => 'https://auth.example.com/oauth/token',
				'authorization_endpoint' => 'https://auth.example.com/oauth/authorize',
			)
		);

		$result = Resolver::discover_auth_server( 'https://pds.example.com' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://auth.example.com', $result['issuer_url'] );
		$this->assertSame( 'https://auth.example.com/oauth/token', $result['token_endpoint'] );
	}

	/**
	 * `discover_auth_server` defers seed-URL safety to `wp_safe_remote_get`.
	 * Passing an obviously-unsafe URL should still surface a `WP_Error`
	 * (from WordPress's host-safety gate), even though we no longer
	 * pre-validate the seed in plugin code.
	 */
	public function test_discover_auth_server_rejects_unsafe_seed_pds_via_safe_remote() {
		$result = Resolver::discover_auth_server( 'http://127.0.0.1:8888' );

		$this->assertWPError( $result );
	}

	/**
	 * `pds_from_did_doc` rejects a `serviceEndpoint` that contains
	 * embedded HTTP credentials. URLs with `user:pass@host` are a
	 * known injection vector — the credentials would otherwise be
	 * persisted into the connection and sent on every request.
	 */
	public function test_pds_from_did_doc_rejects_credentials_in_url() {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => 'https://attacker:secret@pds.example.com',
				),
			),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_pds', $result->get_error_code() );
	}
}
