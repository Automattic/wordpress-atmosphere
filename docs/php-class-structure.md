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
│   ├── class-autoloader.php        # Custom WordPress-style autoloader.
│   ├── class-backfill.php          # Bulk re-sync of existing posts.
│   ├── class-block-editor.php      # Enqueues the block-editor panels (share toggle + pre-publish).
│   ├── class-handle.php            # Domain-handle setup helper (writes /.well-known/atproto-did).
│   ├── class-post-types.php        # Supported post-type discovery and option storage.
│   ├── class-publisher.php         # Atomic applyWrites for both Bluesky post + standard.site document.
│   ├── class-reaction-sync.php     # Mirrors Bluesky reactions back to WordPress comments.
│   ├── functions.php               # Helper functions (loaded directly from atmosphere.php).
│   │
│   ├── content-parser/             # Pluggable content formats for site.standard.document.
│   │   ├── class-html.php
│   │   ├── class-leaflet.php
│   │   ├── class-markpub.php
│   │   ├── class-parser-base.php
│   │   ├── class-pckt.php
│   │   ├── class-registry.php
│   │   └── interface-content-parser.php
│   │
│   ├── oauth/                      # Native OAuth flow (PKCE + DPoP + PAR).
│   │   ├── class-client.php        # OAuth lifecycle (authorize, callback, refresh, disconnect).
│   │   ├── class-dpop.php          # ES256 DPoP proof generation.
│   │   ├── class-encryption.php    # libsodium token / key encryption at rest.
│   │   ├── class-nonce-storage.php # DPoP nonce persistence.
│   │   └── class-resolver.php      # handle → DID → PDS → auth server resolution chain.
│   │
│   ├── rest/                       # REST API controllers (WP_REST_Controller subclasses).
│   │   ├── class-client-metadata-controller.php  # Public OAuth client-metadata endpoint.
│   │   └── admin/                  # Authenticated, editor-only controllers.
│   │       └── class-pre-publish-controller.php   # Pre-publish projection for the editor panel.
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
│   └── wp-admin/                   # Admin screens.
│       ├── class-admin.php         # Settings page, OAuth callback, menu wiring.
│       └── class-settings-fields.php  # Settings API field rendering.
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
- Emits the front-end record mapping on `wp_head` (see below).
- Registers async cron hooks (`register_async_hooks()`) that delegate to the Publisher / Reaction_Sync.
- Listens on `transition_post_status` and comment-status transitions to schedule cross-post / update / delete jobs.

#### Front-end record mapping

Three `wp_head` emitters advertise which AT Protocol records a page maps to. All resolve their AT-URIs through the same private helpers (`current_document_uri()`, `publication_uri()`, `current_bsky_post_uri()`), so the gating — `has_identity()` rather than `is_connected()`, and a required stored record URI — lives in one place.

Those helpers memoize through `head_memo()`, keyed by resolver, queried object, and connected DID. The emitters overlap heavily, and the shared `is_post_publishable()` check walks every registered post type and fires `atmosphere_syncable_post_types` on each call, so an unmemoized head render resolves it four times.

The memo is scoped to the head render, not to the process: `flush_head_record_cache()` is hooked on `wp_head` at priority 0, ahead of the emitters at the default 10. A process static would live as long as the process, which is one request under mod_php or FPM but many under a persistent runtime (FrankenPHP worker mode, Swoole, RoadRunner) — there, a post whose document was re-minted to a new rkey would keep being advertised under the old AT-URI until the worker recycled. Tests call the same method directly, since a test process is not a request either.

| Emitter | Output |
|---------|--------|
| `output_document_link()` | `<link rel="site.standard.document">` on a singular publishable post with a document record. |
| `output_publication_link()` | `<link rel="site.standard.publication">` on the front page and on singular publishable posts. |
| `output_at_tags()` | [AT Tags](https://tangled.org/chrisshank.com/at-tags/) `<meta>` tags, as emitted by Leaflet and others. |

**Record URIs are read, never rebuilt.** Both per-post helpers return the URI the Publisher stored, validated through `verified_record_uri()` against the current DID, the expected collection, and a non-empty rkey. Reassembling a URI from `get_did()` plus the post's TID looks equivalent but retargets the record at whichever account is connected now: after a reconnect to a different account the TID still belongs to the old repo, so the page would advertise a record that exists nowhere. The publication URI is the exception — it is built from `get_did()` plus the site-level TID, both of which belong to the current connection by construction.

This is also why the front end no longer calls `Document::get_rkey()`. That call refreshes `Document::META_DID` to the current DID, and the head emitters were its only render-time callers, so a pageview can no longer move a post's recorded origin out from under the cleanup guards.

The AT Tags mapping: `at:canonical` for records the page is a rendering of, `at:alternate` for records it merely references. A singular post marks its document canonical, with the parent publication and the companion `app.bsky.feed.post` as alternates; the front page marks the publication canonical. Repeated names are arrays, so a static front page that is also a publishable post emits two `at:canonical` lines. Both alternates depend on the document being present — a page with no canonical record has nothing for an alternate to reference.

`at:author` and `at:me` are not emitted — a site has one connected account, so `at:author` would attribute every post on a multi-author site to whoever connected it. Add them (or a namespaced `at:{namespace}:{property}`) through the `atmosphere_at_tags` filter, which receives the assembled `name => [uris]` array.

The `<link rel>` tags and the meta tags ship together; the meta tags are additive.

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

Provides the parser registry for the singular `content` field of `site.standard.document` records (see [`docs/content-formats.md`](content-formats.md)). Built-in parsers live in this directory (`Html`, `Markpub`, `Leaflet`, `Pckt`) and integrations register additional `Content_Parser` instances with `Atmosphere\Content_Parser\Registry::register()`.

`Content_Parser` stays intentionally small: `get_type()` and `parse()`. Parsers that need WordPress helpers should extend `Parser_Base`, which provides block-tree access, rendered HTML, image blob helpers, grapheme truncation, and an optional `applies_to()` method the registry understands. Parsers without `applies_to()` are treated as applicable for third-party compatibility.

## Namespace Organization

```php
// Root namespace.
namespace Atmosphere;

// Feature namespaces.
namespace Atmosphere\OAuth;
namespace Atmosphere\Transformer;
namespace Atmosphere\Content_Parser;
namespace Atmosphere\Integrations;
namespace Atmosphere\Rest;        // Public REST controllers.
namespace Atmosphere\Rest\Admin;  // Authenticated, editor-only REST controllers.
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
| Public REST controllers (`WP_REST_Controller`) | `includes/rest/` | `Atmosphere\Rest` |
| Authenticated/editor-only REST controllers | `includes/rest/admin/` | `Atmosphere\Rest\Admin` |
| Admin screens | `includes/wp-admin/` | `Atmosphere\WP_Admin` |
| Third-party plugin integrations | `integrations/` | `Atmosphere\Integrations` |

### Creating a New Subdirectory

Add one when you have:

- Multiple related classes (3+) that form a cohesive subsystem.
- A clear domain boundary (e.g. all OAuth-flow concerns live in `oauth/`).
- A reason to keep the concerns from leaking into surrounding files.

After adding or renaming a class file under the `Atmosphere` namespace, no Composer autoload step is needed. The runtime autoloader in `includes/class-autoloader.php` maps namespace segments to WordPress-style filenames such as `class-parser-base.php` and `interface-content-parser.php`.

## Architectural Patterns

### REST Controllers

Every REST endpoint is a `WP_REST_Controller` subclass under `includes/rest/`
(public) or `includes/rest/admin/` (authenticated / editor-only), mirroring
the wordpress-activitypub plugin's layout. Each controller declares its route
via `register_routes()`; they are all instantiated together in
`Atmosphere::register_rest_controllers()` on `rest_api_init`.

Route namespaces are versioned deliberately:

- **`atmosphere/v1`** — the public OAuth `client-metadata` endpoint. Its URL is
  the OAuth `client_id`, an external contract, so the version string is frozen
  and must not change.
- **`atmosphere/1.0`** — admin/editor routes (e.g. the pre-publish preview).

New admin routes should use `atmosphere/1.0`, set `show_in_index => false`, and
gate access with a `permission_callback`.

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

See [`integrations/README.md`](../integrations/README.md) for the full registry pattern and the remaining `atmosphere_document_content` filter.
