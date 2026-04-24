<?php
/**
 * DPoP-authenticated HTTP client for AT Protocol PDS operations.
 *
 * All requests carry a DPoP proof and automatically retry once
 * when the server responds with a use_dpop_nonce error.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Client;
use Atmosphere\OAuth\DPoP;
use Atmosphere\OAuth\Encryption;

/**
 * PDS API client.
 */
class API {

	/**
	 * Send a DPoP-authenticated request to the connected PDS.
	 *
	 * @param string      $method   HTTP method.
	 * @param string      $endpoint XRPC path, e.g. /xrpc/com.atproto.repo.createRecord.
	 * @param array       $args     wp_remote_request() arguments.
	 * @param string|null $nonce    Explicit DPoP nonce (used on retry).
	 * @return array|\WP_Error Decoded JSON body or error.
	 */
	public static function request( string $method, string $endpoint, array $args = array(), ?string $nonce = null ): array|\WP_Error {
		$original_args = $args;

		$access_token = Client::access_token();
		if ( \is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$conn = \get_option( 'atmosphere_connection', array() );

		$dpop_jwk_json = Encryption::decrypt( $conn['dpop_jwk'] ?? '' );
		if ( false === $dpop_jwk_json ) {
			return new \WP_Error( 'atmosphere_decrypt', \__( 'Failed to decrypt DPoP key.', 'atmosphere' ) );
		}

		$dpop_jwk = \json_decode( $dpop_jwk_json, true );
		$pds      = \rtrim( $conn['pds_endpoint'], '/' );
		$url      = $pds . $endpoint;

		$content_type = $args['headers']['Content-Type'] ?? 'application/json';
		unset( $args['headers'] );

		$dpop_proof = DPoP::create_proof( $dpop_jwk, $method, $url, $nonce, $access_token );
		if ( false === $dpop_proof ) {
			return new \WP_Error( 'atmosphere_dpop', \__( 'Failed to create DPoP proof.', 'atmosphere' ) );
		}

		$defaults = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'DPoP ' . $access_token,
				'Content-Type'  => $content_type,
				'DPoP'          => $dpop_proof,
			),
			'timeout' => 30,
		);

		$args = \wp_parse_args( $args, $defaults );

		if ( ! empty( $args['body'] ) && \is_array( $args['body'] ) ) {
			$args['body'] = \wp_json_encode( $args['body'] );
		}

		$response = \wp_remote_request( $url, $args );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$status = \wp_remote_retrieve_response_code( $response );
		$body   = \json_decode( \wp_remote_retrieve_body( $response ), true );

		// Persist any nonce the server sends back.
		$response_nonce = \wp_remote_retrieve_header( $response, 'dpop-nonce' );
		if ( $response_nonce ) {
			DPoP::persist_nonce( $dpop_jwk, $url, $response_nonce );
		}

		// Retry once on nonce error.
		if ( null === $nonce
			&& $response_nonce
			&& \in_array( $status, array( 400, 401 ), true )
			&& ( $body['error'] ?? '' ) === 'use_dpop_nonce'
		) {
			return self::request( $method, $endpoint, $original_args, $response_nonce );
		}

		if ( $status >= 400 ) {
			$msg = $body['message'] ?? ( $body['error'] ?? \__( 'PDS request failed.', 'atmosphere' ) );
			return new \WP_Error( 'atmosphere_pds', $msg, array( 'status' => $status ) );
		}

		return \is_array( $body ) ? $body : array();
	}

	/**
	 * Shorthand GET to the PDS.
	 *
	 * @param string $endpoint XRPC path.
	 * @param array  $params   Query parameters.
	 * @return array|\WP_Error
	 */
	public static function get( string $endpoint, array $params = array() ): array|\WP_Error {
		if ( ! empty( $params ) ) {
			$endpoint .= '?' . \http_build_query( $params );
		}

		return self::request( 'GET', $endpoint );
	}

	/**
	 * Shorthand POST to the PDS.
	 *
	 * @param string $endpoint XRPC path.
	 * @param array  $body     Request body.
	 * @return array|\WP_Error
	 */
	public static function post( string $endpoint, array $body = array() ): array|\WP_Error {
		return self::request( 'POST', $endpoint, array( 'body' => $body ) );
	}

	/**
	 * Upload a blob (image) to the PDS.
	 *
	 * @param string $file_path Local file path.
	 * @param string $mime_type MIME type.
	 * @return array|\WP_Error Blob reference from PDS.
	 */
	public static function upload_blob( string $file_path, string $mime_type ): array|\WP_Error {
		if ( ! \is_readable( $file_path ) ) {
			return new \WP_Error( 'atmosphere_file', \__( 'File not found or not readable.', 'atmosphere' ) );
		}

		$contents = \file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents ) {
			return new \WP_Error( 'atmosphere_file', \__( 'Could not read file.', 'atmosphere' ) );
		}

		return self::request(
			'POST',
			'/xrpc/com.atproto.repo.uploadBlob',
			array(
				'body'    => $contents,
				'headers' => array( 'Content-Type' => $mime_type ),
				'timeout' => 60,
			)
		);
	}

	/**
	 * Execute an atomic batch of write operations.
	 *
	 * @param array $writes Array of create/update/delete operations.
	 * @return array|\WP_Error
	 */
	public static function apply_writes( array $writes ): array|\WP_Error {
		/**
		 * Short-circuits the applyWrites call before it reaches the PDS.
		 *
		 * Return a non-null array (success shape: `[ 'results' => [...] ]`)
		 * or a `WP_Error` to bypass the real HTTP round-trip. Used by
		 * the PHPUnit suite, the FOSSE end-to-end harness, and anything
		 * else that needs to observe or mock a write batch without
		 * actually hitting the PDS.
		 *
		 * A common use is `pre_http_request`, but that filter fires
		 * inside `wp_remote_request`, which is only reached after the
		 * DPoP proof has been built — so in test environments without
		 * a real DPoP JWK, the call errors out first. This filter runs
		 * before any of that.
		 *
		 * @param null|array|\WP_Error $short_circuit Short-circuit value. Return null to skip.
		 * @param array                $writes        The write batch about to be sent.
		 */
		$short_circuit = \apply_filters( 'atmosphere_pre_apply_writes', null, $writes );

		if ( \is_array( $short_circuit ) || \is_wp_error( $short_circuit ) ) {
			return $short_circuit;
		}

		if ( null !== $short_circuit ) {
			// Malformed filter return (scalar / object / etc). Surface as a
			// WP_Error instead of letting PHP fatal on the `array|\WP_Error`
			// return type.
			return new \WP_Error(
				'atmosphere_invalid_pre_apply_writes_return',
				\__( 'atmosphere_pre_apply_writes must return null, an array, or a WP_Error.', 'atmosphere' )
			);
		}

		return self::post(
			'/xrpc/com.atproto.repo.applyWrites',
			array(
				'repo'   => get_did(),
				'writes' => $writes,
			)
		);
	}

	/**
	 * Get a single record from the PDS.
	 *
	 * @param string $collection Collection NSID.
	 * @param string $rkey       Record key.
	 * @return array|\WP_Error
	 */
	public static function get_record( string $collection, string $rkey ): array|\WP_Error {
		return self::get(
			'/xrpc/com.atproto.repo.getRecord',
			array(
				'repo'       => get_did(),
				'collection' => $collection,
				'rkey'       => $rkey,
			)
		);
	}

	/**
	 * List records in a collection.
	 *
	 * @param string      $collection Collection NSID.
	 * @param int         $limit      Maximum records (default 50, max 100).
	 * @param string|null $cursor     Pagination cursor.
	 * @return array|\WP_Error
	 */
	public static function list_records( string $collection, int $limit = 50, ?string $cursor = null ): array|\WP_Error {
		$params = array(
			'repo'       => get_did(),
			'collection' => $collection,
			'limit'      => $limit,
		);

		if ( null !== $cursor ) {
			$params['cursor'] = $cursor;
		}

		return self::get( '/xrpc/com.atproto.repo.listRecords', $params );
	}
}
