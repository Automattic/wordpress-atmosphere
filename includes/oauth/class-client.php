<?php
/**
 * Native AT Protocol OAuth client.
 *
 * Implements the full PKCE + DPoP + PAR flow without an external proxy.
 * The plugin's REST endpoint (client-metadata) serves as the client_id.
 *
 * @package Atmosphere
 */

namespace Atmosphere\OAuth;

\defined( 'ABSPATH' ) || exit;

use function Atmosphere\clear_scheduled_hooks;

/**
 * OAuth client that manages the authorization lifecycle.
 */
class Client {

	/**
	 * Scopes requested from the auth server.
	 *
	 * @var string
	 */
	private const SCOPES = 'atproto transition:generic';

	/**
	 * Get the client_id URL (= client metadata endpoint).
	 *
	 * @return string
	 */
	public static function client_id(): string {
		return \rest_url( 'atmosphere/v1/client-metadata' );
	}

	/**
	 * OAuth callback URI (admin page with special query param).
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		$uri = \admin_url( 'options-general.php?page=atmosphere' );

		/**
		 * Filters the OAuth redirect URI.
		 *
		 * Allows consumers (e.g. wrapper plugins) to set their own callback
		 * URL so the OAuth flow returns to their admin page instead of the
		 * default Atmosphere settings page.
		 *
		 * @param string $uri Default redirect URI.
		 */
		return \apply_filters( 'atmosphere_oauth_redirect_uri', $uri );
	}

	/**
	 * Start the OAuth flow for a given handle.
	 *
	 * Resolves the handle, generates PKCE + DPoP key, pushes a PAR
	 * request, and returns the authorization URL to redirect to.
	 *
	 * @param string $handle AT Protocol handle.
	 * @return string|\WP_Error Authorization URL or error.
	 */
	public static function authorize( string $handle ): string|\WP_Error {
		$resolved = Resolver::resolve( $handle );
		if ( \is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$auth_meta = $resolved['auth_server'];

		// PKCE.
		$verifier  = self::generate_verifier();
		$challenge = self::generate_challenge( $verifier );

		// DPoP key pair.
		$dpop_jwk = DPoP::generate_key();

		// State for CSRF.
		$state = \wp_generate_password( 40, false );

		// Persist transient data needed for callback.
		\set_transient( 'atmosphere_oauth_verifier', $verifier, HOUR_IN_SECONDS );
		\set_transient( 'atmosphere_oauth_state', $state, HOUR_IN_SECONDS );
		\set_transient( 'atmosphere_oauth_dpop_jwk', $dpop_jwk, HOUR_IN_SECONDS );
		\set_transient(
			'atmosphere_oauth_resolved',
			array(
				'did'          => $resolved['did'],
				'pds_endpoint' => $resolved['pds_endpoint'],
				'auth_server'  => $auth_meta,
				'handle'       => $handle,
			),
			HOUR_IN_SECONDS
		);

		// PAR (Pushed Authorization Request).
		$par_endpoint = $auth_meta['pushed_authorization_request_endpoint'] ?? null;

		if ( $par_endpoint ) {
			return self::authorize_via_par(
				$par_endpoint,
				$auth_meta['authorization_endpoint'],
				$dpop_jwk,
				$state,
				$challenge,
				$resolved['did']
			);
		}

		// Fallback to plain authorization URL.
		$params = array(
			'client_id'             => self::client_id(),
			'redirect_uri'          => self::redirect_uri(),
			'response_type'         => 'code',
			'scope'                 => self::SCOPES,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'login_hint'            => $resolved['did'],
		);

		return $auth_meta['authorization_endpoint'] . '?' . \http_build_query( $params );
	}

	/**
	 * Use PAR to obtain a request_uri, then redirect to auth endpoint.
	 *
	 * @param string $par_url  PAR endpoint.
	 * @param string $auth_url Authorization endpoint.
	 * @param array  $dpop_jwk DPoP key.
	 * @param string $state    CSRF state.
	 * @param string $challenge PKCE challenge.
	 * @param string $did      Login hint.
	 * @return string|\WP_Error
	 */
	private static function authorize_via_par(
		string $par_url,
		string $auth_url,
		array $dpop_jwk,
		string $state,
		string $challenge,
		string $did,
	): string|\WP_Error {
		$dpop_proof = DPoP::create_proof( $dpop_jwk, 'POST', $par_url );

		if ( false === $dpop_proof ) {
			return new \WP_Error( 'atmosphere_dpop', \__( 'Failed to create DPoP proof for PAR.', 'atmosphere' ) );
		}

		$body = array(
			'client_id'             => self::client_id(),
			'redirect_uri'          => self::redirect_uri(),
			'response_type'         => 'code',
			'scope'                 => self::SCOPES,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'login_hint'            => $did,
		);

		$response = \wp_remote_post(
			$par_url,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'DPoP'         => $dpop_proof,
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		// Store nonce from response if present.
		$nonce = \wp_remote_retrieve_header( $response, 'dpop-nonce' );
		if ( $nonce ) {
			DPoP::persist_nonce( $dpop_jwk, $par_url, $nonce );
		}

		$status = \wp_remote_retrieve_response_code( $response );
		$data   = \json_decode( \wp_remote_retrieve_body( $response ), true );

		// Retry once with nonce on use_dpop_nonce error.
		if ( \in_array( $status, array( 400, 401 ), true )
			&& ( $data['error'] ?? '' ) === 'use_dpop_nonce'
			&& $nonce
		) {
			$dpop_proof = DPoP::create_proof( $dpop_jwk, 'POST', $par_url, $nonce );

			if ( false === $dpop_proof ) {
				return new \WP_Error( 'atmosphere_dpop', \__( 'DPoP nonce retry failed.', 'atmosphere' ) );
			}

			$response = \wp_remote_post(
				$par_url,
				array(
					'headers' => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
						'DPoP'         => $dpop_proof,
					),
					'body'    => $body,
					'timeout' => 15,
				)
			);

			if ( \is_wp_error( $response ) ) {
				return $response;
			}

			$nonce_retry = \wp_remote_retrieve_header( $response, 'dpop-nonce' );
			if ( $nonce_retry ) {
				DPoP::persist_nonce( $dpop_jwk, $par_url, $nonce_retry );
			}

			$status = \wp_remote_retrieve_response_code( $response );
			$data   = \json_decode( \wp_remote_retrieve_body( $response ), true );
		}

		if ( $status >= 400 || empty( $data['request_uri'] ) ) {
			$msg = $data['error_description'] ?? ( $data['error'] ?? \__( 'PAR request failed.', 'atmosphere' ) );
			return new \WP_Error( 'atmosphere_par', $msg );
		}

		$params = array(
			'client_id'   => self::client_id(),
			'request_uri' => $data['request_uri'],
		);

		return $auth_url . '?' . \http_build_query( $params );
	}

	/**
	 * Handle the OAuth callback: exchange code for tokens.
	 *
	 * @param string $code  Authorization code.
	 * @param string $state CSRF state from query string.
	 * @return true|\WP_Error
	 */
	public static function handle_callback( string $code, string $state ): true|\WP_Error {
		// Verify state.
		$stored_state = \get_transient( 'atmosphere_oauth_state' );
		if ( ! $stored_state || ! \hash_equals( $stored_state, $state ) ) {
			return new \WP_Error( 'atmosphere_state', \__( 'Invalid OAuth state. Please try again.', 'atmosphere' ) );
		}
		\delete_transient( 'atmosphere_oauth_state' );

		$verifier = \get_transient( 'atmosphere_oauth_verifier' );
		if ( ! $verifier ) {
			return new \WP_Error( 'atmosphere_expired', \__( 'OAuth session expired. Please try again.', 'atmosphere' ) );
		}
		\delete_transient( 'atmosphere_oauth_verifier' );

		$dpop_jwk = \get_transient( 'atmosphere_oauth_dpop_jwk' );
		$resolved = \get_transient( 'atmosphere_oauth_resolved' );
		\delete_transient( 'atmosphere_oauth_dpop_jwk' );
		\delete_transient( 'atmosphere_oauth_resolved' );

		if ( ! $dpop_jwk || ! $resolved ) {
			return new \WP_Error( 'atmosphere_expired', \__( 'OAuth session data missing. Please try again.', 'atmosphere' ) );
		}

		$token_endpoint = $resolved['auth_server']['token_endpoint'];

		// Build DPoP proof for token request.
		$dpop_proof = DPoP::create_proof( $dpop_jwk, 'POST', $token_endpoint );
		if ( false === $dpop_proof ) {
			return new \WP_Error( 'atmosphere_dpop', \__( 'Failed to create DPoP proof for token exchange.', 'atmosphere' ) );
		}

		$token_body = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => self::client_id(),
			'redirect_uri'  => self::redirect_uri(),
			'code_verifier' => $verifier,
		);

		$response = \wp_remote_post(
			$token_endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'DPoP'         => $dpop_proof,
				),
				'body'    => $token_body,
				'timeout' => 15,
			)
		);

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		// Handle DPoP nonce requirement.
		$nonce  = \wp_remote_retrieve_header( $response, 'dpop-nonce' );
		$status = \wp_remote_retrieve_response_code( $response );
		$data   = \json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( $nonce ) {
			DPoP::persist_nonce( $dpop_jwk, $token_endpoint, $nonce );
		}

		if ( \in_array( $status, array( 400, 401 ), true )
			&& ( $data['error'] ?? '' ) === 'use_dpop_nonce'
			&& $nonce
		) {
			$dpop_proof = DPoP::create_proof( $dpop_jwk, 'POST', $token_endpoint, $nonce );
			if ( false === $dpop_proof ) {
				return new \WP_Error( 'atmosphere_dpop', \__( 'DPoP nonce retry failed during token exchange.', 'atmosphere' ) );
			}

			$response = \wp_remote_post(
				$token_endpoint,
				array(
					'headers' => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
						'DPoP'         => $dpop_proof,
					),
					'body'    => $token_body,
					'timeout' => 15,
				)
			);

			if ( \is_wp_error( $response ) ) {
				return $response;
			}

			$status = \wp_remote_retrieve_response_code( $response );
			$data   = \json_decode( \wp_remote_retrieve_body( $response ), true );
		}

		if ( $status >= 400 || empty( $data['access_token'] ) ) {
			$msg = $data['error_description'] ?? ( $data['error'] ?? \__( 'Token exchange failed.', 'atmosphere' ) );
			return new \WP_Error( 'atmosphere_token', $msg );
		}

		// Persist connection.
		$connection = array(
			'did'            => $resolved['did'],
			'handle'         => $resolved['handle'],
			'pds_endpoint'   => $resolved['pds_endpoint'],
			'auth_server'    => $resolved['auth_server']['issuer_url'],
			'token_endpoint' => $token_endpoint,
			'access_token'   => Encryption::encrypt( $data['access_token'] ),
			'refresh_token'  => ! empty( $data['refresh_token'] ) ? Encryption::encrypt( $data['refresh_token'] ) : '',
			'dpop_jwk'       => Encryption::encrypt( (string) \wp_json_encode( $dpop_jwk ) ),
			'expires_at'     => \time() + ( $data['expires_in'] ?? 3600 ),
		);

		\update_option( 'atmosphere_connection', $connection );

		return true;
	}

	/**
	 * Refresh the access token.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh(): true|\WP_Error {
		$conn = \get_option( 'atmosphere_connection', array() );

		if ( empty( $conn['refresh_token'] ) ) {
			return new \WP_Error( 'atmosphere_no_refresh', \__( 'No refresh token available.', 'atmosphere' ) );
		}

		$refresh_token = Encryption::decrypt( $conn['refresh_token'] );
		if ( false === $refresh_token ) {
			return new \WP_Error( 'atmosphere_decrypt', \__( 'Failed to decrypt refresh token.', 'atmosphere' ) );
		}

		$dpop_jwk_json = Encryption::decrypt( $conn['dpop_jwk'] );
		if ( false === $dpop_jwk_json ) {
			return new \WP_Error( 'atmosphere_decrypt', \__( 'Failed to decrypt DPoP key.', 'atmosphere' ) );
		}

		$dpop_jwk       = \json_decode( $dpop_jwk_json, true );
		$token_endpoint = $conn['token_endpoint'];

		$dpop_proof = DPoP::create_proof( $dpop_jwk, 'POST', $token_endpoint );
		if ( false === $dpop_proof ) {
			return new \WP_Error( 'atmosphere_dpop', \__( 'Failed to create DPoP proof for refresh.', 'atmosphere' ) );
		}

		$body = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => self::client_id(),
		);

		$response = \wp_remote_post(
			$token_endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'DPoP'         => $dpop_proof,
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$nonce  = \wp_remote_retrieve_header( $response, 'dpop-nonce' );
		$status = \wp_remote_retrieve_response_code( $response );
		$data   = \json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( $nonce ) {
			DPoP::persist_nonce( $dpop_jwk, $token_endpoint, $nonce );
		}

		// Retry with nonce.
		if ( \in_array( $status, array( 400, 401 ), true )
			&& ( $data['error'] ?? '' ) === 'use_dpop_nonce'
			&& $nonce
		) {
			$dpop_proof = DPoP::create_proof( $dpop_jwk, 'POST', $token_endpoint, $nonce );
			if ( false === $dpop_proof ) {
				return new \WP_Error( 'atmosphere_dpop', \__( 'DPoP nonce retry failed during refresh.', 'atmosphere' ) );
			}

			$response = \wp_remote_post(
				$token_endpoint,
				array(
					'headers' => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
						'DPoP'         => $dpop_proof,
					),
					'body'    => $body,
					'timeout' => 15,
				)
			);

			if ( \is_wp_error( $response ) ) {
				return $response;
			}

			$status = \wp_remote_retrieve_response_code( $response );
			$data   = \json_decode( \wp_remote_retrieve_body( $response ), true );
		}

		if ( $status >= 400 || empty( $data['access_token'] ) ) {
			$msg = $data['error_description'] ?? ( $data['error'] ?? \__( 'Token refresh failed.', 'atmosphere' ) );

			/*
			 * Only delete the connection for permanent errors where the
			 * refresh token has been consumed or revoked. Transient errors
			 * (rate-limiting, server errors) may not have consumed the
			 * token, so the connection can recover on the next attempt.
			 */
			$error = $data['error'] ?? '';
			if ( \in_array( $error, array( 'invalid_grant', 'invalid_client', 'unauthorized_client' ), true ) ) {
				\delete_option( 'atmosphere_connection' );
			}

			return new \WP_Error( 'atmosphere_refresh', $msg );
		}

		$conn['access_token'] = Encryption::encrypt( $data['access_token'] );
		$conn['expires_at']   = \time() + ( $data['expires_in'] ?? 3600 );

		if ( ! empty( $data['refresh_token'] ) ) {
			$conn['refresh_token'] = Encryption::encrypt( $data['refresh_token'] );
		}

		\update_option( 'atmosphere_connection', $conn );

		return true;
	}

	/**
	 * Get a usable access token, refreshing if close to expiry.
	 *
	 * @return string|\WP_Error
	 */
	public static function access_token(): string|\WP_Error {
		$conn = \get_option( 'atmosphere_connection', array() );

		if ( empty( $conn['access_token'] ) ) {
			return new \WP_Error( 'atmosphere_not_connected', \__( 'Not connected to AT Protocol.', 'atmosphere' ) );
		}

		// Refresh if expiring within 5 minutes.
		if ( ! empty( $conn['expires_at'] ) && $conn['expires_at'] < \time() + 300 ) {
			$result = self::refresh();
			if ( \is_wp_error( $result ) ) {
				return $result;
			}
			$conn = \get_option( 'atmosphere_connection', array() );
		}

		$token = Encryption::decrypt( $conn['access_token'] );
		if ( false === $token ) {
			return new \WP_Error( 'atmosphere_decrypt', \__( 'Failed to decrypt access token.', 'atmosphere' ) );
		}

		return $token;
	}

	/**
	 * Disconnect: remove all stored credentials and clear queued cron events.
	 *
	 * Queued events (`atmosphere_delete_records`,
	 * `atmosphere_delete_comment_record`) issue applyWrites without a
	 * connection check, so a disconnect→reconnect-to-different-account
	 * cycle would otherwise fire deletes against the new account's repo.
	 * Mirrors the cleanup performed on plugin deactivate / uninstall.
	 */
	public static function disconnect(): void {
		\delete_option( 'atmosphere_connection' );
		clear_scheduled_hooks();
	}

	/**
	 * Generate a PKCE code verifier.
	 *
	 * @return string
	 */
	private static function generate_verifier(): string {
		return \rtrim( \strtr( \base64_encode( \random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Generate a PKCE S256 code challenge.
	 *
	 * @param string $verifier Code verifier.
	 * @return string
	 */
	private static function generate_challenge( string $verifier ): string {
		return \rtrim( \strtr( \base64_encode( \hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
