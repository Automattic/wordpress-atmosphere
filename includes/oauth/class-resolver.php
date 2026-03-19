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
		$response = \wp_remote_get(
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
			$url    = 'https://' . $domain . '/.well-known/did.json';
		} else {
			return new \WP_Error(
				'atmosphere_unsupported_did',
				\__( 'Unsupported DID method.', 'atmosphere' )
			);
		}

		$response = \wp_remote_get( $url, array( 'timeout' => 10 ) );

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
	 * Looks for the #atproto_pds service entry.
	 *
	 * @param array $did_doc DID document.
	 * @return string|\WP_Error PDS URL or error.
	 */
	public static function pds_from_did_doc( array $did_doc ): string|\WP_Error {
		foreach ( $did_doc['service'] ?? array() as $service ) {
			$id   = $service['id'] ?? '';
			$type = $service['type'] ?? '';

			if ( '#atproto_pds' === $id && 'AtprotoPersonalDataServer' === $type && ! empty( $service['serviceEndpoint'] ) ) {
				return $service['serviceEndpoint'];
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
	 *
	 * @param string $pds_url PDS base URL.
	 * @return array|\WP_Error Authorization server metadata or error.
	 */
	public static function discover_auth_server( string $pds_url ): array|\WP_Error {
		$resource_url = \rtrim( $pds_url, '/' ) . '/.well-known/oauth-protected-resource';
		$response     = \wp_remote_get( $resource_url, array( 'timeout' => 10 ) );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$resource = \json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( empty( $resource['authorization_servers'][0] ) ) {
			return new \WP_Error(
				'atmosphere_no_auth_server',
				\__( 'PDS did not advertise an authorization server.', 'atmosphere' )
			);
		}

		$issuer   = $resource['authorization_servers'][0];
		$meta_url = \rtrim( $issuer, '/' ) . '/.well-known/oauth-authorization-server';
		$response = \wp_remote_get( $meta_url, array( 'timeout' => 10 ) );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$meta = \json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( empty( $meta['token_endpoint'] ) || empty( $meta['authorization_endpoint'] ) ) {
			return new \WP_Error(
				'atmosphere_incomplete_auth_meta',
				\__( 'Authorization server metadata is incomplete.', 'atmosphere' )
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
}
