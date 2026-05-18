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
	 * `identity:handle` is required for `com.atproto.identity.updateHandle`
	 * — the canonical AT Protocol permission scope per
	 * https://atproto.com/specs/permission. `transition:generic` is the
	 * App Password-equivalent bucket and explicitly does not include
	 * identity operations, so it must be paired with `identity:handle`
	 * for any flow that lets users change their handle through the PDS.
	 *
	 * MUST stay in lockstep with the `scope` value advertised in the
	 * client-metadata REST endpoint
	 * ({@see \Atmosphere\Admin::serve_client_metadata()}). The auth
	 * server validates the requested scope against the metadata; a drift
	 * silently downgrades every connection to whichever value is smaller.
	 *
	 * @var string
	 */
	private const SCOPES = 'atproto transition:generic identity:handle';

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
	 * The return value of this method is sent to the auth server as
	 * `redirect_uri` in the PAR / authorize request and also baked into
	 * the public client metadata document. If a third-party filter
	 * returns a non-admin URL, the auth server happily redirects the
	 * authorization code to that destination on completion — which is
	 * an OAuth token-leak primitive. We validate the filter return is
	 * a string pointing at this site's admin and fall back to the
	 * default URI on anything else.
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
		 * default Atmosphere settings page. The return MUST be a string
		 * pointing at this site's admin area — anything else is rejected
		 * to prevent a malicious or buggy filter from redirecting OAuth
		 * codes off-site.
		 *
		 * @param string $uri Default redirect URI.
		 */
		$filtered = \apply_filters( 'atmosphere_oauth_redirect_uri', $uri );

		if ( ! \is_string( $filtered ) || '' === $filtered ) {
			return $uri;
		}

		if ( ! \str_starts_with( $filtered, \admin_url( '' ) ) ) {
			return $uri;
		}

		return $filtered;
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
		$rate_limit = self::rate_limit_check();
		if ( \is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

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

		/*
		 * Persist transient data needed for callback. The DPoP JWK
		 * contains the ES256 private `d` parameter and must never sit
		 * in plaintext in `wp_options` — encrypt it the same way the
		 * persisted connection encrypts the key after callback
		 * (`Encryption::encrypt()` below at the option write).
		 *
		 * Compute the ciphertext BEFORE writing any transients.
		 * `Encryption::encrypt()` ultimately calls `random_bytes()`
		 * and `sodium_crypto_secretbox()`, both of which can throw.
		 * If we wrote verifier + state first and the encrypt then
		 * threw, those transients would sit orphaned for an hour and
		 * the user would see `atmosphere_expired` on retry until they
		 * expired naturally. Doing the encrypt first means a thrown
		 * exception bubbles up cleanly with no orphans.
		 */
		$dpop_jwk_ciphertext = Encryption::encrypt( (string) \wp_json_encode( $dpop_jwk ) );

		\set_transient( 'atmosphere_oauth_verifier', $verifier, HOUR_IN_SECONDS );
		\set_transient( 'atmosphere_oauth_state', $state, HOUR_IN_SECONDS );
		\set_transient( 'atmosphere_oauth_dpop_jwk', $dpop_jwk_ciphertext, HOUR_IN_SECONDS );
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

		$response = \wp_safe_remote_post(
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
		if ( ! \is_array( $data ) ) {
			$data = array();
		}

		// Retry once with nonce on use_dpop_nonce error.
		if ( \in_array( $status, array( 400, 401 ), true )
			&& ( $data['error'] ?? '' ) === 'use_dpop_nonce'
			&& $nonce
		) {
			$dpop_proof = DPoP::create_proof( $dpop_jwk, 'POST', $par_url, $nonce );

			if ( false === $dpop_proof ) {
				return new \WP_Error( 'atmosphere_dpop', \__( 'DPoP nonce retry failed.', 'atmosphere' ) );
			}

			$response = \wp_safe_remote_post(
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
			if ( ! \is_array( $data ) ) {
				$data = array();
			}
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
		$rate_limit = self::rate_limit_check();
		if ( \is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

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

		$dpop_jwk_blob = \get_transient( 'atmosphere_oauth_dpop_jwk' );
		$resolved      = \get_transient( 'atmosphere_oauth_resolved' );
		\delete_transient( 'atmosphere_oauth_dpop_jwk' );
		\delete_transient( 'atmosphere_oauth_resolved' );

		if ( ! $dpop_jwk_blob || ! $resolved ) {
			return new \WP_Error( 'atmosphere_expired', \__( 'OAuth session data missing. Please try again.', 'atmosphere' ) );
		}

		/*
		 * Pre-encryption versions of the plugin stored the DPoP JWK as
		 * a plain array in the transient. A user who started OAuth on
		 * the old code and completes the callback after upgrading
		 * sees a non-string value here. Distinct error code from
		 * "transient expired" so support can tell the two cases apart.
		 */
		if ( ! \is_string( $dpop_jwk_blob ) ) {
			return new \WP_Error(
				'atmosphere_legacy_session',
				\__( 'OAuth session predates the latest update. Please try connecting again.', 'atmosphere' )
			);
		}

		$dpop_jwk_json = Encryption::decrypt( $dpop_jwk_blob );
		if ( false === $dpop_jwk_json ) {
			return new \WP_Error( 'atmosphere_decrypt', \__( 'Failed to decrypt OAuth session key.', 'atmosphere' ) );
		}

		$dpop_jwk = \json_decode( $dpop_jwk_json, true );
		if ( ! \is_array( $dpop_jwk ) ) {
			return new \WP_Error(
				'atmosphere_session_malformed',
				\__( 'OAuth session key is malformed. Please try again.', 'atmosphere' )
			);
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

		$response = \wp_safe_remote_post(
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
		if ( ! \is_array( $data ) ) {
			$data = array();
		}

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

			$response = \wp_safe_remote_post(
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
			if ( ! \is_array( $data ) ) {
				$data = array();
			}
		}

		if ( $status >= 400 || empty( $data['access_token'] ) ) {
			$msg = $data['error_description'] ?? ( $data['error'] ?? \__( 'Token exchange failed.', 'atmosphere' ) );
			return new \WP_Error( 'atmosphere_token', $msg );
		}

		// Persist connection.
		$connection = array(
			'did'                 => $resolved['did'],
			'handle'              => $resolved['handle'],
			'pds_endpoint'        => $resolved['pds_endpoint'],
			'auth_server'         => $resolved['auth_server']['issuer_url'],
			'token_endpoint'      => $token_endpoint,
			'revocation_endpoint' => isset( $resolved['auth_server']['revocation_endpoint'] )
				? (string) $resolved['auth_server']['revocation_endpoint']
				: '',
			'access_token'        => Encryption::encrypt( $data['access_token'] ),
			'refresh_token'       => ! empty( $data['refresh_token'] ) ? Encryption::encrypt( $data['refresh_token'] ) : '',
			'dpop_jwk'            => Encryption::encrypt( (string) \wp_json_encode( $dpop_jwk ) ),
			'expires_at'          => \time() + ( $data['expires_in'] ?? 3600 ),
		);

		\update_option( 'atmosphere_connection', $connection );

		return true;
	}

	/**
	 * Maximum lifetime (seconds) of the refresh lock before it is
	 * presumed stale and reclaimed. One refresh roundtrip is typically
	 * sub-second; the buffer covers the slowest reasonable network
	 * timeout (15s) plus a margin for the request that set the lock to
	 * actually finish.
	 *
	 * @var int
	 */
	private const REFRESH_LOCK_TTL = 30;

	/**
	 * Refresh the access token.
	 *
	 * Guarded by an `add_option`-backed lock so two concurrent workers
	 * (cron + admin click, two cron events firing in the same tick,
	 * etc.) cannot both POST the same refresh token to the auth server.
	 * The auth server consumes the refresh token on first success and
	 * the loser would otherwise receive `invalid_grant`, which used to
	 * delete a perfectly-working connection.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh(): true|\WP_Error {
		$conn = \get_option( 'atmosphere_connection', array() );

		if ( empty( $conn['refresh_token'] ) ) {
			return new \WP_Error( 'atmosphere_no_refresh', \__( 'No refresh token available.', 'atmosphere' ) );
		}

		/*
		 * Reclaim a lock left by a crashed worker. Two workers can
		 * race the reclaim+`add_option` pair and both succeed, but
		 * that only happens after the locked worker actually crashed
		 * (i.e. the in-flight request never finished). At that point
		 * both winners just re-issue the refresh; the second to talk
		 * to the auth server gets `invalid_grant` and the connection
		 * is reset.
		 */
		$existing_lock = (int) \get_option( 'atmosphere_refresh_lock', 0 );
		if ( $existing_lock > 0 && $existing_lock < \time() - self::REFRESH_LOCK_TTL ) {
			\delete_option( 'atmosphere_refresh_lock' );
		}

		/*
		 * `add_option` is atomic at the SQL layer (INSERT IGNORE).
		 * Fourth arg `'no'` disables autoload so the lock value does
		 * not bloat the always-loaded options cache. When another
		 * worker holds the lock the call returns false and we fall
		 * through to the re-read path: if that worker already updated
		 * `atmosphere_connection` with a fresh token, the caller's
		 * `access_token()` will see it without us issuing a second
		 * refresh.
		 */
		if ( ! \add_option( 'atmosphere_refresh_lock', (string) \time(), '', 'no' ) ) {
			$conn = \get_option( 'atmosphere_connection', array() );
			if ( \is_array( $conn ) && ! empty( $conn['expires_at'] ) && $conn['expires_at'] > \time() + 300 ) {
				return true;
			}
			return new \WP_Error(
				'atmosphere_refresh_locked',
				\__( 'Another worker is refreshing the access token. Try again shortly.', 'atmosphere' )
			);
		}

		/*
		 * Re-read the connection under the lock — between the initial
		 * read above and the lock acquisition, the previous lock-holder
		 * (which we may have just reclaimed as stale, or whose work
		 * landed mid-execution) could have stored a fresh token. Using
		 * the post-lock snapshot avoids POSTing an already-consumed
		 * refresh token.
		 */
		$conn = \get_option( 'atmosphere_connection', array() );

		if ( ! \is_array( $conn ) || empty( $conn['refresh_token'] ) ) {
			\delete_option( 'atmosphere_refresh_lock' );
			return new \WP_Error( 'atmosphere_no_refresh', \__( 'No refresh token available.', 'atmosphere' ) );
		}

		try {
			return self::do_refresh( $conn );
		} finally {
			\delete_option( 'atmosphere_refresh_lock' );
		}
	}

	/**
	 * Issue the actual token-refresh request.
	 *
	 * Split from `refresh()` so the surrounding lock acquire/release in
	 * `refresh()` has a single, easy-to-follow critical section. Don't
	 * call this directly — concurrent callers will burn the refresh
	 * token. Always go through `refresh()` which holds the lock.
	 *
	 * @param array $conn Decoded `atmosphere_connection` option payload.
	 * @return true|\WP_Error
	 */
	private static function do_refresh( array $conn ): true|\WP_Error {
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

		$response = \wp_safe_remote_post(
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
		if ( ! \is_array( $data ) ) {
			$data = array();
		}

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

			$response = \wp_safe_remote_post(
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
			if ( ! \is_array( $data ) ) {
				$data = array();
			}
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
	 *
	 * Best-effort revokes the refresh token at the auth server before
	 * deleting local state (RFC 7009). A leaked refresh token therefore
	 * stops working server-side even though disconnect already removed
	 * it locally. Revocation failures are logged but do not block the
	 * local cleanup.
	 */
	public static function disconnect(): void {
		self::revoke_refresh_token();
		\delete_option( 'atmosphere_connection' );
		clear_scheduled_hooks();
	}

	/**
	 * Best-effort revoke the stored refresh token at the auth server.
	 *
	 * Reads the live `atmosphere_connection` option, decrypts the
	 * refresh token + DPoP JWK, and POSTs the token to the auth
	 * server's `revocation_endpoint` per RFC 7009. AT Protocol auth
	 * servers require DPoP-bound revocation, so the request carries a
	 * DPoP proof and retries once on `use_dpop_nonce` exactly like
	 * `refresh()` does. Any failure path returns silently after
	 * logging — disconnect must not be gated on the auth server being
	 * reachable.
	 */
	private static function revoke_refresh_token(): void {
		$conn = \get_option( 'atmosphere_connection', array() );

		if ( ! \is_array( $conn ) ) {
			return;
		}

		$revocation_endpoint = isset( $conn['revocation_endpoint'] ) ? (string) $conn['revocation_endpoint'] : '';
		if ( '' === $revocation_endpoint ) {
			return;
		}

		/*
		 * Validate at call time, not just at the resolver during
		 * connect. The stored option may have been imported from a
		 * backup, restored from a migration tool, or filtered by a
		 * misbehaving plugin since the connection was minted. POSTing
		 * a refresh token to a non-HTTPS or otherwise-unsafe URL on
		 * disconnect would leak it to whoever controls that endpoint.
		 */
		if ( ! Resolver::is_safe_https_url( $revocation_endpoint ) ) {
			return;
		}

		if ( empty( $conn['refresh_token'] ) || empty( $conn['dpop_jwk'] ) ) {
			return;
		}

		$refresh_token = Encryption::decrypt( $conn['refresh_token'] );
		$dpop_jwk_json = Encryption::decrypt( $conn['dpop_jwk'] );
		if ( false === $refresh_token || false === $dpop_jwk_json ) {
			return;
		}

		$dpop_jwk = \json_decode( $dpop_jwk_json, true );
		if ( ! \is_array( $dpop_jwk ) ) {
			return;
		}

		$body = array(
			'token'           => $refresh_token,
			'token_type_hint' => 'refresh_token',
			'client_id'       => self::client_id(),
		);

		$dpop_proof = DPoP::create_proof( $dpop_jwk, 'POST', $revocation_endpoint );
		if ( false === $dpop_proof ) {
			return;
		}

		$response = \wp_safe_remote_post(
			$revocation_endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'DPoP'         => $dpop_proof,
				),
				'body'    => $body,
				'timeout' => 10,
			)
		);

		if ( \is_wp_error( $response ) ) {
			\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\sprintf(
					'[atmosphere] refresh-token revocation failed: %s',
					$response->get_error_message()
				)
			);
			return;
		}

		$nonce = \wp_remote_retrieve_header( $response, 'dpop-nonce' );
		if ( $nonce ) {
			DPoP::persist_nonce( $dpop_jwk, $revocation_endpoint, $nonce );
		}

		$status = \wp_remote_retrieve_response_code( $response );
		$data   = \json_decode( \wp_remote_retrieve_body( $response ), true );
		if ( ! \is_array( $data ) ) {
			$data = array();
		}

		if ( \in_array( $status, array( 400, 401 ), true )
			&& ( $data['error'] ?? '' ) === 'use_dpop_nonce'
			&& $nonce
		) {
			$dpop_proof = DPoP::create_proof( $dpop_jwk, 'POST', $revocation_endpoint, $nonce );
			if ( false === $dpop_proof ) {
				return;
			}

			$response = \wp_safe_remote_post(
				$revocation_endpoint,
				array(
					'headers' => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
						'DPoP'         => $dpop_proof,
					),
					'body'    => $body,
					'timeout' => 10,
				)
			);

			if ( \is_wp_error( $response ) ) {
				\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					\sprintf(
						'[atmosphere] refresh-token revocation retry failed: %s',
						$response->get_error_message()
					)
				);
				return;
			}

			$nonce_retry = \wp_remote_retrieve_header( $response, 'dpop-nonce' );
			if ( $nonce_retry ) {
				DPoP::persist_nonce( $dpop_jwk, $revocation_endpoint, $nonce_retry );
			}

			$status = \wp_remote_retrieve_response_code( $response );
		}

		/*
		 * RFC 7009 §2.2: revocation responses are 200 even when the
		 * token is unknown to the auth server. A 4xx/5xx generally
		 * indicates a misconfigured client or a server outage; either
		 * way disconnect proceeds.
		 */
		if ( $status >= 400 ) {
			\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\sprintf(
					'[atmosphere] refresh-token revocation returned status %d',
					$status
				)
			);
		}
	}

	/**
	 * Maximum OAuth attempts (authorize + callback combined) per user
	 * inside a single rate-limit window.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_MAX = 10;

	/**
	 * Rate-limit window in seconds.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_WINDOW = 900;

	/**
	 * Throttle OAuth start and callback per WordPress user.
	 *
	 * The admin-only capability gate keeps the surface narrow, but a
	 * compromised admin or a chain that survives the nonce check (a
	 * settings-screen XSS in another plugin, for example) could
	 * otherwise drive unbounded outbound requests to an attacker-chosen
	 * auth server via the handle field. The counter is cheap, scoped to
	 * the current user (or a shared `0` bucket for unauthenticated
	 * callers, which the admin nonce normally rejects anyway), and
	 * naturally resets when the window expires.
	 *
	 * @return true|\WP_Error
	 */
	private static function rate_limit_check(): true|\WP_Error {
		$key      = \sprintf( 'atmosphere_oauth_rate_%d', \get_current_user_id() );
		$attempts = (int) \get_transient( $key );

		if ( $attempts >= self::RATE_LIMIT_MAX ) {
			return new \WP_Error(
				'atmosphere_rate_limited',
				\__( 'Too many OAuth attempts. Please wait a few minutes and try again.', 'atmosphere' )
			);
		}

		\set_transient( $key, $attempts + 1, self::RATE_LIMIT_WINDOW );

		return true;
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
