---
name: code-style
description: PHP coding standards and WordPress patterns for ATmosphere plugin. Use when writing PHP code, creating classes, implementing WordPress hooks, or structuring plugin files.
---

# ATmosphere PHP Conventions

Plugin-specific conventions and architectural patterns.

## Quick Reference

### File Naming
```
class-{name}.php         # Regular classes.
```

### Namespace Pattern
```php
namespace Atmosphere;
namespace Atmosphere\OAuth;
namespace Atmosphere\Transformer;
namespace Atmosphere\WP_Admin;
```

### Text Domain
Always use `'atmosphere'` for translations:
```php
\__( 'Text', 'atmosphere' );
\esc_html_e( 'Text', 'atmosphere' );
```

### WordPress Global Functions
When in a namespace, always escape WordPress and PHP global functions with backslash:
```php
\get_option(), \add_action(), \is_wp_error(), \strlen(), \time()
```

### Cross-Namespace References
Use `use` imports — never inline `\Namespace\Class`:
```php
use Atmosphere\OAuth\Client;
use function Atmosphere\get_did;
use function Atmosphere\is_connected;
```

## Directory Structure

```
includes/
├── class-*.php              # Core classes.
├── functions.php            # Helper functions.
├── oauth/                   # OAuth flow classes.
├── transformer/             # AT Protocol record transformers.
└── wp-admin/                # Admin functionality.

templates/                   # PHP template files.
assets/                      # CSS and JS.
tests/phpunit/               # PHPUnit tests.
```

## Architectural Patterns

### Transformers
Convert WordPress content into AT Protocol records.

**Base class:** `includes/transformer/class-base.php`

**Pattern:**
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
        // Return or generate TID.
    }
}
```

**Examples:**
- `includes/transformer/class-post.php` — app.bsky.feed.post.
- `includes/transformer/class-document.php` — site.standard.document.
- `includes/transformer/class-publication.php` — site.standard.publication.

### OAuth Classes
Handle the full PKCE + DPoP + PAR native OAuth flow.

- `includes/oauth/class-resolver.php` — Handle → DID → PDS → Auth Server chain.
- `includes/oauth/class-client.php` — OAuth lifecycle (authorize, callback, refresh).
- `includes/oauth/class-dpop.php` — ES256 DPoP proof generation.
- `includes/oauth/class-encryption.php` — libsodium token encryption.

### API Client
`includes/class-api.php` — DPoP-authenticated PDS requests with automatic nonce retry.

### Publisher
`includes/class-publisher.php` — Atomic batch applyWrites for both bsky post + document.

## Hook Patterns

**Filters:**
```php
\apply_filters( 'atmosphere_transform_bsky_post', $record, $post );
\apply_filters( 'atmosphere_transform_document', $record, $post );
\apply_filters( 'atmosphere_transform_publication', $record );
\apply_filters( 'atmosphere_client_metadata', $metadata );
\apply_filters( 'atmosphere_syncable_post_types', array( 'post' ) );
```

## Cron Lifecycle — three-way symmetry

Every plugin-owned `wp_schedule_*` hook MUST also be in `Atmosphere\get_cron_hooks()` (`includes/functions.php`). That single list drives:

- `Atmosphere\deactivate()` (`atmosphere.php`)
- `Atmosphere\OAuth\Client::disconnect()` (`includes/oauth/class-client.php`)
- `uninstall.php`

When adding a new cron hook:

1. Add the hook name to `get_cron_hooks()` — do not duplicate the literal in deactivate / disconnect / uninstall.
2. If the hook handler issues PDS writes without re-checking `is_connected()` (e.g. `atmosphere_delete_records`, `atmosphere_delete_comment_record`), the symmetry is load-bearing: a queued event from a previous connection would otherwise fire against a different repo on reconnect.
3. If the handler stores or sweeps commentmeta / postmeta keys, mirror those keys in `uninstall.php`.

This pattern was extracted in PR #32; see review by @kraftbj for the cross-install risk that motivated it.

## Cron Handler Errors — never swallow `WP_Error`

Cron handlers in `register_async_hooks()` MUST surface `Publisher::*` errors via `error_log()` (typically through `log_cron_error()`). `wp_schedule_single_event` does not retry, so a silent drop loses the only signal operators have for transient PDS failures, expired refresh tokens, or DPoP nonce drift.

When the handler operates on records the caller has already lost local state for (e.g. `atmosphere_delete_comment_record` after the WP comment row is gone), include the TID/identifier in the log line so the orphan is recoverable manually.

## Inflight-state Races

When a cron handler writes meta both *before* an `apply_writes` call (e.g. `Comment::get_rkey()` persists META_TID) and *after* (e.g. `store_comment_result()` writes META_URI), and a concurrent state change can short-circuit the cleanup gates that key off the *post-call* meta, the handler MUST re-check eligibility after the call returns and roll back if needed.

Concrete pattern: `atmosphere_publish_comment` → `reconcile_comment_after_publish()`. Re-fetch the WP object, re-run the eligibility gate, schedule the orphan-cleanup cron (not direct delete) so transient PDS failures retry through the standard channel.
