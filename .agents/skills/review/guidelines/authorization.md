# Authorization

Who may call a route, change a setting, see a post's projected data or delete a record, and how often.

Applies to: `includes/rest/**`, `includes/wp-admin/**`, `includes/oauth/**`, `includes/class-atmosphere.php`, `includes/transformer/class-preview.php`

## Past issues

- **Generic capability on a per-object surface** (#170 review): the `?atproto` preview gated on blanket `edit_posts`, so any author could read another author's projected records, including saved custom Bluesky text that role cannot otherwise see.

## Capabilities and nonces

- Admin pages check `manage_options` or the fitting capability.
- Every form submission (settings, OAuth connect and disconnect) has a nonce check. Each `phpcs:ignore WordPress.Security.NonceVerification` explains why it is safe.
- Unauthorized users cannot trigger the OAuth callback.
- Per-post controls in the editor check `edit_post`.
- AJAX and REST handlers check capabilities before processing.
- Settings API registrations have proper sanitize callbacks.

## Object-level capabilities

A handler that serves data derived from one post (the REST pre-publish projection, the front-end preview, editor panel data) gates on the meta capability for that post, `current_user_can( 'edit_post', $post->ID )`, not on a blanket capability like `edit_posts`. Mirror whatever the equivalent REST controller gates on. The blanket form lets any author read every author's per-post data.

## Who may delete a record

Files: delete paths in `includes/class-publisher.php` and `includes/class-reaction-sync.php`.

Before deleting on the PDS, the AT-URI's repo DID matches the connected account. A request from one user never deletes records owned by another.

## Rate limiting

Files: `includes/oauth/`, `includes/wp-admin/`, REST handlers.

- Every endpoint that mints, validates or accepts a token has per-IP rate limiting, or relies on a documented WordPress core or hosting-layer protection.
- OAuth start, callback and any retry endpoint are rate-limited. Repeated `state` mismatches or PKCE failures are an abuse signal, not an error to ignore.
- Per-IP buckets trust only the TCP peer by default. Keying on `$_SERVER['HTTP_X_FORWARDED_FOR']` is spoofable on directly exposed sites; default to `$_SERVER['REMOTE_ADDR']`, and honor proxy headers only through an explicit opt-in filter (for example `atmosphere_client_ip_sources`). Sites behind Cloudflare, Akamai or nginx need the filter; sites without a reverse proxy must not have it on.
- Fail closed when the client IP is unknown. An empty `REMOTE_ADDR` or a malformed `X-Forwarded-For` does not share one bucket with every other unidentified request; reject, or give it a conservatively limited bucket of its own.
- The OAuth token and refresh endpoints answer with `429 Too Many Requests` plus `Retry-After`, not a silent 401.
