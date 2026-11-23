# Remote fetches and remote input

Outbound requests along the identity chain (SSRF), DNS egress, and what the plugin does with the JSON it gets back.

Applies to: `includes/oauth/class-resolver.php`, `includes/oauth/class-client.php`, `includes/class-api.php`, `includes/class-handle.php`, `includes/class-reaction-sync.php`, `includes/transformer/class-facet.php`, `includes/transformer/class-post.php`, `includes/wp-admin/class-health-check.php`, and any new `wp_remote_*`, `wp_safe_remote_*` or `dns_get_record()` call

## Outbound requests

The resolution chain handle → DID → PDS → auth server is a multi-hop SSRF surface.

- Every outbound request uses `wp_safe_remote_get/post()`, which blocks private IPv4 by default.
- A malicious handle (for example `evil.internal`) cannot resolve to an internal host.
- DID documents are not fetched from arbitrary URLs found in `plc.directory` responses.
- A crafted DID document's `#atproto_pds` service cannot point at internal services (`http://169.254.169.254`, `http://[::1]`, `http://[fe80::1]`).
- Auth server discovery (`/.well-known/oauth-protected-resource` → `/.well-known/oauth-authorization-server`) cannot chain into internal networks.
- Second-order SSRF: a URL taken from a response, then a URL taken from *that* response, each hop goes through the gate.
- 3xx redirects are not followed blindly to internal hosts.
- Well-known handlers have no open redirect or SSRF through query parameters.
- Mention resolution: every `@handle` the facet extractor resolves costs a DNS TXT lookup plus a possible HTTPS well-known fetch to a host the content author chose, and `dns_get_record()` has no PHP-level timeout. The budget and the commenter gate are in `records.md`.

## A URL that never reaches the HTTP layer

The fetch-time gate in `wp_safe_remote_*` does not run on a URL that is redirected to (`wp_redirect` to the OAuth `authorization_endpoint`), returned from a REST endpoint, persisted into the connection record, or handed to a third-party SDK. Apply an explicit URL-safety gate in plugin code wherever a URL leaves plugin control that way.

A safe HTTPS URL check has all of:

- a non-empty string;
- parses with `wp_parse_url()`;
- scheme `https`, compared lowercased: `\strtolower( $parts['scheme'] ?? '' ) !== 'https'`, because `parse_url()` keeps the original casing;
- a host;
- a host that is not an IP literal: `FILTER_VALIDATE_IP` catches IPv4 (`127.0.0.1`, `169.254.169.254`, RFC1918) and IPv6 (`[::1]`, ULAs); `parse_url()` keeps the brackets on IPv6 hosts, so `trim( $host, '[]' )` first;
- no `user:pass@` credentials.

Decode percent-encoding before the check: `https://%6c%6f%63%61%6c%68%6f%73%74/.well-known/atproto-did` passes a naive string compare against `localhost`. Resolve canonical host names before any allow or block list.

Handlers that *receive* a remote URL (the OAuth callback's `state` round-trip, the publication endpoint) reject internal targets at the route layer, before the URL reaches `wp_safe_remote_*`.

An "allow localhost" override for development checks `wp_get_environment_type() !== 'production'` (or `WP_ENVIRONMENT_TYPE`), so it never applies on a live site.

## IPv6

Core's safe-remote helpers are IPv4-centric. The resolver itself rejects IPv6 targets pointing at:

- loopback (`::1`);
- link-local (`fe80::/10`);
- unique local addresses (`fc00::/7`);
- IPv4-mapped IPv6 (`::ffff:127.0.0.1`, `::ffff:169.254.169.254`);
- multicast (`ff00::/8`);
- IPv6-only third-party hosts whose AAAA records point into internal networks.

## Handle syntax

Beyond the RFC 1035 DNS-label regex, the AT Protocol handle spec requires, and `Resolver::is_valid_handle()` enforces before any network I/O:

- the TLD does not start with a digit and is not all digits (numeric TLDs collide with IP literals);
- reserved TLDs are rejected: `.alt`, `.arpa`, `.example`, `.internal`, `.invalid`, `.local`, `.localhost`, `.onion`, `.test`.

## Responses: JSON and input resilience

Files: `includes/class-api.php`, `includes/oauth/class-resolver.php`, anything that consumes a PDS or auth server response.

- Every `json_decode()` checks for `JSON_ERROR_NONE` (or uses `wp_json_decode()` and checks `is_wp_error()`).
- No field is assumed to be an array because it was last time: guard with `is_array()` / `is_string()` before iterating or indexing. ActivityPub had a class of `array_keys(null)` fatals from unexpected types (see `hooks.md`).
- `json_decode` gets a sane depth limit (third argument), and oversized payloads are rejected at the HTTP layer (`max_input_vars`, body-size caps).
- Lists from a PDS (records, reactions, facets) are walked with a hard upper bound (for example 100 items). A hostile PDS could otherwise return 50k entries and exhaust memory.

PHP 8.1+ offset access on scalars: `empty( $x['a']['b'] )` does not short-circuit before the inner access. When `$x['a']` is a scalar, because remote JSON returned `{"a": "scalar"}`, PHP warns "Trying to access array offset on value of type X". Guard the parent first:

```php
// BAD on PHP 8.1+: warning if authorization_servers is a string
if ( empty( $resource['authorization_servers'][0] ) ) { … }

// GOOD: parent guarded first
if ( ! isset( $resource['authorization_servers'] )
    || ! is_array( $resource['authorization_servers'] )
    || empty( $resource['authorization_servers'][0] )
) { … }
```
