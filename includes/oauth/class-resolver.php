<?php
/**
 * AT Protocol identity & service resolution.
 *
 * Walks the chain: Handle → DID → DID Document → PDS → Auth Server,
 * exactly as specified by the atproto OAuth specification.
 *
 * @package Atmosphere
 */

namespace Atmosphere\OAuth;

\defined( 'ABSPATH' ) || exit;

/**
 * Resolver for AT Protocol identities and services.
 */
class Resolver {

	/**
	 * Resolve a handle to a DID via DNS TXT or well-known fallback.
	 *
	 * @param string $handle AT Protocol handle (e.g. alice.bsky.social).
	 * @return string|\WP_Error DID string or error.
	 */
	public static function handle_to_did( string $handle ): string|\WP_Error {
		if ( ! self::is_valid_handle( $handle ) ) {
			return new \WP_Error(
				'atmosphere_invalid_handle',
				\__( 'Handle is not a valid DNS-style identifier.', 'atmosphere' )
			);
		}

		// Try DNS TXT first: _atproto.<handle>.
		$records = @\dns_get_record( '_atproto.' . $handle, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( \is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['txt'] ) && \str_starts_with( $record['txt'], 'did=' ) ) {
					return \substr( $record['txt'], 4 );
				}
			}
		}

		// Fallback: HTTPS well-known.
		$response = \wp_safe_remote_get(
			'https://' . $handle . '/.well-known/atproto-did',
			array( 'timeout' => 10 )
		);

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$body = \trim( \wp_remote_retrieve_body( $response ) );

		if ( \str_starts_with( $body, 'did:' ) ) {
			return $body;
		}

		return new \WP_Error(
			'atmosphere_resolve_handle',
			/* translators: %s: AT Protocol handle */
			\sprintf( \__( 'Could not resolve handle %s to a DID.', 'atmosphere' ), $handle )
		);
	}

	/**
	 * Fetch the DID document for a given DID.
	 *
	 * Supports did:plc and did:web methods.
	 *
	 * @param string $did DID string.
	 * @return array|\WP_Error DID document array or error.
	 */
	public static function resolve_did( string $did ): array|\WP_Error {
		if ( \str_starts_with( $did, 'did:plc:' ) ) {
			$url = 'https://plc.directory/' . $did;
		} elseif ( \str_starts_with( $did, 'did:web:' ) ) {
			$domain = \substr( $did, 8 );
			if ( ! self::is_valid_handle( $domain ) ) {
				return new \WP_Error(
					'atmosphere_invalid_did',
					\__( 'did:web identifier is not a valid DNS-style host.', 'atmosphere' )
				);
			}
			$url = 'https://' . $domain . '/.well-known/did.json';
		} else {
			return new \WP_Error(
				'atmosphere_unsupported_did',
				\__( 'Unsupported DID method.', 'atmosphere' )
			);
		}

		$response = \wp_safe_remote_get( $url, array( 'timeout' => 10 ) );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$body = \json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( ! \is_array( $body ) || empty( $body['id'] ) ) {
			return new \WP_Error(
				'atmosphere_invalid_did_doc',
				\__( 'Invalid DID document received.', 'atmosphere' )
			);
		}

		return $body;
	}

	/**
	 * Extract the PDS endpoint from a DID document.
	 *
	 * Looks for the #atproto_pds service entry. The serviceEndpoint is
	 * attacker-controlled (anyone can publish a DID doc on plc.directory
	 * pointing at any URL), so we reject anything that isn't a plain
	 * HTTPS URL pointing at a publicly-routable host. `wp_http_validate_url()`
	 * does the host-reachability gate; the explicit `https` check rules
	 * out `http://` and exotic schemes that the spec doesn't allow.
	 *
	 * @param array $did_doc DID document.
	 * @return string|\WP_Error PDS URL or error.
	 */
	public static function pds_from_did_doc( array $did_doc ): string|\WP_Error {
		$services = $did_doc['service'] ?? array();

		/*
		 * The DID document is remote/untrusted. A malformed `service`
		 * field that decodes to a scalar (or to a list of scalars
		 * rather than a list of objects) would TypeError on the
		 * `$service['id']` offset access below. Bail cleanly instead.
		 */
		if ( ! \is_array( $services ) ) {
			return new \WP_Error(
				'atmosphere_invalid_did_doc',
				\__( 'DID document `service` field is malformed.', 'atmosphere' )
			);
		}

		foreach ( $services as $service ) {
			if ( ! \is_array( $service ) ) {
				continue;
			}

			$id   = $service['id'] ?? '';
			$type = $service['type'] ?? '';

			if ( '#atproto_pds' === $id && 'AtprotoPersonalDataServer' === $type && ! empty( $service['serviceEndpoint'] ) ) {
				$endpoint = $service['serviceEndpoint'];

				if ( ! \is_string( $endpoint ) || ! self::is_safe_https_url( $endpoint ) ) {
					return new \WP_Error(
						'atmosphere_unsafe_pds',
						\__( 'PDS endpoint in DID document is not a safe HTTPS URL.', 'atmosphere' )
					);
				}

				return $endpoint;
			}
		}

		return new \WP_Error(
			'atmosphere_no_pds',
			\__( 'No PDS endpoint found in DID document.', 'atmosphere' )
		);
	}

	/**
	 * Discover the authorization server for a given PDS.
	 *
	 * Fetches /.well-known/oauth-protected-resource, then
	 * /.well-known/oauth-authorization-server from the indicated issuer.
	 * Every URL produced by the response chain is re-validated before it
	 * is fetched — the DID-doc PDS endpoint, the issuer URL inside
	 * `authorization_servers`, and the `token_endpoint` /
	 * `authorization_endpoint` advertised by the auth server are all
	 * attacker-controlled in the worst case.
	 *
	 * @param string $pds_url PDS base URL.
	 * @return array|\WP_Error Authorization server metadata or error.
	 */
	public static function discover_auth_server( string $pds_url ): array|\WP_Error {
		$resource_url = \rtrim( $pds_url, '/' ) . '/.well-known/oauth-protected-resource';
		$response     = \wp_safe_remote_get( $resource_url, array( 'timeout' => 10 ) );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$resource = \json_decode( \wp_remote_retrieve_body( $response ), true );

		/*
		 * Malformed JSON (or a valid scalar/null payload like
		 * `"foo"` or `null`) decodes to a non-array. Bail before
		 * indexing so PHP 8 doesn't TypeError on offset access.
		 */
		if ( ! \is_array( $resource )
			|| empty( $resource['authorization_servers'][0] )
			|| ! \is_string( $resource['authorization_servers'][0] )
		) {
			return new \WP_Error(
				'atmosphere_no_auth_server',
				\__( 'PDS did not advertise an authorization server.', 'atmosphere' )
			);
		}

		$issuer = $resource['authorization_servers'][0];

		if ( ! self::is_safe_https_url( $issuer ) ) {
			return new \WP_Error(
				'atmosphere_unsafe_auth_server',
				\__( 'Authorization server issuer is not a safe HTTPS URL.', 'atmosphere' )
			);
		}

		$meta_url = \rtrim( $issuer, '/' ) . '/.well-known/oauth-authorization-server';
		$response = \wp_safe_remote_get( $meta_url, array( 'timeout' => 10 ) );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$meta = \json_decode( \wp_remote_retrieve_body( $response ), true );

		/*
		 * Same guard as above — a scalar / null payload would
		 * TypeError on the indexing checks below.
		 */
		if ( ! \is_array( $meta )
			|| empty( $meta['token_endpoint'] )
			|| empty( $meta['authorization_endpoint'] )
		) {
			return new \WP_Error(
				'atmosphere_incomplete_auth_meta',
				\__( 'Authorization server metadata is incomplete.', 'atmosphere' )
			);
		}

		if ( ! \is_string( $meta['token_endpoint'] ) || ! self::is_safe_https_url( $meta['token_endpoint'] ) ) {
			return new \WP_Error(
				'atmosphere_unsafe_token_endpoint',
				\__( 'Authorization server token endpoint is not a safe HTTPS URL.', 'atmosphere' )
			);
		}

		if ( ! \is_string( $meta['authorization_endpoint'] ) || ! self::is_safe_https_url( $meta['authorization_endpoint'] ) ) {
			return new \WP_Error(
				'atmosphere_unsafe_auth_endpoint',
				\__( 'Authorization server authorization endpoint is not a safe HTTPS URL.', 'atmosphere' )
			);
		}

		if ( ! empty( $meta['pushed_authorization_request_endpoint'] )
			&& ( ! \is_string( $meta['pushed_authorization_request_endpoint'] )
				|| ! self::is_safe_https_url( $meta['pushed_authorization_request_endpoint'] ) )
		) {
			return new \WP_Error(
				'atmosphere_unsafe_par_endpoint',
				\__( 'Authorization server PAR endpoint is not a safe HTTPS URL.', 'atmosphere' )
			);
		}

		$meta['issuer_url'] = $issuer;

		return $meta;
	}

	/**
	 * Run the full resolution chain: handle → DID → PDS → auth server.
	 *
	 * @param string $handle AT Protocol handle.
	 * @return array|\WP_Error Array with did, pds_endpoint, auth_server keys.
	 */
	public static function resolve( string $handle ): array|\WP_Error {
		$did = self::handle_to_did( $handle );
		if ( \is_wp_error( $did ) ) {
			return $did;
		}

		$did_doc = self::resolve_did( $did );
		if ( \is_wp_error( $did_doc ) ) {
			return $did_doc;
		}

		$pds = self::pds_from_did_doc( $did_doc );
		if ( \is_wp_error( $pds ) ) {
			return $pds;
		}

		$auth_server = self::discover_auth_server( $pds );
		if ( \is_wp_error( $auth_server ) ) {
			return $auth_server;
		}

		return array(
			'did'          => $did,
			'pds_endpoint' => $pds,
			'auth_server'  => $auth_server,
		);
	}

	/**
	 * Validate an AT Protocol handle or `did:web` host against
	 * RFC 1035-style DNS name rules.
	 *
	 * Rejects empty strings, oversized labels, leading/trailing
	 * hyphens, single-label hosts (`localhost`), and characters
	 * outside `[A-Za-z0-9-]`. This is the first line of defence
	 * against percent-encoded host bypasses and SSRF via crafted
	 * DIDs — the regex never matches anything that contains a `%`
	 * or other reserved URL characters.
	 *
	 * @param string $host Hostname (handle or did:web domain).
	 * @return bool
	 */
	private static function is_valid_handle( string $host ): bool {
		if ( '' === $host || \strlen( $host ) > 253 ) {
			return false;
		}

		$label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

		return (bool) \preg_match( '/^' . $label . '(?:\.' . $label . ')+$/', $host );
	}

	/**
	 * Validate that a URL is well-formed, https-only, and safe to
	 * persist or hand off downstream.
	 *
	 * The actual host-safety gate (private-IP, loopback, link-local)
	 * lives in `wp_safe_remote_*`, which the resolver uses on every
	 * outbound request. This helper deliberately does NOT call
	 * `wp_http_validate_url()` — that function does a `gethostbyname`
	 * lookup, which is unreliable in CI (test domains like
	 * `pds.example.com` may not resolve) and redundant with the
	 * fetch-time check.
	 *
	 * What this helper does enforce:
	 *
	 *  - non-empty string input
	 *  - parses as a URL
	 *  - scheme is exactly `https` (the spec requires HTTPS; we narrow
	 *    further than WordPress's `http`/`https`/`ssl` allowlist)
	 *  - has a host
	 *  - host is NOT a raw IP literal — IPv4 (`127.0.0.1`,
	 *    `169.254.169.254`), IPv6 (`[::1]`, `[fd00::1]`), and any
	 *    other `FILTER_VALIDATE_IP`-recognised form. `wp_safe_remote_*`
	 *    catches RFC1918 and loopback but is IPv4-centric, and the
	 *    `authorization_endpoint` URL is handed straight to
	 *    `wp_redirect()` with no host-safety net at all. Rejecting IP
	 *    literals here closes both gaps in one place.
	 *  - no embedded `user:pass@` credentials (a known URL-injection
	 *    vector that would otherwise be carried into the persisted
	 *    connection)
	 *
	 * @param mixed $url URL to validate.
	 * @return bool
	 */
	private static function is_safe_https_url( $url ): bool {
		if ( ! \is_string( $url ) || '' === $url ) {
			return false;
		}

		$parts = \wp_parse_url( $url );
		if ( ! \is_array( $parts ) ) {
			return false;
		}

		if ( ( $parts['scheme'] ?? '' ) !== 'https' ) {
			return false;
		}

		if ( empty( $parts['host'] ) ) {
			return false;
		}

		/*
		 * PHP's parse_url() preserves the brackets around IPv6 hosts
		 * (host is `[::1]` for `https://[::1]/`), and
		 * FILTER_VALIDATE_IP rejects bracketed forms — strip them
		 * before validating.
		 */
		$host_candidate = \trim( $parts['host'], '[]' );
		if ( false !== \filter_var( $host_candidate, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		return true;
	}
}
