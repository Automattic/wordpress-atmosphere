# Well-known endpoints and CORS

The `/.well-known/` handlers and the headers public responses send.

Applies to: `includes/class-atmosphere.php` (rewrite rules and `template_redirect` handlers), `includes/rest/**`

## Endpoints

- `/.well-known/atproto-did` exposes only the configured DID, and unexpected query parameters (`?foo=bar`, `?atmosphere_wellknown=garbage`) cannot make it return data for another site or user.
- `/.well-known/site.standard.publication` exposes only the configured publication AT-URI, nothing derived from the current post context (for example draft data through an injected query var).
- The rewrite rules cannot be abused to reach other well-known paths.
- The `atmosphere_wellknown` query var cannot be injected with unexpected values.
- Response headers (Content-Type, caching) fit each endpoint.

## CORS

- An endpoint not designed for browser clients does not advertise credentialed cross-origin access: `Access-Control-Allow-Origin: *` never comes with `Access-Control-Allow-Credentials: true`.
- Bearer-token endpoints (an OAuth-protected resource) allow credentialed CORS only for an explicit origin allow list, not `*`.
- `Vary: Accept` / `Vary: Origin` are sent where the response differs by those headers, or a CDN caches the wrong variant.
- Preflight (`OPTIONS`) responses do not advertise more than the real handler accepts (`PUT`, `DELETE` or custom auth headers when the handler only takes `GET`).
