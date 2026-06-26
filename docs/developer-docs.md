# ATmosphere Plugin Developer Documentation

## Table of Contents
- [Introduction](#introduction)
- [Where to Start](#where-to-start)
- [Public Hooks](#public-hooks)
- [Previewing AT Protocol Records](#previewing-at-protocol-records)
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
| `atmosphere_content_parser` | filter | Deprecated parser hook; use `Content_Parser\Registry::register()` instead. |
| `atmosphere_document_content` | filter | Last-chance modification of the parsed content object. |
| `atmosphere_syncable_post_types` | filter | Add or remove post types eligible for cross-posting. |
| `atmosphere_should_publish_comment` | filter | Customise which approved comments are mirrored as Bluesky replies. |
| `atmosphere_should_sync_reply` | filter | Customise which inbound Bluesky replies become WordPress comments. |
| `atmosphere_transform_bsky_post` | filter | Mutate the Bluesky post record before write. |
| `atmosphere_transform_document` | filter | Mutate the document record before write. |
| `atmosphere_atproto_preview_transformers` | filter | Add a transformer to the `?atproto={$type}` preview for posts and the front page. |
| `atmosphere_appview_host` | filter | Point Bluesky web links at an alternative AT Protocol appview (host or subpath). |
| `atmosphere_appview_url` | filter | Rewrite the whole assembled appview link, including its route. |
| `atmosphere_publish_post_result` | action | React to a post-publish outcome (success or `WP_Error`). |
| `atmosphere_publish_comment_result` | action | React to a comment-publish outcome. |
| `atmosphere_reaction_synced` | action | React when a Bluesky reaction is stored as a WordPress comment. |

When adding a new public hook, mark its `@since` tag as `unreleased` — the release script rewrites it (see [Release Process → Marking Unreleased Code](release-process.md#marking-unreleased-code)).

### Pointing Bluesky links at another appview

Rendered links to Bluesky (profiles, hashtags, mentions, posts) default to the `bsky.app` web appview. Two filters let you redirect them, depending on how much you need to change.

Both filters pass up to three arguments. As with any WordPress filter, register with `$accepted_args = 3` if your callback needs `$path` and `$context`:

- `$path` — the path being built, e.g. `profile/<did>` or `hashtag/<tag>`.
- `$context` — array with the available parts: `type` (one of `profile`, `post`, `mention`, `hashtag`), `did`, `handle`, `rkey`, `tag`.

#### `atmosphere_appview_host` — swap the host (or subpath)

Use this when the alternative appview mirrors bsky.app's routes (`/profile/...`, `/hashtag/...`) and you only need to change where they live. The first argument is the default host, `'bsky.app'`.

The returned value can be a bare host, a host on a subdomain, or a host with a path prefix, with or without a scheme or trailing slash — it's normalized before use, so an appview hosted on a subpath works cleanly:

```php
// Bare host.
add_filter( 'atmosphere_appview_host', fn() => 'deer.social' );

// Appview living on a subpath: yields https://something.social/atblue/profile/<did>.
add_filter( 'atmosphere_appview_host', fn() => 'something.social/atblue' );

// Route by context: send profiles elsewhere, keep hashtags on bsky.app.
add_filter(
	'atmosphere_appview_host',
	function ( $host, $path, $context ) {
		return 'hashtag' === ( $context['type'] ?? '' ) ? $host : 'deer.social';
	},
	10,
	3
);
```

#### `atmosphere_appview_url` — rewrite the whole link

Use this when the appview's routes differ from bsky.app's — for example `/account/<did>` instead of `/profile/<did>`, or a custom hashtag route. The first argument is the fully assembled URL (after the host filter has run); rebuild it from `$context` and return a complete URL:

```php
// Custom profile route: /account/<did> instead of /profile/<did>.
add_filter(
	'atmosphere_appview_url',
	function ( $url, $path, $context ) {
		if ( 'mention' === ( $context['type'] ?? '' ) || 'profile' === ( $context['type'] ?? '' ) ) {
			return 'https://my.appview/account/' . ( $context['did'] ?? $context['handle'] ?? '' );
		}
		return $url;
	},
	10,
	3
);
```

Of note: links rendered on the fly (facet mentions, hashtags, and the "View on Bluesky" link) pick up the filters on every render, so changing them updates immediately. The author and source links stored on synced reaction comments are resolved once at sync time, so they keep whichever host was in effect when the comment was synced.

## Previewing AT Protocol Records

Append `?atproto` to a URL while logged in as a user with the `edit_posts` capability to see the JSON records ATmosphere would publish, without writing anything:

| URL | Returns |
|-----|---------|
| `?atproto` on a post | The `site.standard.document` record (default). |
| `?atproto=app.bsky.feed.post` on a post | The Bluesky record(s) — a single post or a thread. |
| `?atproto` / `?atproto=site.standard.publication` on the front page | The site-level `site.standard.publication` record. |
| `?atproto=all` | Every record family for that view, keyed by its lexicon `$type`. |
| `?atproto={unknown}` | A `400` JSON error listing the supported selectors. |

Each selector is the lexicon NSID of a transformer ([`Atmosphere\Transformer\Base`](../includes/transformer/class-base.php)). The preview reuses the same transformers as the publish path, so what you see is what would be written.

### Adding your own lexicon to the preview

The `atmosphere_atproto_preview_transformers` filter receives the transformers offered for the current view and the queried post (`null` on the front page). Append any `Base` subclass; it becomes available under `?atproto={its-collection-nsid}` and in `?atproto=all` automatically — its `get_collection()` NSID is the selector, and `get_preview_records()` (which defaults to a single `transform()`, overridden when a post fans out into multiple records) supplies the JSON.

```php
add_filter(
	'atmosphere_atproto_preview_transformers',
	static function ( array $transformers, ?\WP_Post $post ): array {
		// Only offer this preview on singular posts.
		if ( $post instanceof \WP_Post ) {
			$transformers[] = new My_Plugin\Example_Transformer( $post );
		}

		return $transformers;
	},
	10,
	2
);
```

```php
class Example_Transformer extends \Atmosphere\Transformer\Base {

	public function transform(): array {
		return array(
			'$type'  => 'com.example.document',
			'postId' => $this->object->ID,
		);
	}

	public function get_collection(): string {
		return 'com.example.document'; // The ?atproto selector.
	}

	public function get_rkey(): string {
		return (string) $this->object->ID;
	}
}
```

Entries that are not `Base` instances are ignored, and a filter that returns a non-array falls back to the built-in transformers — so a malformed filter return cannot break the endpoint. A transformer whose `get_collection()` matches a built-in NSID supersedes that built-in for the request, mirroring how `Content_Parser\Registry::register()` lets a registration override a default.

## Extending Content Formats

The `site.standard.document` record's `content` field is a singular open union of typed content objects (see [`docs/content-formats.md`](content-formats.md)). ATmosphere ships built-in parsers for HTML, Markpub, Leaflet, and pckt formats, and integrations can register additional parsers.

To provide a parser:

1. Implement `Atmosphere\Content_Parser\Content_Parser` (defined in `includes/content-parser/interface-content-parser.php`), or extend `Atmosphere\Content_Parser\Parser_Base` for WordPress/block helpers.
2. Register the parser with `Atmosphere\Content_Parser\Registry::register( $parser, $priority )`.
3. Optionally expose `applies_to( \WP_Post $post ): bool` so the registry can skip posts the parser cannot represent.

The deprecated `atmosphere_content_parser` filter remains for existing integrations. A returned parser still wins over the registry, and `null` still suppresses the `content` field, but using the filter emits a deprecation notice.

The **Content format** setting is a preference, not an absolute guarantee for every post. When the selected parser does not apply or cannot safely represent a post, the registry falls back to the next applicable parser, normally rendered HTML.

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
