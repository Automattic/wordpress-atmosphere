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
	 * @param string      $method       HTTP method.
	 * @param string      $endpoint     XRPC path, e.g. /xrpc/com.atproto.repo.createRecord.
	 * @param array       $args         wp_safe_remote_request() arguments.
	 * @param string|null $nonce        Explicit DPoP nonce (used on retry).
	 * @param bool        $auth_retried Internal marker: set on the recursive call after a
	 *                                  proactive refresh so a still-failing token does not
	 *                                  loop.
	 * @return array|\WP_Error Decoded JSON body or error.
	 */
	public static function request( string $method, string $endpoint, array $args = array(), ?string $nonce = null, bool $auth_retried = false ): array|\WP_Error {
		$original_args = $args;

		$access_token = Client::access_token();
		if ( \is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$conn = \get_option( 'atmosphere_connection', array() );

		/*
		 * Snapshot the access-token ciphertext we are about to use,
		 * BEFORE the HTTP round-trip. If the request comes back 401
		 * and a concurrent worker rotated the token while our HTTP
		 * call was in-flight, the rotated ciphertext will already be
		 * in `atmosphere_connection` by the time we read it again
		 * inside the 401 branch — and a snapshot taken there would
		 * equal the rotated value, defeating
		 * `Client::wait_for_token_refresh($snapshot)`'s "wait until
		 * the ciphertext differs from snapshot" semantics. Capturing
		 * here pins the comparison value to the token that ACTUALLY
		 * went on the wire, so any rotation that happened during the
		 * round-trip (or that lands during the wait) trips the
		 * differs-from-snapshot check correctly.
		 */
		$access_token_snapshot = (string) ( $conn['access_token'] ?? '' );

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

		$response = \wp_safe_remote_request( $url, $args );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$status = \wp_remote_retrieve_response_code( $response );
		$body   = \json_decode( \wp_remote_retrieve_body( $response ), true );
		if ( ! \is_array( $body ) ) {
			$body = array();
		}

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
			return self::request( $method, $endpoint, $original_args, $response_nonce, $auth_retried );
		}

		/*
		 * Retry once after a proactive token refresh when the PDS rejects
		 * the access token as expired or invalid. The previous behaviour
		 * surfaced these as a hard `atmosphere_pds` error even though a
		 * refresh-then-retry would have recovered the request — the most
		 * common reason for hitting this branch is an access token that
		 * `Client::access_token()` considered fresh (because `expires_at`
		 * was still in the future) but the auth server has revoked or
		 * rotated under us.
		 *
		 * Only recurse if the refresh either succeeded or is racing
		 * another worker (`atmosphere_refresh_locked`, in which case the
		 * recursive `Client::access_token()` will wait for that worker to
		 * land a new token). On any other refresh error — missing refresh
		 * token, decrypt failure, transient network blip — surface that
		 * error instead of retrying with the same stale access token; the
		 * second PDS call would just hit the same 401 and mask the more
		 * actionable upstream cause.
		 */
		if ( ! $auth_retried
			&& 401 === $status
			&& \in_array(
				$body['error'] ?? '',
				array( 'InvalidToken', 'ExpiredToken', 'AuthenticationRequired' ),
				true
			)
		) {
			/*
			 * `$access_token_snapshot` was captured at the top of this
			 * function, BEFORE the HTTP request — see the comment
			 * there for why post-request snapshotting would race
			 * a concurrent rotation. The retry must run against a
			 * rotated token, and the ciphertext-comparison is the
			 * only signal that reliably distinguishes "we still hold
			 * the version that just got rejected" from "someone
			 * already rotated":
			 *
			 *   - `Client::refresh()` short-circuits with `true` when
			 *     another worker holds the lock AND the local
			 *     `expires_at` is still in the future. Without the
			 *     ciphertext check we would retry immediately with
			 *     the same stale token.
			 *   - `Client::refresh()` returns `atmosphere_refresh_locked`
			 *     because another worker is mid-flight. The wait must
			 *     block until that worker writes a fresh token, not
			 *     just until the existing `expires_at` re-clears the
			 *     5-minute window.
			 */
			$refresh = Client::refresh();
			if ( \is_wp_error( $refresh ) && 'atmosphere_refresh_locked' !== $refresh->get_error_code() ) {
				return $refresh;
			}

			/*
			 * Whether `refresh()` returned `true` (we acquired the
			 * lock and rotated, or another worker just finished
			 * rotating), short-circuited to `true` on a locally-fresh
			 * `expires_at`, or returned `atmosphere_refresh_locked`,
			 * the same wait covers all three: it blocks until the
			 * stored access-token ciphertext differs from the
			 * snapshot above (or until `needs_reauth` flips, or
			 * until the lock TTL elapses). On the
			 * we-rotated-ourselves path this returns almost
			 * immediately on the first poll; on the concurrent-worker
			 * path it waits for their write to land.
			 */
			$waited = Client::wait_for_token_refresh( $access_token_snapshot );
			if ( \is_wp_error( $waited ) ) {
				return $waited;
			}

			return self::request( $method, $endpoint, $original_args, null, true );
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
		/**
		 * Short-circuits the uploadBlob call before it reaches the PDS.
		 *
		 * Return a non-null array (the PDS success shape, e.g.
		 * `[ 'blob' => [...] ]`) or a `WP_Error` to bypass the real HTTP
		 * round-trip. Mirrors `atmosphere_pre_apply_writes`: the real
		 * upload runs inside `wp_safe_remote_request` after a DPoP proof
		 * has been built, so test environments and the FOSSE harness use
		 * this filter to observe or mock the upload without a live PDS.
		 *
		 * @param null|array|\WP_Error $short_circuit Short-circuit value. Return null to skip.
		 * @param string               $file_path     Local path of the file about to be uploaded.
		 * @param string               $mime_type     MIME type of the file.
		 */
		$short_circuit = \apply_filters( 'atmosphere_pre_upload_blob', null, $file_path, $mime_type );
		if ( \is_array( $short_circuit ) || \is_wp_error( $short_circuit ) ) {
			return $short_circuit;
		}
		if ( null !== $short_circuit ) {
			// Malformed filter return (scalar / object / etc). Surface as a
			// WP_Error instead of letting PHP fatal on the `array|\WP_Error`
			// return type. Mirrors `atmosphere_pre_apply_writes`.
			return new \WP_Error(
				'atmosphere_invalid_pre_upload_blob_return',
				\__( 'atmosphere_pre_upload_blob must return null, an array, or a WP_Error.', 'atmosphere' )
			);
		}

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
		 * Return a non-null array (success shape: `[ 'results' => [...] ]`,
		 * with one array result per write) or a `WP_Error` to bypass the
		 * real HTTP round-trip. Used by
		 * the PHPUnit suite, the FOSSE end-to-end harness, and anything
		 * else that needs to observe or mock a write batch without
		 * actually hitting the PDS.
		 *
		 * A common use is `pre_http_request`, but that filter fires
		 * inside `wp_safe_remote_request`, which is only reached after
		 * the DPoP proof has been built — so in test environments
		 * without a real DPoP JWK, the call errors out first. This
		 * filter runs before any of that.
		 *
		 * @param null|array|\WP_Error $short_circuit Short-circuit value. Return null to skip.
		 * @param array                $writes        The write batch about to be sent.
		 */
		$short_circuit = \apply_filters( 'atmosphere_pre_apply_writes', null, $writes );

		if ( \is_wp_error( $short_circuit ) ) {
			return $short_circuit;
		}

		if ( \is_array( $short_circuit ) ) {
			return self::validate_apply_writes_response( $short_circuit, $writes );
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
	 * Validate a short-circuited applyWrites success response.
	 *
	 * @param array $response Short-circuited applyWrites response.
	 * @param array $writes   Write batch the response represents.
	 * @return array|\WP_Error
	 */
	private static function validate_apply_writes_response( array $response, array $writes ): array|\WP_Error {
		if ( ! isset( $response['results'] )
			|| ! \is_array( $response['results'] )
			|| ! \array_is_list( $response['results'] )
			|| \count( $response['results'] ) !== \count( $writes )
		) {
			return self::invalid_apply_writes_response();
		}

		foreach ( $response['results'] as $i => $result ) {
			if ( ! \is_array( $result ) ) {
				return self::invalid_apply_writes_response();
			}

			$type = $writes[ $i ]['$type'] ?? '';
			if ( \in_array(
				$type,
				array(
					'com.atproto.repo.applyWrites#create',
					'com.atproto.repo.applyWrites#update',
				),
				true
			) && ( empty( $result['uri'] ) || empty( $result['cid'] ) )
			) {
				return self::invalid_apply_writes_response();
			}
		}

		return $response;
	}

	/**
	 * Build a consistent malformed applyWrites response error.
	 *
	 * @return \WP_Error
	 */
	private static function invalid_apply_writes_response(): \WP_Error {
		return new \WP_Error(
			'atmosphere_invalid_pre_apply_writes_response',
			\__( 'atmosphere_pre_apply_writes success responses must include one results array entry for each write.', 'atmosphere' )
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
