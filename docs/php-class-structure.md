# Class Structure and Organization

## Table of Contents
- [Directory Layout](#directory-layout)
- [Core Components](#core-components)
- [Namespace Organization](#namespace-organization)
- [File Placement Guidelines](#file-placement-guidelines)
- [Architectural Patterns](#architectural-patterns)
- [Class Design Patterns](#class-design-patterns)

## Directory Layout

```
wordpress-atmosphere/
├── atmosphere.php                  # Main plugin file (header, bootstrap, autoloader registration).
├── uninstall.php                   # WordPress uninstall hook — removes meta, options, scheduled events.
│
├── includes/                       # Core plugin code.
│   ├── class-atmosphere.php        # Plugin orchestration; rewrite rules + well-known handlers; cron registration.
│   ├── class-api.php               # DPoP-authenticated PDS request layer with nonce retry.
│   ├── class-autoloader.php        # Custom classmap autoloader.
│   ├── class-backfill.php          # Bulk re-sync of existing posts.
│   ├── class-handle.php            # Domain-handle setup helper (writes /.well-known/atproto-did).
│   ├── class-post-types.php        # Supported post-type discovery and option storage.
│   ├── class-publisher.php         # Atomic applyWrites for both Bluesky post + standard.site document.
│   ├── class-reaction-sync.php     # Mirrors Bluesky reactions back to WordPress comments.
│   ├── functions.php               # Helper functions (loaded via Composer `files`).
│   │
│   ├── content-parser/             # Pluggable content formats for site.standard.document.
│   │   └── interface-content-parser.php
│   │
│   ├── oauth/                      # Native OAuth flow (PKCE + DPoP + PAR).
│   │   ├── class-client.php        # OAuth lifecycle (authorize, callback, refresh, disconnect).
│   │   ├── class-dpop.php          # ES256 DPoP proof generation.
│   │   ├── class-encryption.php    # libsodium token / key encryption at rest.
│   │   ├── class-nonce-storage.php # DPoP nonce persistence.
│   │   └── class-resolver.php      # handle → DID → PDS → auth server resolution chain.
│   │
│   ├── transformer/                # WordPress → AT Protocol record transformers.
│   │   ├── class-base.php          # Abstract base.
│   │   ├── class-comment.php       # Comment → app.bsky.feed.post (reply).
│   │   ├── class-document.php      # Post → site.standard.document.
│   │   ├── class-facet.php         # Detects links, mentions, hashtags in post text.
│   │   ├── class-post.php          # Post → app.bsky.feed.post.
│   │   ├── class-publication.php   # Site → site.standard.publication.
│   │   └── class-tid.php           # AT Protocol Timestamp ID generation.
│   │
│   └── wp-admin/                   # Admin functionality.
│       └── class-admin.php         # Settings page, sidebar panel, REST handlers.
│
├── integrations/                   # Third-party plugin integrations.
│   └── class-load.php              # Integration loader stub.
│
├── templates/                      # PHP template files.
├── assets/                         # CSS and JS.
├── bin/                            # Build/release scripts (release.js, install-wp-tests.sh).
├── docs/                           # This directory.
└── tests/
    └── phpunit/                    # PHPUnit tests, mirroring `includes/`.
```

## Core Components

### Plugin Bootstrap (`atmosphere.php`)

The main file registers the autoloader, defines `ATMOSPHERE_VERSION` and path constants, instantiates `Atmosphere\Atmosphere`, and wires plugin activation / deactivation / uninstall.

### Atmosphere (`includes/class-atmosphere.php`)

Plugin orchestration class:

- Registers rewrite rules + `template_redirect` handlers for `/.well-known/atproto-did` and `/.well-known/site.standard.publication`. All share the `atmosphere_wellknown` query var.
- Registers async cron hooks (`register_async_hooks()`) that delegate to the Publisher / Reaction_Sync.
- Listens on `transition_post_status` and comment-status transitions to schedule cross-post / update / delete jobs.

### API (`includes/class-api.php`)

DPoP-authenticated PDS request layer:

- `apply_writes( array $writes )` — the only PDS write path. Filters through `atmosphere_pre_apply_writes` first for test interception.
- Handles DPoP nonce retry transparently: on `use_dpop_nonce` server hint, recompute the proof with the new nonce and retry once.
- Returns `WP_Error` for non-2xx PDS responses, with `data.status` set to the HTTP code so callers can branch on transient vs permanent failures.

### Publisher (`includes/class-publisher.php`)

Atomic batch publish path:

- `publish_post()` — initial publish of a WordPress post (single record + document, or a teaser thread).
- `update_post()` — in-place update or destructive rewrite based on whether the new record count matches the stored count.
- `delete_post()` / `delete_post_by_tids()` — removes both Bluesky and document records.
- `publish_comment()` — cross-posts a WordPress comment as a Bluesky reply.

### Transformers (`includes/transformer/`)

Convert WordPress objects to AT Protocol records. Extend `Atmosphere\Transformer\Base`:

```php
namespace Atmosphere\Transformer;

abstract class Base {
    /**
     * Build the AT Protocol record array.
     *
     * @return array
     */
    abstract public function transform(): array;

    /**
     * The collection (NSID) this transformer writes to.
     */
    abstract public function get_collection(): string;

    /**
     * Reserve or return the rkey (TID) for this record.
     *
     * Writes Post::META_TID (or the equivalent) on first call so
     * the rkey is reused across retries.
     */
    abstract public function get_rkey(): string;
}
```

Concrete transformers:

| Class | Produces |
|-------|----------|
| `Atmosphere\Transformer\Post` | `app.bsky.feed.post` (short-form + teaser-thread variants). |
| `Atmosphere\Transformer\Comment` | `app.bsky.feed.post` reply under a cross-posted record. |
| `Atmosphere\Transformer\Document` | `site.standard.document`. |
| `Atmosphere\Transformer\Publication` | `site.standard.publication`. |

`Atmosphere\Transformer\Facet` is a helper, not a record producer — it detects links, mentions, and hashtags in post text. `Atmosphere\Transformer\TID` generates Timestamp IDs.

### OAuth (`includes/oauth/`)

Full PKCE + DPoP + PAR native OAuth flow. The handle → DID → PDS → Auth Server resolution chain is implemented across `class-resolver.php` (resolution) and `class-client.php` (OAuth lifecycle). DPoP proofs are generated in `class-dpop.php` (ES256). Tokens and the DPoP private key are encrypted at rest via `class-encryption.php` (libsodium).

### Reaction Sync (`includes/class-reaction-sync.php`)

Periodically polls the PDS for notifications and self-collections (`app.bsky.feed.like`, `app.bsky.feed.repost`, `app.bsky.feed.post`) and stores them as WordPress comments. Replies become regular comments; likes and reposts become dedicated comment types so they show up as engagement counts.

### Content Parser (`includes/content-parser/`)

Provides the `Atmosphere\Content_Parser\Content_Parser` interface for plugins that want to populate the `content` field of `site.standard.document` records (see [`docs/content-formats.md`](content-formats.md)). The plugin ships only the interface — concrete parsers register through the `atmosphere_content_parser` filter from `integrations/` or third-party plugins.

## Namespace Organization

```php
// Root namespace.
namespace Atmosphere;

// Feature namespaces.
namespace Atmosphere\OAuth;
namespace Atmosphere\Transformer;
namespace Atmosphere\Content_Parser;
namespace Atmosphere\Integrations;
namespace Atmosphere\WP_Admin;
namespace Atmosphere\Tests;
```

### Using Namespaces

```php
<?php
namespace Atmosphere;

use Atmosphere\OAuth\Client;
use Atmosphere\Transformer\Post;
use Atmosphere\Transformer\Document;
use function Atmosphere\is_connected;
use function Atmosphere\get_did;

class Publisher {
    public static function publish_post( \WP_Post $post ) {
        // Imported classes used unqualified.
        $bsky = new Post( $post );
        $doc  = new Document( $post );

        // WordPress and PHP globals are backslash-prefixed.
        if ( ! is_connected() ) {
            return new \WP_Error( 'atmosphere_not_connected', \__( 'Not connected.', 'atmosphere' ) );
        }
    }
}
```

## File Placement Guidelines

### File Naming Rules

| Type | Pattern | Example |
|------|---------|---------|
| Class | `class-{name}.php` | `class-publisher.php` |
| Trait | `trait-{name}.php` | `trait-singleton.php` |
| Interface | `interface-{name}.php` | `interface-content-parser.php` |
| Functions | `functions.php` | `includes/functions.php` |
| Templates | `{name}.php` | `templates/admin-settings.php` |

### Where to Place New Classes

| Class Type | Location | Namespace |
|------------|----------|-----------|
| Core functionality | `includes/` | `Atmosphere` |
| AT Protocol record transformers | `includes/transformer/` | `Atmosphere\Transformer` |
| OAuth flow components | `includes/oauth/` | `Atmosphere\OAuth` |
| Content parsers (NSID-typed `content` producers) | `includes/content-parser/` | `Atmosphere\Content_Parser` |
| Admin screens / REST handlers | `includes/wp-admin/` | `Atmosphere\WP_Admin` |
| Third-party plugin integrations | `integrations/` | `Atmosphere\Integrations` |

### Creating a New Subdirectory

Add one when you have:

- Multiple related classes (3+) that form a cohesive subsystem.
- A clear domain boundary (e.g. all OAuth-flow concerns live in `oauth/`).
- A reason to keep the concerns from leaking into surrounding files.

After adding or renaming a class file, run `composer dump-autoload` so the Composer classmap picks it up.

## Architectural Patterns

### Transformer Pattern

The transformer pattern (`includes/transformer/`) is the canonical way to add a new AT Protocol record type:

```php
namespace Atmosphere\Transformer;

class Custom_Record extends Base {
    public function transform(): array {
        return array(
            '$type'     => 'app.example.record',
            'createdAt' => to_iso8601( $this->object->post_date_gmt ),
            // ...
        );
    }

    public function get_collection(): string {
        return 'app.example.record';
    }

    public function get_rkey(): string {
        $rkey = \get_post_meta( $this->object->ID, self::META_TID, true );
        if ( ! $rkey ) {
            $rkey = TID::generate();
            \update_post_meta( $this->object->ID, self::META_TID, $rkey );
        }
        return $rkey;
    }
}
```

Always reserve the rkey at the start of `get_rkey()` and persist it via meta. The reserved rkey survives a failed publish and is reused on retry — this is the marker that distinguishes "pristine post" from "failed prior attempt" in `Publisher::update_post()`.

### Static Initialization

```php
class Feature {
    public static function init(): void {
        \add_action( 'init', array( self::class, 'register' ) );
        \add_filter( 'the_content', array( self::class, 'filter' ) );
    }

    public static function register(): void {
        // Registration logic.
    }
}
```

Most plugin classes are static — there's no per-request state worth carrying in instances. Use `self::class` for callback strings rather than hardcoding the FQCN.

### Singleton (use sparingly)

```php
class Manager {
    private static ?self $instance = null;

    private function __construct() {}

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
```

Singletons make tests harder; prefer static helper classes unless you genuinely need lazy initialisation.

### Factory

```php
namespace Atmosphere\Transformer;

class Factory {
    public static function for_object( $object ): Base {
        if ( $object instanceof \WP_Post ) {
            return new Post( $object );
        }
        if ( $object instanceof \WP_Comment ) {
            return new Comment( $object );
        }
        throw new \InvalidArgumentException( 'Unsupported object type.' );
    }
}
```

### Integration Loader

`integrations/class-load.php` is the canonical entry point for plugin-specific integrations. Conditional registration based on plugin detection:

```php
namespace Atmosphere\Integrations;

class Load {
    public static function init(): void {
        \add_action( 'plugins_loaded', array( self::class, 'register' ), 20 );
    }

    public static function register(): void {
        if ( \defined( 'JETPACK__VERSION' ) ) {
            Jetpack::init();
        }
    }
}
```

See [`integrations/README.md`](../integrations/README.md) for the full pattern, including how `atmosphere_content_parser` and `atmosphere_document_content` filters compose.
