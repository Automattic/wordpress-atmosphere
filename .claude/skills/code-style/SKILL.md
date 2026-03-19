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
