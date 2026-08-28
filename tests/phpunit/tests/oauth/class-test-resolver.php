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
	 * @param array  $headers   Response headers (e.g. `retry-after`).
	 */
	private function stub_response( string $url_match, int $status, $body, array $headers = array() ): void {
		\add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( $url_match, $status, $body, $headers ) {
				if ( false !== \strpos( $url, $url_match ) ) {
					return array(
						'response' => array( 'code' => $status ),
						'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
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
	 * The AT Protocol handle spec rejects TLDs that are entirely
	 * numeric or that start with a digit (`alice.bsky.123`,
	 * `alice.bsky.9foo`) — these aren't real TLDs and overlap with
	 * IP-literal territory.
	 *
	 * @dataProvider provide_numeric_tld_handles
	 *
	 * @param string $handle Handle under test.
	 */
	public function test_handle_to_did_rejects_numeric_tld( string $handle ) {
		$result = Resolver::handle_to_did( $handle );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_invalid_handle', $result->get_error_code() );
	}

	/**
	 * Data provider — handles with numeric TLDs.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_numeric_tld_handles(): array {
		return array(
			'all-numeric'       => array( 'alice.bsky.123' ),
			'starts-with-digit' => array( 'alice.bsky.9foo' ),
		);
	}

	/**
	 * The AT Protocol handle spec disallows reserved / private-use
	 * TLDs — `.local`, `.localhost`, `.arpa`, `.internal`,
	 * `.invalid`, `.onion`, `.test`, `.example`, `.alt`. Reject them
	 * before any network lookup.
	 *
	 * @dataProvider provide_reserved_tld_handles
	 *
	 * @param string $handle Handle under test.
	 */
	public function test_handle_to_did_rejects_reserved_tld( string $handle ) {
		$result = Resolver::handle_to_did( $handle );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_invalid_handle', $result->get_error_code() );
	}

	/**
	 * Data provider — handles whose TLDs are on the reserved list.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_reserved_tld_handles(): array {
		return array(
			'local'     => array( 'alice.local' ),
			'localhost' => array( 'alice.localhost' ),
			'arpa'      => array( 'alice.arpa' ),
			'internal'  => array( 'alice.internal' ),
			'invalid'   => array( 'alice.invalid' ),
			'onion'     => array( 'alice.onion' ),
			'test'      => array( 'alice.test' ),
			'example'   => array( 'alice.example' ),
			'alt'       => array( 'alice.alt' ),
		);
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
	 * `pds_from_did_doc` treats the scheme as case-insensitive per
	 * RFC 3986 — `HTTPS://pds.example.com` is the same as
	 * `https://pds.example.com` and must be accepted.
	 */
	public function test_pds_from_did_doc_accepts_uppercase_https_scheme() {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => 'HTTPS://pds.example.com',
				),
			),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertSame( 'HTTPS://pds.example.com', $result );
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
	 * `pds_from_did_doc` rejects HTTPS URLs whose host is a raw IPv4
	 * literal — including link-local cloud-metadata addresses that
	 * sit outside WordPress's IPv4 private-range blocklist.
	 *
	 * @dataProvider provide_ipv4_literal_endpoints
	 *
	 * @param string $endpoint serviceEndpoint URL under test.
	 */
	public function test_pds_from_did_doc_rejects_ipv4_literal_endpoint( string $endpoint ) {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => $endpoint,
				),
			),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_pds', $result->get_error_code() );
	}

	/**
	 * Data provider — IPv4 literals that must never be accepted as a
	 * PDS / auth-server endpoint.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_ipv4_literal_endpoints(): array {
		return array(
			'loopback'        => array( 'https://127.0.0.1' ),
			'aws-metadata'    => array( 'https://169.254.169.254/latest/meta-data/' ),
			'rfc1918-10/8'    => array( 'https://10.0.0.1' ),
			'rfc1918-192.168' => array( 'https://192.168.1.1' ),
			'unspecified'     => array( 'https://0.0.0.0' ),
		);
	}

	/**
	 * `pds_from_did_doc` rejects HTTPS URLs whose host is a raw IPv6
	 * literal. PHP's `parse_url()` returns IPv6 hosts wrapped in
	 * brackets, so the IP-literal gate has to strip them before
	 * handing off to `FILTER_VALIDATE_IP`.
	 *
	 * @dataProvider provide_ipv6_literal_endpoints
	 *
	 * @param string $endpoint serviceEndpoint URL under test.
	 */
	public function test_pds_from_did_doc_rejects_ipv6_literal_endpoint( string $endpoint ) {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => $endpoint,
				),
			),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_pds', $result->get_error_code() );
	}

	/**
	 * Data provider — IPv6 literals (loopback + unique-local).
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_ipv6_literal_endpoints(): array {
		return array(
			'loopback'     => array( 'https://[::1]' ),
			'unique-local' => array( 'https://[fd00::1]' ),
		);
	}

	/**
	 * `pds_from_did_doc` rejects HTTPS URLs whose host hides an IP
	 * literal behind percent-encoding. The gate validates the host as
	 * parsed, but any layer that normalizes the URL afterwards (cURL,
	 * a proxy, a redirect) may decode `%31%32%37.0.0.1` back to
	 * `127.0.0.1` — so the gate has to judge the decoded form.
	 *
	 * @dataProvider provide_pct_encoded_ip_endpoints
	 *
	 * @param string $endpoint serviceEndpoint URL under test.
	 */
	public function test_pds_from_did_doc_rejects_pct_encoded_ip_endpoint( string $endpoint ) {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => $endpoint,
				),
			),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_pds', $result->get_error_code() );
	}

	/**
	 * Data provider — percent-encoded IP-literal hosts that must never
	 * be accepted as a PDS / auth-server endpoint.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_pct_encoded_ip_endpoints(): array {
		return array(
			'encoded-ipv4-loopback' => array( 'https://%31%32%37.0.0.1' ),
			'partially-encoded'     => array( 'https://127.0.0.%31' ),
			'encoded-ipv6-loopback' => array( 'https://[%3A%3A1]' ),
			'double-encoded'        => array( 'https://%2531%2532%2537.0.0.1' ),
		);
	}

	/**
	 * `pds_from_did_doc` rejects HTTPS URLs whose host hides a URL
	 * delimiter behind percent-encoding. `wp_parse_url()` leaves the
	 * delimiter encoded — so `user`/`pass` stay unset and the host
	 * isn't a bare IP — but a downstream layer that decodes the host
	 * reinterprets `user%40127.0.0.1` as `userinfo@host` (routing to
	 * loopback) or `127.0.0.1%3A443` as `host:port`.
	 *
	 * @dataProvider provide_pct_encoded_delimiter_endpoints
	 *
	 * @param string $endpoint serviceEndpoint URL under test.
	 */
	public function test_pds_from_did_doc_rejects_pct_encoded_delimiter_endpoint( string $endpoint ) {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array(
				array(
					'id'              => '#atproto_pds',
					'type'            => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => $endpoint,
				),
			),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_unsafe_pds', $result->get_error_code() );
	}

	/**
	 * Data provider — percent-encoded URL-delimiter hosts that must
	 * never be accepted as a PDS / auth-server endpoint.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_pct_encoded_delimiter_endpoints(): array {
		return array(
			'encoded-userinfo-at' => array( 'https://user%40127.0.0.1' ),
			'encoded-port-colon'  => array( 'https://127.0.0.1%3A443' ),
			'encoded-path-slash'  => array( 'https://evil.example%2Fpds.lan' ),
			'encoded-query-mark'  => array( 'https://evil.example%3Ffoo=bar.lan' ),
		);
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

	/**
	 * `discover_auth_server` doesn't fatal when the
	 * `oauth-protected-resource` body decodes to a non-array
	 * (e.g. a JSON string `"foo"` or `null`). Returns the same
	 * `atmosphere_no_auth_server` error as a missing
	 * `authorization_servers` field.
	 */
	public function test_discover_auth_server_tolerates_scalar_json_resource() {
		$this->stub_response( 'oauth-protected-resource', 200, 'just-a-string-not-an-object' );

		$result = Resolver::discover_auth_server( 'https://pds.example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_no_auth_server', $result->get_error_code() );
	}

	/**
	 * `discover_auth_server` doesn't emit a PHP 8.1+ offset-access
	 * warning when `authorization_servers` is itself a scalar
	 * (`"foo"` rather than `["https://…"]`). `empty()` on the inner
	 * index would otherwise force PHP to read offset `0` of a scalar
	 * before short-circuiting.
	 */
	public function test_discover_auth_server_tolerates_scalar_authorization_servers() {
		$this->stub_response(
			'oauth-protected-resource',
			200,
			array( 'authorization_servers' => 'not-a-list' )
		);

		$result = Resolver::discover_auth_server( 'https://pds.example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_no_auth_server', $result->get_error_code() );
	}

	/**
	 * `discover_auth_server` doesn't fatal when the
	 * `oauth-authorization-server` body decodes to a non-array.
	 * Returns `atmosphere_incomplete_auth_meta`.
	 */
	public function test_discover_auth_server_tolerates_scalar_json_meta() {
		$this->stub_response(
			'oauth-protected-resource',
			200,
			array( 'authorization_servers' => array( 'https://auth.example.com' ) )
		);

		$this->stub_response( 'oauth-authorization-server', 200, 'malformed' );

		$result = Resolver::discover_auth_server( 'https://pds.example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_incomplete_auth_meta', $result->get_error_code() );
	}

	/**
	 * `pds_from_did_doc` doesn't fatal when `service` is a scalar
	 * (rather than the expected list of objects). Returns
	 * `atmosphere_invalid_did_doc`.
	 */
	public function test_pds_from_did_doc_tolerates_non_array_service_field() {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => 'not-an-array',
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_invalid_did_doc', $result->get_error_code() );
	}

	/**
	 * `pds_from_did_doc` skips entries inside `service` that aren't
	 * themselves arrays (e.g. a malformed DID doc that's a list of
	 * scalars instead of a list of objects). Falls through to
	 * `atmosphere_no_pds` rather than TypeErroring on `$service['id']`.
	 */
	public function test_pds_from_did_doc_tolerates_scalar_service_entries() {
		$did_doc = array(
			'id'      => 'did:plc:test',
			'service' => array( 'string-entry-1', 42, null ),
		);

		$result = Resolver::pds_from_did_doc( $did_doc );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_no_pds', $result->get_error_code() );
	}

	/**
	 * A 5xx from plc.directory surfaces as a distinct upstream error, not
	 * as `atmosphere_invalid_did_doc`, so a transient outage is not mistaken
	 * for a malformed document. The status rides along in the error data.
	 */
	public function test_resolve_did_surfaces_upstream_error_on_5xx() {
		$this->stub_response( 'plc.directory', 503, '<html>Service Unavailable</html>' );

		$result = Resolver::resolve_did( 'did:plc:test123' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_upstream_error', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['upstream_status'] );
	}

	/**
	 * A 429 surfaces as a rate-limit error, and the `Retry-After` header is
	 * carried into the message.
	 */
	public function test_resolve_did_surfaces_rate_limit_with_retry_after() {
		$this->stub_response( 'plc.directory', 429, '{"error":"RateLimitExceeded"}', array( 'retry-after' => '30' ) );

		$result = Resolver::resolve_did( 'did:plc:test123' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_upstream_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['upstream_status'] );
		$this->assertStringContainsString( '30 seconds', $result->get_error_message() );
	}

	/**
	 * A 5xx on the `oauth-protected-resource` fetch surfaces as an upstream
	 * error rather than "PDS did not advertise an authorization server".
	 */
	public function test_discover_auth_server_surfaces_upstream_error_on_5xx() {
		$this->stub_response( 'oauth-protected-resource', 500, '<html>Internal Server Error</html>' );

		$result = Resolver::discover_auth_server( 'https://pds.example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_upstream_error', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['upstream_status'] );
	}

	/**
	 * A 5xx on the second fetch — the auth-server metadata document — also
	 * surfaces as an upstream error, not "Authorization server metadata is
	 * incomplete". The first fetch succeeds so this exercises that call site.
	 */
	public function test_discover_auth_server_surfaces_upstream_error_on_metadata_5xx() {
		$this->stub_response(
			'oauth-protected-resource',
			200,
			array( 'authorization_servers' => array( 'https://auth.example.com' ) )
		);
		$this->stub_response( 'oauth-authorization-server', 503, '<html>Service Unavailable</html>' );

		$result = Resolver::discover_auth_server( 'https://pds.example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_upstream_error', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['upstream_status'] );
	}

	/**
	 * A hostile `Retry-After` never reaches the message. The value comes from
	 * a host the submitted handle chose, and the message is printed unescaped
	 * by `settings_errors()`, so anything non-numeric has to be dropped rather
	 * than displayed.
	 */
	public function test_rate_limit_message_drops_a_hostile_retry_after() {
		$this->stub_response(
			'plc.directory',
			429,
			'{"error":"RateLimitExceeded"}',
			array( 'retry-after' => '<script>alert(1)</script>' )
		);

		$message = Resolver::resolve_did( 'did:plc:test123' )->get_error_message();

		$this->assertStringNotContainsString( '<script>', $message );
		$this->assertStringNotContainsString( 'alert', $message );
		$this->assertStringNotContainsString( 'Retry after', $message );
	}

	/**
	 * An HTTP-date `Retry-After` is dropped too rather than interpolated, and
	 * the caller still gets the rate-limit code.
	 */
	public function test_rate_limit_message_drops_an_http_date_retry_after() {
		$this->stub_response(
			'plc.directory',
			429,
			'{"error":"RateLimitExceeded"}',
			array( 'retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT' )
		);

		$result = Resolver::resolve_did( 'did:plc:test123' );

		$this->assertSame( 'atmosphere_upstream_rate_limited', $result->get_error_code() );
		$this->assertStringNotContainsString( 'Retry after', $result->get_error_message() );
	}

	/**
	 * `Retry-After: 0` used to be dropped because the string is falsy. Zero is
	 * not a useful wait, so it still takes the no-value message, but by way of
	 * the range check rather than by accident.
	 */
	public function test_rate_limit_message_handles_a_zero_retry_after() {
		$this->stub_response( 'plc.directory', 429, '{}', array( 'retry-after' => '0' ) );

		$this->assertStringNotContainsString(
			'Retry after',
			Resolver::resolve_did( 'did:plc:test123' )->get_error_message()
		);
	}

	/**
	 * `is_numeric()` accepts a negative, which would otherwise render as
	 * "Retry after -30 seconds". The range check takes it to the no-value
	 * message instead.
	 */
	public function test_rate_limit_message_drops_a_negative_retry_after() {
		$this->stub_response( 'plc.directory', 429, '{}', array( 'retry-after' => '-30' ) );

		$message = Resolver::resolve_did( 'did:plc:test123' )->get_error_message();

		$this->assertStringNotContainsString( 'Retry after', $message );
		$this->assertStringNotContainsString( '-30', $message );
	}

	/**
	 * `is_numeric()` also accepts exponent notation, where `1e5` expands to a
	 * 27-hour wait. Anything past a day is not a plausible delta-seconds value,
	 * so it takes the no-value message.
	 */
	public function test_rate_limit_message_drops_an_implausibly_large_retry_after() {
		$this->stub_response( 'plc.directory', 429, '{}', array( 'retry-after' => '1e5' ) );

		$message = Resolver::resolve_did( 'did:plc:test123' )->get_error_message();

		$this->assertStringNotContainsString( 'Retry after', $message );
		$this->assertStringNotContainsString( '100000', $message );
	}

	/**
	 * The status is deliberately not stored under `status`: that key is what
	 * `rest_convert_error_to_response()` reads, so an upstream 401 must not be
	 * able to become the site's own REST response code.
	 */
	public function test_upstream_status_is_not_stored_under_the_rest_status_key() {
		$this->stub_response( 'plc.directory', 401, '{}' );

		$data = Resolver::resolve_did( 'did:plc:test123' )->get_error_data();

		$this->assertSame( 401, $data['upstream_status'] );
		$this->assertArrayNotHasKey( 'status', $data );
	}

	/**
	 * The well-known fetch points at the user's own domain, so it is the hop
	 * most likely to sit behind a CDN or WAF. A 503 there must read as an
	 * upstream failure rather than as an unresolvable handle.
	 */
	public function test_handle_to_did_surfaces_upstream_error_on_5xx() {
		$this->stub_response( 'example.com/.well-known/atproto-did', 503, '<html>Service Unavailable</html>' );

		$result = Resolver::handle_to_did( 'example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_upstream_error', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['upstream_status'] );
	}

	/**
	 * A missing well-known file is not an outage: the host simply has not set
	 * handle verification up, so 404 keeps falling through to the existing
	 * resolve-handle message rather than claiming the upstream is down.
	 */
	public function test_handle_to_did_lets_a_404_fall_through() {
		$this->stub_response( 'example.com/.well-known/atproto-did', 404, 'Not Found' );

		$result = Resolver::handle_to_did( 'example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_resolve_handle', $result->get_error_code() );
	}

	/**
	 * A non-2xx body that happens to start with `did:` is not a DID: without
	 * the status gate an error page could be accepted as an identity.
	 */
	public function test_handle_to_did_rejects_a_did_shaped_error_body() {
		$this->stub_response( 'example.com/.well-known/atproto-did', 500, 'did:plc:attacker' );

		$result = Resolver::handle_to_did( 'example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'atmosphere_upstream_error', $result->get_error_code() );
	}
}
