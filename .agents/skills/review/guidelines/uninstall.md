# Uninstall and cleanup

What is left behind after disconnect, deactivation and uninstall.

Applies to: `uninstall.php`, deactivation and disconnect paths, `includes/oauth/class-nonce-storage.php`

- Uninstall removes all stored tokens and keys, and nothing sensitive stays in `wp_options` or `wp_postmeta`.
- OAuth credentials are revoked at the PDS before the local delete (see "Revocation" in `oauth.md`).
- Deactivation clears scheduled events (see "Unscheduling" in `cron.md`).
- Deletion goes through `delete_transient()` / `delete_option()`, never a raw `DELETE FROM wp_options`, so the object cache is invalidated with the row.

## Transients in an object cache

With a persistent object cache (Redis, Memcached), transients live in the cache, not in `wp_options`. A `SELECT … LIKE '_transient_…%'` sweep returns no rows there and cleans nothing. Either:

1. track dynamic keys at write time in a registry and iterate it on uninstall; or
2. document the residual, confirm the leftover keys hold no decryptable secrets, and keep their TTLs short.

## Constants in pre-bootstrap code

`uninstall.php` runs before the plugin autoloader, so it cannot reference `Nonce_Storage::PREFIX` without bootstrapping it. Keep the literal in `uninstall.php` with a `MUST stay in lock-step with X::Y` comment naming the constant, so a rename finds both by grep. Without it, a renamed constant silently orphans rows the sweep no longer matches.

## Multisite

Everything in a typical `uninstall.php` runs against the current blog (`delete_option`, `$wpdb->postmeta`). On a network-activated install, the other blogs keep their full state, encrypted credentials included. Either:

1. wrap the cleanup in `is_multisite()` / `get_sites()` / `switch_to_blog()`; or
2. document multisite as unsupported in `readme.txt`, and check the rest of the plugin makes no multisite assumptions either.

A plugin with no `switch_to_blog()` anywhere in `includes/` is implicitly option 2; the fix is documentation, not code.
