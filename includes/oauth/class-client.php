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

use Atmosphere\Atmosphere;
use function Atmosphere\clear_scheduled_hooks;
use function Atmosphere\get_connection;

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
	 * `wp_options` row name used as the cross-process refresh lock.
	 *
	 * Public so `uninstall.php` and the test suite can refer to the same
	 * key without drifting from the canonical value used here.
	 *
	 * @var string
	 */
	public const REFRESH_LOCK_OPTION = '_atmosphere_refresh_lock';

	/**
	 * `wp_options` row name flagging an explicit operator-initiated disconnect.
	 *
	 * Set on {@see self::disconnect()} so the admin reauth notice can tell
	 * "user clicked Disconnect" apart from "refresh token failed". Cleared
	 * on the next successful authorization. Public so `uninstall.php` and
	 * the test suite can refer to the same key without drifting from the
	 * canonical value used here.
	 *
	 * @var string
	 */
	public const DISCONNECTED_OPTION = 'atmosphere_disconnected';

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
		/*
		 * Force the `https` scheme regardless of what `admin_url()`
		 * would default to. AT Protocol auth servers will return the
		 * authorization code by redirecting the browser to this URL;
		 * on an HTTP-configured admin that code would otherwise travel
		 * in cleartext. Sites that don't actually serve admin over
		 * HTTPS will fail to load the redirect, which is the safer
		 * failure mode.
		 */
		$uri = \admin_url( 'options-general.php?page=atmosphere', 'https' );

		/**
		 * Filters the OAuth redirect URI.
		 *
		 * Allows consumers (e.g. wrapper plugins) to set their own callback
		 * URL so the OAuth flow returns to their admin page instead of the
		 * default Atmosphere settings page. The return MUST be a string
		 * pointing at this site's admin area over HTTPS — anything else is
		 * rejected to prevent a malicious or buggy filter from redirecting
		 * OAuth codes off-site or over cleartext.
		 *
		 * @param string $uri Default redirect URI.
		 */
		$filtered = \apply_filters( 'atmosphere_oauth_redirect_uri', $uri );

		if ( ! \is_string( $filtered ) || '' === $filtered ) {
			return $uri;
		}

		if ( ! \str_starts_with( $filtered, 'https://' ) ) {
			return $uri;
		}

		if ( ! \str_starts_with( $filtered, \admin_url( '', 'https' ) ) ) {
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
		/*
		 * Rate-limit AFTER a successful `Resolver::resolve()`, not
		 * before. The earlier ordering charged the per-user bucket on
		 * every handle-typo / DNS-miss / malformed-DID-document path,
		 * even though those failures never reach the auth server — so
		 * ten consecutive typos in fifteen minutes locked the admin
		 * out without any abusive request having occurred. Resolver
		 * itself is bounded by its own DNS + HTTP timeouts, so the
		 * pre-resolve flood surface stays narrow even without a
		 * bucket charge here.
		 */
		$resolved = Resolver::resolve( $handle );
		if ( \is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$rate_limit = self::rate_limit_check();
		if ( \is_wp_error( $rate_limit ) ) {
			return $rate_limit;
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

			/*
			 * Persist the nonce from the retry response too. Auth
			 * servers rotate nonces per response; without this write
			 * the stored nonce stays at whatever the first response
			 * advertised, so the next DPoP-bound request issues a
			 * stale proof and forces another `use_dpop_nonce` retry.
			 */
			$nonce_retry = \wp_remote_retrieve_header( $response, 'dpop-nonce' );
			if ( $nonce_retry ) {
				DPoP::persist_nonce( $dpop_jwk, $token_endpoint, $nonce_retry );
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

		/*
		 * Persist identity separately from the credentials so a later
		 * refresh failure can wipe the live session without taking the
		 * domain's verification headers down with it. The credentials
		 * option still holds did/handle/pds_endpoint as well for
		 * backward compatibility with any external consumer reading the
		 * pre-split shape; the canonical source of truth for identity
		 * is `atmosphere_identity`.
		 */
		\update_option(
			'atmosphere_identity',
			array(
				'did'          => $resolved['did'],
				'handle'       => $resolved['handle'],
				'pds_endpoint' => $resolved['pds_endpoint'],
			),
			true
		);

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
			'needs_reauth'        => false,
		);

		/*
		 * Clear any prior explicit-disconnect marker BEFORE persisting
		 * the new connection. Doing so afterward exposes a cross-tab
		 * race: a stale Disconnect link fired from another admin tab
		 * during the OAuth callback would land between the
		 * `update_option('atmosphere_connection', ...)` and the
		 * `delete_option(DISCONNECTED_OPTION)`, stamping a fresh marker
		 * (Client::disconnect() also runs `delete_option`) — the
		 * callback would then clear the new marker on its way out.
		 * Final state: identity preserved, connection gone, marker
		 * gone — the reauth notice would fall through to "session
		 * expired" copy when the user actually triggered a deliberate
		 * disconnect from another tab.
		 *
		 * Clearing first means: any concurrent disconnect that lands
		 * between this delete and the persist below leaves the marker
		 * set AND the connection gone, which the notice gate correctly
		 * renders as "disconnected." The disconnect's own marker write
		 * is the authoritative signal of operator intent. Safe to call
		 * when nothing was set — `delete_option` is a no-op for missing
		 * rows.
		 */
		\delete_option( self::DISCONNECTED_OPTION );

		/*
		 * Encrypted token blobs do not need to ride along in every
		 * request's `alloptions` payload; they're only read on the
		 * paths that actually talk to the PDS. WP 6.6+ honours the
		 * autoload flag on subsequent `update_option` calls too, so
		 * existing autoloaded rows flip on the next reconnect.
		 */
		\update_option( 'atmosphere_connection', $connection, false );

		/*
		 * Connecting is the moment our well-known endpoints become
		 * meaningful — the PDS starts fetching `/.well-known/atproto-did`
		 * to verify a domain handle, and resolvers hit
		 * `/.well-known/site.standard.publication`. Make sure the
		 * persisted rewrite rules serve them on the very next request.
		 * See {@see Atmosphere::maybe_flush_wellknown_rewrites()} for the
		 * install paths that need this beyond activation.
		 */
		Atmosphere::maybe_flush_wellknown_rewrites();

		return true;
	}

	/**
	 * Maximum lifetime (seconds) of the refresh lock before it is
	 * presumed stale and reclaimed.
	 *
	 * `refresh_locked()` can issue up to two sequential HTTP POSTs on
	 * the `use_dpop_nonce` retry path, each with a 15s
	 * `wp_safe_remote_post` timeout. The first call typically returns
	 * the nonce error in well under a second (the auth server rejects
	 * before any real work) — but on a degraded auth server that
	 * actually hangs on every call, both legs can run the full 15s
	 * timeout, yielding a worst-case ~30s + encryption + option I/O
	 * overhead. 45 seconds covers that pathological case plus a small
	 * margin so a slow-but-legitimate refresh isn't stomped by a
	 * second worker's CAS-steal. Going lower than the actual worst
	 * case reintroduces the concurrent-refresh race the lock exists
	 * to close; going much higher keeps a crashed worker's lock alive
	 * for proportionally longer before any successor can take over.
	 *
	 * @var int
	 */
	private const REFRESH_LOCK_TTL = 45;

	/**
	 * Refresh the access token.
	 *
	 * Gated by a cross-process coordination lock so a publish event
	 * firing inline through `access_token()`, the hourly refresh
	 * cron, an admin click, or any combination of those cannot both
	 * POST the same refresh token to the auth server. The auth server
	 * consumes the refresh token on first success and the loser would
	 * otherwise receive `invalid_grant`, which used to delete a
	 * perfectly-working connection.
	 *
	 * Atomicity comes from {@see self::lock()}, which uses
	 * `INSERT IGNORE` on the `wp_options` UNIQUE index — portable to
	 * every WP-supported DB and truly cross-process. When the lock is
	 * already held, this method short-circuits to success if the
	 * stored token has already been rotated to a fresh value,
	 * otherwise it returns a soft error and lets the other process
	 * finish.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh(): true|\WP_Error {
		$conn = \get_option( 'atmosphere_connection', array() );

		if ( empty( $conn['refresh_token'] ) ) {
			return new \WP_Error( 'atmosphere_no_refresh', \__( 'No refresh token available.', 'atmosphere' ) );
		}

		if ( ! self::lock() ) {
			$fresh = \get_option( 'atmosphere_connection', array() );

			if ( ! empty( $fresh['access_token'] )
				&& empty( $fresh['needs_reauth'] )
				&& ! empty( $fresh['expires_at'] )
				&& $fresh['expires_at'] > \time() + 300
			) {
				return true;
			}

			return new \WP_Error(
				'atmosphere_refresh_locked',
				\__( 'Token refresh is already in progress; another request will pick up the new token.', 'atmosphere' )
			);
		}

		try {
			/*
			 * Re-read the connection after acquiring the lock. Another
			 * caller may have completed a refresh between our initial
			 * read and the lock acquisition; using a stale refresh
			 * token here would defeat the point of locking.
			 */
			$conn = \get_option( 'atmosphere_connection', array() );

			if ( empty( $conn['refresh_token'] ) ) {
				return new \WP_Error( 'atmosphere_no_refresh', \__( 'No refresh token available.', 'atmosphere' ) );
			}

			return self::refresh_locked( $conn );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Inner refresh routine. Runs under the refresh lock acquired in
	 * `refresh()`. Do not call directly — concurrent callers will
	 * burn the refresh token. Always go through `refresh()` which
	 * holds the lock.
	 *
	 * @param array $conn Connection option as read at lock-acquisition time.
	 * @return true|\WP_Error
	 */
	private static function refresh_locked( array $conn ): true|\WP_Error {
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

			/*
			 * Persist the nonce from the retry response too. Auth
			 * servers rotate nonces per response; without this write
			 * the stored nonce stays at whatever the first response
			 * advertised, so the next DPoP-bound refresh issues a
			 * stale proof and forces another `use_dpop_nonce` retry.
			 */
			$nonce_retry = \wp_remote_retrieve_header( $response, 'dpop-nonce' );
			if ( $nonce_retry ) {
				DPoP::persist_nonce( $dpop_jwk, $token_endpoint, $nonce_retry );
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
			 * Only mark the connection as needing reauth for permanent
			 * errors where the refresh token has been consumed or revoked.
			 * Transient errors (rate-limiting, server errors) may not have
			 * consumed the token, so the connection can recover on the
			 * next attempt without operator action.
			 *
			 * The connection row itself is preserved (rather than deleted)
			 * so the durable identity inside it stays available for the
			 * public verification headers — see `Atmosphere\has_identity()`
			 * and the gates in `output_document_link()` and the well-known
			 * endpoints. `is_connected()` returns false while
			 * `needs_reauth` is set, so the publish, comment, and API
			 * callers short-circuit until the user re-authorizes.
			 */
			$error = $data['error'] ?? '';
			if ( \in_array( $error, array( 'invalid_grant', 'invalid_client', 'unauthorized_client' ), true ) ) {
				/*
				 * Re-read the connection before writing, and only stamp
				 * `needs_reauth` if the row STILL holds the same
				 * refresh-token ciphertext we read at lock-acquisition
				 * time. Two races close here:
				 *
				 *   - Admin disconnected mid-flight: the row is gone
				 *     and the `refresh_token` check below fails.
				 *   - Admin disconnected + reconnected to a different
				 *     account mid-flight: the row exists but the
				 *     `refresh_token` ciphertext is different (libsodium
				 *     re-encrypts with a fresh nonce). Stamping
				 *     `needs_reauth` on the NEW account because the
				 *     OLD account's refresh failed would be wrong.
				 */
				$current = \get_option( 'atmosphere_connection', array() );
				if ( \is_array( $current )
					&& ! empty( $current['refresh_token'] )
					&& isset( $conn['refresh_token'] )
					&& \hash_equals( (string) $conn['refresh_token'], (string) $current['refresh_token'] )
				) {
					$current['needs_reauth'] = true;
					$current['access_token'] = '';
					unset( $current['expires_at'] );
					\update_option( 'atmosphere_connection', $current, false );
				}
			}

			return new \WP_Error( 'atmosphere_refresh', $msg );
		}

		/*
		 * Re-read the connection before persisting the rotated token.
		 * Two races are possible while the worker is in-flight:
		 *
		 *  1. Admin clicks Disconnect — `atmosphere_connection` is
		 *     deleted. Writing the post-network token back would
		 *     resurrect the row the admin just removed.
		 *  2. Admin clicks Disconnect AND immediately reconnects to a
		 *     different account. The row exists, but `refresh_token`
		 *     belongs to the NEW account; writing our rotated tokens
		 *     would overwrite the new account's access_token with
		 *     credentials minted against the OLD account's session.
		 *
		 * Both are closed by comparing the refresh-token ciphertext we
		 * read at lock-acquisition time against the current row. The
		 * ciphertexts are random per encryption (libsodium uses a
		 * fresh nonce on every `encrypt()`), so any change at all —
		 * delete-then-recreate, reconnect, even an unrelated
		 * `sync_connection_handle()` write that re-encrypted the row
		 * — fails the equality check and the worker bails.
		 */
		$current = \get_option( 'atmosphere_connection', array() );

		if ( ! \is_array( $current )
			|| empty( $current['refresh_token'] )
			|| ! isset( $conn['refresh_token'] )
			|| ! \hash_equals( (string) $conn['refresh_token'], (string) $current['refresh_token'] )
		) {
			return new \WP_Error(
				'atmosphere_disconnected_mid_refresh',
				\__( 'Connection changed while the refresh was in-flight; new tokens were discarded.', 'atmosphere' )
			);
		}

		$current['access_token'] = Encryption::encrypt( $data['access_token'] );
		$current['expires_at']   = \time() + ( $data['expires_in'] ?? 3600 );
		$current['needs_reauth'] = false;

		if ( ! empty( $data['refresh_token'] ) ) {
			$current['refresh_token'] = Encryption::encrypt( $data['refresh_token'] );
		}

		\update_option( 'atmosphere_connection', $current, false );

		return true;
	}

	/**
	 * Acquire the refresh-in-progress lock. Returns true if this caller
	 * now owns the lock; false if another request already holds a
	 * non-expired lock.
	 *
	 * Atomicity comes from the `UNIQUE` index on `wp_options.option_name`:
	 * `INSERT IGNORE` will succeed for exactly one concurrent caller and
	 * silently no-op for the rest. The stored value is the expiry
	 * timestamp; if a holder crashes mid-refresh, the next caller reads
	 * a past expiry and atomically steals the row via a `UPDATE ... WHERE
	 * option_value = $previous` compare-and-swap.
	 *
	 * Direct `$wpdb` queries are used (rather than `add_option`) because
	 * `add_option` is itself a check-then-INSERT and is not safe under
	 * concurrent acquisition. The options cache is invalidated by hand
	 * on every write so a subsequent `get_option` lookup elsewhere in
	 * the codebase does not return a cached value.
	 *
	 * Note: re-locking by the same caller returns false. The lock has
	 * no notion of owner identity; pair every successful `lock()` with
	 * a matching `unlock()` in a `try`/`finally`.
	 *
	 * @return bool
	 */
	public static function lock(): bool {
		global $wpdb;

		$key        = self::REFRESH_LOCK_OPTION;
		$now        = \time();
		$expires_at = $now + self::REFRESH_LOCK_TTL;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				(string) $expires_at,
				'no'
			)
		);

		if ( 1 === (int) $inserted ) {
			self::invalidate_lock_option_cache( $key );
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$key
			)
		);

		if ( $existing > 0 && $now < $existing ) {
			return false;
		}

		/*
		 * Compare-and-swap: only steal the lock if the row still holds
		 * the timestamp we just read. If a third caller stole the row
		 * between the SELECT and this UPDATE, the WHERE clause filters
		 * us out and we report failure.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stolen = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $expires_at,
				$key,
				(string) $existing
			)
		);

		if ( 1 === (int) $stolen ) {
			self::invalidate_lock_option_cache( $key );
			return true;
		}

		return false;
	}

	/**
	 * Release the refresh-in-progress lock.
	 *
	 * Safe to call unconditionally — a missing row is a no-op.
	 */
	public static function unlock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::REFRESH_LOCK_OPTION ) );
		self::invalidate_lock_option_cache( self::REFRESH_LOCK_OPTION );
	}

	/**
	 * Whether the refresh lock is currently held by any caller.
	 *
	 * Returns false when the lock row is absent or its stored expiry is
	 * in the past — both states indicate the next {@see self::lock()}
	 * call would succeed. Useful for diagnostics and for tests; the
	 * production refresh path uses {@see self::lock()}'s return value
	 * directly to avoid a redundant SELECT.
	 *
	 * @return bool
	 */
	public static function locked(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::REFRESH_LOCK_OPTION
			)
		);

		if ( null === $value ) {
			return false;
		}

		return \time() < (int) $value;
	}

	/**
	 * Invalidate the WP options cache for the lock row so a later
	 * `get_option` call does not see a stale value written by the
	 * direct `$wpdb` queries above.
	 *
	 * @param string $key Option name.
	 */
	private static function invalidate_lock_option_cache( string $key ): void {
		\wp_cache_delete( $key, 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Poll the connection option waiting for the concurrent refresh
	 * holder to write a fresh access token (or to flip
	 * `needs_reauth` on a permanent failure).
	 *
	 * The deadline matches `REFRESH_LOCK_TTL` (45s) rather than a
	 * conservative few-seconds wait. The previous 5-second budget
	 * was shorter than the realistic worst case — two sequential
	 * `wp_safe_remote_post` calls at 15-second timeouts on the
	 * `use_dpop_nonce` retry path plus encryption overhead — which
	 * meant single-shot publish / comment cron events that arrived
	 * mid-refresh would silently drop their content even though the
	 * holder was about to land a fresh token. Aligning with the
	 * lock TTL guarantees we either see the holder's result or
	 * conclude the holder crashed (lock will be reclaimable on the
	 * next call).
	 *
	 * Returns `true` when the stored token has been rotated and is
	 * safe to use, or a `WP_Error` when the holder either flipped
	 * `needs_reauth` (permanent failure) or did not finish in time.
	 * Used by {@see self::access_token()} so that a publish or
	 * comment cron event does not get silently dropped when it
	 * arrives mid-refresh.
	 *
	 * `$current_access_token_ciphertext` lets callers in the API
	 * 401-recovery path require the access-token ciphertext to
	 * *differ* from a known-stale snapshot before declaring the
	 * wait done. The default behaviour (empty string) trusts the
	 * `expires_at` window alone — fine for the `access_token()`
	 * caller, which only waits when `expires_at` was within 5
	 * minutes of now. For a 401 from the PDS, `expires_at` may
	 * still be in the future (the auth server invalidated the jti
	 * without the local clock catching up), so the ciphertext
	 * snapshot is the only reliable "token has actually rotated"
	 * signal.
	 *
	 * @param string $current_access_token_ciphertext Snapshot of
	 *               `atmosphere_connection['access_token']` taken
	 *               BEFORE the failed request whose retry is
	 *               waiting on a fresh token. Empty string disables
	 *               the ciphertext-change requirement.
	 * @return true|\WP_Error
	 */
	public static function wait_for_token_refresh( string $current_access_token_ciphertext = '' ): true|\WP_Error {
		$deadline = \microtime( true ) + (float) self::REFRESH_LOCK_TTL;

		while ( \microtime( true ) < $deadline ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			\usleep( 100000 );

			$conn = \get_option( 'atmosphere_connection', array() );

			if ( ! empty( $conn['needs_reauth'] ) ) {
				return new \WP_Error(
					'atmosphere_needs_reauth',
					\__( 'AT Protocol session expired during concurrent refresh. Reconnect to resume publishing.', 'atmosphere' )
				);
			}

			if ( ! empty( $conn['access_token'] )
				&& ! empty( $conn['expires_at'] )
				&& $conn['expires_at'] > \time() + 300
				&& ( '' === $current_access_token_ciphertext
					|| ( $conn['access_token'] ?? '' ) !== $current_access_token_ciphertext )
			) {
				return true;
			}
		}

		return new \WP_Error(
			'atmosphere_refresh_locked',
			\__( 'Token refresh did not complete in time.', 'atmosphere' )
		);
	}

	/**
	 * Get a usable access token, refreshing if close to expiry.
	 *
	 * @return string|\WP_Error
	 */
	public static function access_token(): string|\WP_Error {
		$conn = \get_option( 'atmosphere_connection', array() );

		if ( ! empty( $conn['needs_reauth'] ) ) {
			return new \WP_Error(
				'atmosphere_needs_reauth',
				\__( 'AT Protocol session expired. Reconnect to resume publishing.', 'atmosphere' )
			);
		}

		if ( empty( $conn['access_token'] ) ) {
			return new \WP_Error( 'atmosphere_not_connected', \__( 'Not connected to AT Protocol.', 'atmosphere' ) );
		}

		// Refresh if expiring within 5 minutes.
		if ( ! empty( $conn['expires_at'] ) && $conn['expires_at'] < \time() + 300 ) {
			$result = self::refresh();

			if ( \is_wp_error( $result ) ) {
				/*
				 * Another caller is refreshing right now. Wait briefly
				 * for them to finish before failing the publish/comment
				 * cron event we are part of: those events are consumed
				 * on dispatch and not retried, so propagating the
				 * `atmosphere_refresh_locked` error would silently drop
				 * the post or comment even though the other caller is
				 * about to rotate the token.
				 */
				if ( 'atmosphere_refresh_locked' === $result->get_error_code() ) {
					$waited = self::wait_for_token_refresh();

					if ( \is_wp_error( $waited ) ) {
						return $waited;
					}
				} else {
					return $result;
				}
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
	 * Disconnect: remove stored credentials and clear queued cron events.
	 *
	 * Drops `atmosphere_connection` (the OAuth session) but deliberately
	 * preserves `atmosphere_identity`. Identity holds the DID, handle, and
	 * PDS endpoint that drive the bidirectional verification headers
	 * (`/.well-known/atproto-did` and the publication link tag). Sites that
	 * adopted a custom domain handle (e.g. `example.com` instead of
	 * `alice.bsky.social`) depend on that route to resolve their handle
	 * back to a DID during reconnect — wiping identity here breaks the
	 * chain: `Resolver::handle_to_did()` then 404s on both DNS TXT and the
	 * HTTPS well-known, and the user is locked out of reconnecting with
	 * their domain handle. The split between identity and credentials was
	 * introduced precisely so a failed token refresh would not break this
	 * route (see `get_identity()`); explicit disconnect must honor the
	 * same invariant. A reconnect overwrites identity in place; sites that
	 * truly want to forget the DID should clear `atmosphere_identity`
	 * separately.
	 *
	 * Queued events (`atmosphere_delete_records`,
	 * `atmosphere_delete_comment_record`) issue applyWrites without a
	 * connection check, so a disconnect→reconnect-to-different-account
	 * cycle would otherwise fire deletes against the new account's repo.
	 * Mirrors the cleanup performed on plugin deactivate / uninstall.
	 *
	 * Best-effort revokes the refresh token at the auth server (RFC 7009)
	 * so a leaked refresh token stops working server-side even though
	 * disconnect already removed it locally. The revocation POST is
	 * deferred to a single-shot WP-Cron event scheduled AFTER
	 * `clear_scheduled_hooks()` runs (so the helper does not clear the
	 * very event we just queued), keeping the admin click responsive:
	 * a slow or unreachable auth server otherwise blocks disconnect
	 * for up to ~20 seconds (two synchronous 10-second POSTs).
	 */
	public static function disconnect(): void {
		$conn = \get_option( 'atmosphere_connection', array() );

		/*
		 * Capture the inputs the revocation worker needs BEFORE we
		 * wipe the local options. The encrypted ciphertexts are passed
		 * directly to the cron worker so it can decrypt them later
		 * without re-reading `atmosphere_connection`, which is about
		 * to be deleted.
		 */
		$revoke_args = null;
		if ( \is_array( $conn )
			&& ! empty( $conn['refresh_token'] )
			&& ! empty( $conn['dpop_jwk'] )
			&& ! empty( $conn['revocation_endpoint'] )
			&& ! empty( $conn['auth_server'] )
		) {
			/*
			 * Pass the auth-server issuer URL alongside the revocation
			 * endpoint. The cron worker binds the two at use time: if
			 * a tampered or backup-restored `atmosphere_connection`
			 * row pointed `revocation_endpoint` at an attacker host,
			 * the refresh token would otherwise be POSTed to that
			 * host even though `is_safe_https_url()` would not block
			 * it (the check only confirms HTTPS + safe host, not the
			 * issuer-binding). Including the issuer here lets the
			 * worker reject endpoint↔issuer mismatches before the
			 * decryption step.
			 */
			$revoke_args = array(
				(string) $conn['refresh_token'],
				(string) $conn['dpop_jwk'],
				(string) $conn['revocation_endpoint'],
				(string) $conn['auth_server'],
			);
		}

		/*
		 * Mark the disconnect as operator-initiated BEFORE wiping the
		 * connection row so the admin reauth notice's copy swap is
		 * race-free. With the marker set first, any concurrent admin
		 * request that loads `Admin::maybe_render_reauth_notice()` while
		 * this method is in-flight sees either: (a) a live connection
		 * (needs_reauth false, notice silent), or (b) a missing
		 * connection AND a set marker (the "disconnected" copy renders).
		 * Reversing the order would expose a narrow window where the
		 * connection is gone but the marker is not yet present — the
		 * gate would fall through to the misleading "session has
		 * expired" wording the marker exists to suppress. Set with
		 * `autoload = false` because the value is only consulted by the
		 * admin notice (single per-request read after a needs_reauth
		 * gate), so paying for it on every page-load is wasteful.
		 *
		 * Stamped with `\time()` so a future caller could expire stale
		 * markers (e.g. after N days); the notice gate itself only
		 * reads truthiness. Cleared on the next successful
		 * authorization (see `handle_callback()`).
		 */
		\update_option( self::DISCONNECTED_OPTION, \time(), false );

		\delete_option( 'atmosphere_connection' );
		\delete_option( self::REFRESH_LOCK_OPTION );

		/*
		 * Sweep a stale option from 1.0.0 installs. `atmosphere_publication_uri`
		 * was cached state that nothing in production ever read — the
		 * well-known endpoint and the Document transformer's `site`
		 * reference both derive the publication URI on demand from
		 * `get_did()` + the publication TID. Drop the row so it does
		 * not linger on disconnected installs. `atmosphere_publication_tid`
		 * stays put because that TID is the stable site-level
		 * identifier that survives reconnects to the same account.
		 */
		\delete_option( 'atmosphere_publication_uri' );

		clear_scheduled_hooks();

		if ( null !== $revoke_args ) {
			\wp_schedule_single_event(
				\time(),
				'atmosphere_revoke_refresh_token',
				$revoke_args
			);
		}
	}

	/**
	 * Best-effort revoke a refresh token at the auth server.
	 *
	 * Invoked by the `atmosphere_revoke_refresh_token` cron hook from
	 * `disconnect()`. Decrypts the ciphertexts the caller captured
	 * before the local state was wiped, and POSTs the token to the
	 * auth server's `revocation_endpoint` per RFC 7009. AT Protocol
	 * auth servers require DPoP-bound revocation, so the request
	 * carries a DPoP proof and retries once on `use_dpop_nonce`
	 * exactly like `refresh()` does. Any failure path returns
	 * silently after logging — disconnect already succeeded locally
	 * by the time this fires.
	 *
	 * @param string $refresh_token_ciphertext Encrypted refresh token
	 *                                         captured at disconnect.
	 * @param string $dpop_jwk_ciphertext      Encrypted DPoP JWK.
	 * @param string $revocation_endpoint      Auth server revocation URL.
	 * @param string $auth_server_issuer       Auth server issuer URL the
	 *                                         revocation endpoint must
	 *                                         share an origin with.
	 */
	public static function revoke_refresh_token(
		string $refresh_token_ciphertext,
		string $dpop_jwk_ciphertext,
		string $revocation_endpoint,
		string $auth_server_issuer = ''
	): void {
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

		/*
		 * Bind the revocation endpoint to the auth-server issuer
		 * origin. `is_safe_https_url()` above confirms the URL is
		 * well-formed HTTPS to a non-private host, but says nothing
		 * about which host. A tampered `atmosphere_connection` row
		 * could have swapped `revocation_endpoint` for
		 * `https://attacker.example/collect` between connect and
		 * disconnect; without this check the cron worker would
		 * happily POST the decrypted refresh token there.
		 *
		 * The comparison is scheme + host + port (origin in the RFC
		 * 6454 sense). The auth-server is always HTTPS (the resolver
		 * already enforces that), so in practice this comes down to
		 * matching the host plus an explicit port if either side
		 * declares one. Missing ports normalise to the HTTPS default
		 * (443) so `auth.example.com` and `auth.example.com:443`
		 * compare equal.
		 */
		if ( '' !== $auth_server_issuer ) {
			$revoke_host = $revocation_endpoint ? \wp_parse_url( $revocation_endpoint, PHP_URL_HOST ) : '';
			$issuer_host = \wp_parse_url( $auth_server_issuer, PHP_URL_HOST );
			$revoke_port = \wp_parse_url( $revocation_endpoint, PHP_URL_PORT );
			$issuer_port = \wp_parse_url( $auth_server_issuer, PHP_URL_PORT );

			if ( ! \is_string( $revoke_host )
				|| ! \is_string( $issuer_host )
				|| '' === $revoke_host
				|| \strtolower( $revoke_host ) !== \strtolower( $issuer_host )
				|| ( $revoke_port ?? 443 ) !== ( $issuer_port ?? 443 )
			) {
				return;
			}
		}

		if ( '' === $refresh_token_ciphertext || '' === $dpop_jwk_ciphertext ) {
			return;
		}

		$refresh_token = Encryption::decrypt( $refresh_token_ciphertext );
		$dpop_jwk_json = Encryption::decrypt( $dpop_jwk_ciphertext );
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
		global $wpdb;

		/*
		 * Why direct $wpdb instead of the options API or a transient?
		 * The rate-limit semantics require atomicity: two concurrent
		 * callers at `attempts = N - 1` must not both pass the cap.
		 * None of the WordPress-idiomatic primitives give us that
		 * cross-process guarantee on a default install:
		 *
		 *   - `set_transient` / `get_transient` is a check-then-set
		 *     pair; the two callers above both `get` 9, both pass
		 *     the `>=` check, both `set` 10. The cap silently
		 *     doubles under contention.
		 *   - `add_option` does a preflight `SELECT` then an
		 *     `INSERT ... ON DUPLICATE KEY UPDATE`, so on the second
		 *     INSERT it overwrites the first caller's value rather
		 *     than failing — also not atomic for our purpose.
		 *   - `wp_cache_add` is atomic on installs with a persistent
		 *     object cache (Redis, Memcached) but degrades to a
		 *     per-request store on default WP, where it cannot see
		 *     a concurrent worker's bucket at all.
		 *
		 * `INSERT IGNORE` is atomic at the SQL layer because of the
		 * UNIQUE index on `wp_options.option_name`. Combined with
		 * the CAS-loop below (`UPDATE ... WHERE option_value = $previous`),
		 * the entire read-decide-write cycle becomes lock-free and
		 * cross-process correct. The bespoke SQL is the price of an
		 * actually-atomic counter; the per-user bucket holds two
		 * digits and an integer expiry, so the storage is cheap.
		 *
		 * The encoded value is `attempts|expires_at` so the whole
		 * bucket is a single packed payload the CAS can replace
		 * with one statement. Window expiry is encoded in the value
		 * itself (rather than relying on transient TTL) so stale
		 * windows reset themselves on the next CAS-iteration.
		 */
		$key        = \sprintf( '_atmosphere_oauth_rate_%d', \get_current_user_id() );
		$now        = \time();
		$expires_at = $now + self::RATE_LIMIT_WINDOW;

		/*
		 * First-in-window race: `INSERT IGNORE` is atomic on the
		 * `wp_options` UNIQUE index, so exactly one concurrent
		 * caller writes the initial `1|expires_at` row. Everyone
		 * else falls through to the CAS-loop below.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				'1|' . $expires_at,
				'no'
			)
		);

		if ( 1 === (int) $inserted ) {
			self::invalidate_lock_option_cache( $key );
			return true;
		}

		/*
		 * Row exists. Walk a bounded compare-and-swap loop: read the
		 * current packed value, decide what the next value should be
		 * (cap-reached / window-expired-reset / increment), then
		 * `UPDATE ... WHERE option_value = $previous`. The WHERE
		 * clause filters out anyone else who beat us between SELECT
		 * and UPDATE, so the loser retries on a fresh read. Five
		 * tries is more than enough under realistic contention; if
		 * we still cannot land an update, fail closed so the
		 * caller backs off rather than passing in a torn state.
		 */
		for ( $i = 0; $i < 5; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$current = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					$key
				)
			);

			$parts            = \explode( '|', $current, 2 );
			$current_attempts = (int) ( $parts[0] ?? 0 );
			$current_expiry   = (int) ( $parts[1] ?? 0 );

			if ( $now >= $current_expiry ) {
				// Window has expired; reset to 1 attempt in a fresh window.
				$next = '1|' . ( $now + self::RATE_LIMIT_WINDOW );
			} elseif ( $current_attempts >= self::RATE_LIMIT_MAX ) {
				return new \WP_Error(
					'atmosphere_rate_limited',
					\__( 'Too many OAuth attempts. Please wait a few minutes and try again.', 'atmosphere' )
				);
			} else {
				$next = ( $current_attempts + 1 ) . '|' . $current_expiry;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$next,
					$key,
					$current
				)
			);

			if ( 1 === $updated ) {
				self::invalidate_lock_option_cache( $key );
				return true;
			}
		}

		return new \WP_Error(
			'atmosphere_rate_limited',
			\__( 'Too many OAuth attempts. Please wait a few minutes and try again.', 'atmosphere' )
		);
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
