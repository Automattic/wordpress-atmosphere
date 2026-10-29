# OAuth, DPoP and tokens

The native OAuth flow (PKCE, PAR, DPoP) and the credentials it leaves behind.

Applies to: `includes/oauth/**`, `includes/class-api.php`, `includes/rest/class-client-metadata-controller.php`, `includes/rest/class-legacy-client-metadata-controller.php`, `includes/rest/admin/class-connection-controller.php`

## Flow

- PKCE: `S256` is required, `plain` is blocked.
- PAR: the `request_uri` is validated.
- `redirect_uri` validation cannot be steered to leak tokens.
- The authorization server discovery chain (handle → DID → PDS → auth server) cannot be poisoned (see `remote-fetch.md`).
- The client metadata endpoint exposes nothing sensitive.
- Refresh invalidates the old tokens.
- Refresh errors tell transient failures (network, 5xx) apart from permanent ones (revoked, `invalid_grant`).
- Distinct failure modes get distinct error codes. One `WP_Error` code reused across "transient missing", "legacy session shape" and "decrypt OK but plaintext malformed" leaves support unable to triage and users without the right remediation.

## DPoP proofs

Files: `includes/oauth/class-dpop.php`

- ES256 keys are generated on the P-256 curve with proper entropy.
- Private keys never reach logs, error messages or HTTP responses. The public JWK in the proof header never carries the private `d` parameter.
- The `ath` claim binds the proof to the access token, and the proof's `jwk` header matches the key bound to that token. Accepting a different, caller-supplied `jwk` next to a valid `ath` is a confused deputy.
- `htu` and `htm` match the request.
- `jti` has enough entropy to prevent replay.
- Server-provided nonces are stored and replayed correctly.
- With native OpenSSL signing: DER-to-raw signature conversion handles leading zeros and variable-length integers, and JWK-to-PEM conversion produces valid SEC1/PKCS#8 structures.

Freshness, for proofs and for any signed nonce or timestamp coming back from the auth server or PDS:

- `iat` is required, and its window is a few minutes either side of the server clock, not hours. A wide window lets an intercepted proof be replayed.
- Where the spec allows `exp` or `nbf`, the future expiry is capped at a small bound (for example 5 minutes), so nobody can mint a long-lived proof.

## Tokens, keys and secrets

Files: `includes/oauth/class-encryption.php`, `includes/oauth/class-client.php`, `includes/oauth/class-nonce-storage.php`, `includes/class-api.php`

- Encryption uses libsodium with proper key derivation.
- Tokens are never logged, put in error messages or stored in plaintext. PDS error responses do not pass token values to the browser.
- DPoP private keys (ES256) are stored encrypted and cannot be extracted through the admin UI.
- The connection data in `wp_options` is encrypted and not readable by lower-privilege users.
- Token scope is checked before PDS requests.
- Nonce storage (`class-nonce-storage.php`) cannot be written by unauthenticated users.
- Every crypto output is computed into locals first; state is written only after every encrypt succeeded. `Encryption::encrypt()` can throw (`random_bytes`, `sodium_crypto_secretbox`); inlined into the third `set_transient()` argument, a throw leaves the first two transients orphaned for the full TTL.

## Revocation

Files: `includes/oauth/class-client.php` (`disconnect()`), `uninstall.php`

- Disconnect revokes the refresh token at the auth server (`POST /oauth/revoke`). A local-only delete leaves a refresh token that stays valid wherever it leaked to.
- Uninstall cleans up local state and remote tokens, so a credential leaked from a deleted install does not stay valid forever (see `uninstall.md`).
