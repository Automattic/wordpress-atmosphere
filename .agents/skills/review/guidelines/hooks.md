# Hooks and filters

`atmosphere_` hooks that other plugins can use to weaken a gate, and what the plugin does with a filter's return value.

Applies to: any added or changed `apply_filters()`, `do_action()` or `add_filter()` call

## Past issues

- **Type confusion through filters** (ActivityPub): third-party plugins returned `null` into filters that expected arrays, which caused a class of `array_keys(null)` fatals. The same pattern can break ATmosphere.

## Rules

- Catalog the `atmosphere_` filters and actions the change adds or touches, and name the ones that could weaken a security check if hooked (disabling an auth check, changing token handling).
- A filtered value is checked before it is used as the documented type.
- Hooks that carry tokens or keys cannot be observed by lower-privilege code.
- A boolean filter that gates sensitive behavior (for example `atmosphere_should_publish_comment`) is cast to `bool` at the call site, so a truthy string does not widen the gate.
- The `atmosphere_publishing` action's loop guard cannot be bypassed by a third-party hook that resets it.

## Container validation is not content validation

A filter that builds OAuth client metadata or any other security-sensitive structure validates every entry inside the container, not just its shape. `is_array( $filtered ) && ! empty( $filtered['redirect_uris'] )` accepts `['']`, `[null]`, `[['nested']]` and `['https://evil.example/cb']`. For public-client metadata, where `token_endpoint_auth_method: 'none'` lets anyone use the advertised `client_id`, an off-site `redirect_uris` entry leaks tokens. Run every entry through the gate the equivalent inbound filter applies (for example an `admin_url('')` prefix match).

## Say when a filter broke its contract

A silent fallback on a wrong-shaped return leaves third-party developers looking at a no-op with no log. Every `apply_filters` site that validates the return shape and falls back calls `_doing_it_wrong()`: visible under `WP_DEBUG`, silent in production.

## Scope a filter to the call it protects

`add_filter( 'allowed_redirect_hosts', $closure )` immediately before `wp_safe_redirect()` and `remove_filter(...)` immediately after, instead of relying on the `exit` that ends the script. Production behaves the same; tests or `wp_die()` handlers that intercept the redirect cannot leak the filter into later redirects in the same request.
