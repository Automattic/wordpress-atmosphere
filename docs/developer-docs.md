# ATmosphere Plugin Developer Documentation

## Table of Contents
- [Introduction](#introduction)
- [Where to Start](#where-to-start)
- [Public Hooks](#public-hooks)
- [Extending Content Formats](#extending-content-formats)
- [Custom Post Type Support](#custom-post-type-support)
- [Templates and Admin UI](#templates-and-admin-ui)

## Introduction

This documentation is for developers who want to extend, integrate with, or build on the ATmosphere plugin — whether you're writing a companion plugin, adding a content parser for the `site.standard.document` content union, or hooking into the publish / reaction pipeline.

If you're contributing to ATmosphere itself, start with [`AGENTS.md`](../AGENTS.md) for repository conventions.

## Where to Start

- [Development Environment Setup](development-environment.md) — wp-env, prerequisites, troubleshooting.
- [PHP Coding Standards](php-coding-standards.md) — naming, escaping, error handling, performance.
- [Class Structure](php-class-structure.md) — directory layout and architectural patterns.
- [Code Linting](code-linting.md) — PHPCS rules and common fixes.
- [Pull Request Guide](pull-request.md) — branch naming, checklists, commit format.
- [Release Process](release-process.md) — `npm run release`, patch releases, GitHub Release UI.
- [Translations](translations.md) — text domain, GlotPress, translator-friendly strings.
- [Content Formats](content-formats.md) — the AT Protocol content types ATmosphere can produce.
- [`org.wordpress.html` Lexicon](org.wordpress.html.md) — the rendered-HTML content type schema.
- [Integrations Guide](../integrations/README.md) — how third-party plugins register content parsers.

## Public Hooks

ATmosphere exposes a small set of filters and actions for plugins to extend behaviour. The full catalog with signatures lives in [`docs/php-coding-standards.md → Hook Patterns`](php-coding-standards.md#hook-patterns). The most commonly used:

| Hook | Type | Use |
|------|------|-----|
| `atmosphere_content_parser` | filter | Return a `Content_Parser` instance to populate `site.standard.document.content`. |
| `atmosphere_document_content` | filter | Last-chance modification of the parsed content object. |
| `atmosphere_syncable_post_types` | filter | Add or remove post types eligible for cross-posting. |
| `atmosphere_should_publish_comment` | filter | Customise which approved comments are mirrored as Bluesky replies. |
| `atmosphere_should_sync_reply` | filter | Customise which inbound Bluesky replies become WordPress comments. |
| `atmosphere_transform_bsky_post` | filter | Mutate the Bluesky post record before write. |
| `atmosphere_transform_document` | filter | Mutate the document record before write. |
| `atmosphere_publish_post_result` | action | React to a post-publish outcome (success or `WP_Error`). |
| `atmosphere_publish_comment_result` | action | React to a comment-publish outcome. |
| `atmosphere_reaction_synced` | action | React when a Bluesky reaction is stored as a WordPress comment. |

When adding a new public hook, mark its `@since` tag as `unreleased` — the release script rewrites it (see [Release Process → Marking Unreleased Code](release-process.md#marking-unreleased-code)).

## Extending Content Formats

The `site.standard.document` record's `content` field is an open union of typed content objects (see [`docs/content-formats.md`](content-formats.md)). ATmosphere ships only the `Content_Parser` interface — concrete parsers come from integrations.

To provide a parser:

1. Implement `Atmosphere\Content_Parser\Content_Parser` (defined in `includes/content-parser/interface-content-parser.php`).
2. Register through the `atmosphere_content_parser` filter, returning your instance.
3. Return `null` from the filter when you don't want to handle a given post — other integrations can then take over.

A complete worked example (with `class-load.php` registration) is in [`integrations/README.md`](../integrations/README.md).

## Custom Post Type Support

ATmosphere only cross-posts post types that opt in. Two ways to add one:

### Per-site option

```php
\update_option( 'atmosphere_support_post_types', array( 'post', 'product' ) );
```

### Native theme/plugin support

```php
\add_post_type_support( 'product', 'atmosphere' );
```

### Filter override

```php
\add_filter(
    'atmosphere_syncable_post_types',
    static function ( array $types ): array {
        $types[] = 'event';
        return $types;
    }
);
```

The plugin merges all three sources, dedupes, and sanitises.

## Templates and Admin UI

ATmosphere's admin screens render from `templates/`. The settings page is rendered from a single template; the editor sidebar panel is a React surface registered through `class-admin.php`. There is currently no public template-override mechanism — file an issue if you have a use case that requires one.

## Reporting Issues

Bugs and feature requests: [GitHub Issues](https://github.com/Automattic/wordpress-atmosphere/issues).

Security issues: see the project's security disclosure policy in [`README.md`](../README.md#security).
