---
name: code-style
description: PHP coding standards and WordPress patterns for ATmosphere plugin. Use when writing PHP code, creating classes, implementing WordPress hooks, or structuring plugin files.
---

# ATmosphere PHP Conventions

Quick-reference for everyday work. Full reference: [`docs/php-coding-standards.md`](../../../docs/php-coding-standards.md) and [`docs/php-class-structure.md`](../../../docs/php-class-structure.md).

## Non-Negotiables

- **Text domain:** always `'atmosphere'`.
- **Tabs for indentation**, spaces inside parentheses, `array()` (not `[]`).
- **Backslash-prefix** WordPress and PHP global functions in namespaced code: `\get_option()`, `\add_action()`, `\apply_filters()`, `\strlen()`.
- **Use** imports for cross-namespace references — never inline `\Atmosphere\OAuth\Client`.
- **Yoda conditions** for value-vs-variable comparisons: `if ( 'value' === $variable )`.
- **`unreleased`** for `@since` / `@deprecated` tags on new code — the release script rewrites them.

## File and Class Layout

```
includes/
├── class-*.php              # Atmosphere, API, Publisher, Backfill, Handle, Post_Types, Reaction_Sync, Autoloader.
├── functions.php
├── content-parser/          # Content_Parser interface for site.standard.document.
├── oauth/                   # Client, DPoP, Encryption, Nonce_Storage, Resolver.
├── transformer/             # Post, Document, Publication, Comment, Facet, TID (extend Base).
└── wp-admin/                # Admin UI.
integrations/                # Plugin-specific content-parser integrations.
```

After adding or renaming a class file: `composer dump-autoload`.

## Transformer Pattern

```php
namespace Atmosphere\Transformer;

class Custom extends Base {
    public function transform(): array {
        // Build the AT Protocol record array.
    }

    public function get_collection(): string {
        return 'app.custom.collection';
    }

    public function get_rkey(): string {
        // Reserve or return the TID; persist to META_TID so it survives retries.
    }
}
```

Always reserve the rkey via meta in `get_rkey()` — that meta key is the marker `Publisher::update_post()` uses to distinguish "never published" from "publish attempt failed mid-flight."

## Hook Quick-Reference

**Transform filters:** `atmosphere_transform_bsky_post`, `atmosphere_transform_comment`, `atmosphere_transform_document`, `atmosphere_transform_publication`.

**Content / composition:** `atmosphere_content_parser`, `atmosphere_document_content`, `atmosphere_long_form_composition`, `atmosphere_teaser_thread_posts`.

**Gating:** `atmosphere_syncable_post_types`, `atmosphere_should_publish_comment`, `atmosphere_should_sync_reply`, `atmosphere_backfill_limit`, `atmosphere_oauth_redirect_uri`, `atmosphere_client_metadata`.

**Actions:** `atmosphere_publishing`, `atmosphere_publish_post_result`, `atmosphere_publish_comment_result`, `atmosphere_update_skipped_unsynced_post`, `atmosphere_long_form_strategy_downgraded`, `atmosphere_reaction_synced`.

**Test-only:** `atmosphere_pre_apply_writes` — Publisher fixture uses this to short-circuit `apply_writes` before the HTTP layer.

Full signatures and docblocks: [`docs/php-coding-standards.md → Hook Patterns`](../../../docs/php-coding-standards.md#hook-patterns).

## Post Visibility and Federation Cleanup

Federation output is remote, site-wide state. Treat a post as publishable only when it is `publish`, its post type is supported, and `post_password` is empty. Do not use `post_password_required()` for AT Protocol records: it depends on the current visitor's unlock cookie and can leak protected content into PDS records.

When a previously-published post leaves public visibility (draft, pending, private, trash, custom non-public status, password applied, or post type support removed), delete remote records rather than updating them with redacted content. Status transitions may queue the normal delete event, but stale publish/update cron handlers must re-check visibility at fire time and call `Publisher::delete_post( $post )` directly when local record metadata exists.

## Cron Lifecycle — Three-Way Symmetry

Every plugin-owned `wp_schedule_*` hook MUST appear in `Atmosphere\get_cron_hooks()` (`includes/functions.php`). That list drives:

- `Atmosphere\deactivate()` (`atmosphere.php`)
- `Atmosphere\OAuth\Client::disconnect()`
- `uninstall.php`

When adding a new cron hook:

1. Add it to `get_cron_hooks()` — never duplicate the literal in deactivate / disconnect / uninstall.
2. If the handler issues PDS writes without re-checking `is_connected()`, the symmetry is load-bearing: a queued event from a previous connection would otherwise fire against a different repo on reconnect.
3. If the handler stores or sweeps post/comment meta keys, mirror those keys in `uninstall.php`.

This pattern was extracted in PR #32 (review by @kraftbj). Full rationale: [`docs/php-coding-standards.md → Cron-Specific Rules`](../../../docs/php-coding-standards.md#cron-specific-rules).

## Cron Handler Errors — Never Swallow `WP_Error`

Cron handlers in `register_async_hooks()` MUST surface `Publisher::*` errors via `error_log()` — typically through `log_cron_error()`. `wp_schedule_single_event` does not retry, so a silent drop loses the only signal operators have for transient PDS failures, expired refresh tokens, or DPoP nonce drift.

When the handler operates on records the caller has already lost local state for (e.g. `atmosphere_delete_comment_record` after the WP comment row is gone), include the TID/identifier in the log line so the orphan is recoverable manually.

## Inflight-State Races

When a cron handler writes meta both *before* an `apply_writes` call (e.g. `Comment::get_rkey()` persists `META_TID`) and *after* (e.g. `store_comment_result()` writes `META_URI`), and a concurrent state change can short-circuit the cleanup gates that key off the *post-call* meta, the handler MUST re-check eligibility after the call returns and roll back if needed.

Concrete pattern: `atmosphere_publish_comment` → `reconcile_comment_after_publish()`. Re-fetch the WP object, re-run the eligibility gate, schedule the orphan-cleanup cron (not direct delete) so transient PDS failures retry through the standard channel.

## When to Read the Full Docs

- **Naming conventions** (classes / methods / files / hooks) — [`docs/php-coding-standards.md → Naming Conventions`](../../../docs/php-coding-standards.md#naming-conventions).
- **Security** (escaping, sanitization, nonces, capability checks) — [`docs/php-coding-standards.md → Security Practices`](../../../docs/php-coding-standards.md#security-practices).
- **Error handling** (returning, checking, aggregating `WP_Error`) — [`docs/php-coding-standards.md → Error Handling`](../../../docs/php-coding-standards.md#error-handling).
- **Performance** (caching, capped collections, batching) — [`docs/php-coding-standards.md → Performance Considerations`](../../../docs/php-coding-standards.md#performance-considerations).
- **Documentation standards** (DocBlock format, `@since` policy, inline comment style) — [`docs/php-coding-standards.md → Documentation Standards`](../../../docs/php-coding-standards.md#documentation-standards).
- **Class structure** (where new classes go, namespace hierarchy, integration patterns) — [`docs/php-class-structure.md`](../../../docs/php-class-structure.md).
