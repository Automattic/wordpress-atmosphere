# ATmosphere Plugin Developer Documentation

## Table of Contents
- [Introduction](#introduction)
- [Where to Start](#where-to-start)
- [Public Hooks](#public-hooks)
- [Previewing AT Protocol Records](#previewing-at-protocol-records)
- [Extending Content Formats](#extending-content-formats)
- [Custom Post Type Support](#custom-post-type-support)
- [Publishing Programmatically](#publishing-programmatically)
- [Outgoing Reaction Controls](#outgoing-reaction-controls)
- [Token Encryption](#token-encryption)
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
| `atmosphere_document_links` | filter | Add a typed `links` union to `site.standard.document` records. |
| `atmosphere_document_labels` | filter | Add standard self-labels to `site.standard.document` records. |
| `atmosphere_document_contributors` | filter | Add contributor metadata to `site.standard.document` records. |
| `atmosphere_publication_labels` | filter | Add standard self-labels to `site.standard.publication` records. |
| `atmosphere_publication_show_in_discover` | filter | Override `preferences.showInDiscover` (defaults to the site's `blog_public` option) for `site.standard.publication` records. |
| `atmosphere_syncable_post_types` | filter | Add or remove post types eligible for cross-posting. |
| `atmosphere_should_publish_comment` | filter | Customise which approved comments are mirrored as Bluesky replies. |
| `atmosphere_should_sync_reply` | filter | Customise which inbound Bluesky replies become WordPress comments. |
| `atmosphere_transform_bsky_post` | filter | Mutate the Bluesky post record before write. |
| `atmosphere_transform_document` | filter | Mutate the document record before write. |
| `atmosphere_transform_publication` | filter | Mutate the publication record before write. |
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

### Extending Standard.site metadata

ATmosphere emits the core `site.standard.publication` and `site.standard.document` fields from WordPress data. Optional Standard.site fields that do not have a native WordPress source are extension points.

Document metadata filters:

```php
add_filter(
	'atmosphere_document_links',
	static fn( $links, \WP_Post $post ) => array(
		'$type' => 'example.document.links',
		'items' => array(
			array( 'uri' => 'https://example.com/source' ),
		),
	),
	10,
	2
);

add_filter(
	'atmosphere_document_labels',
	static fn() => array(
		'$type'  => 'com.atproto.label.defs#selfLabels',
		'values' => array(
			array( 'val' => 'adult' ),
		),
	)
);

add_filter(
	'atmosphere_document_contributors',
	static fn( $contributors, \WP_Post $post ) => array(
		array(
			'did'         => 'did:plc:editor123',
			'role'        => 'editor',
			'displayName' => 'Jane Editor',
		),
	),
	10,
	2
);
```

Publication metadata filters:

```php
add_filter(
	'atmosphere_publication_labels',
	static fn() => array(
		'$type'  => 'com.atproto.label.defs#selfLabels',
		'values' => array(
			array( 'val' => 'adult' ),
		),
	)
);

// `showInDiscover` defaults to the site's `blog_public` option; force it
// off (or return null to omit the preference entirely) regardless.
add_filter( 'atmosphere_publication_show_in_discover', '__return_false' );
```

The field-specific filters run before `atmosphere_transform_document` and `atmosphere_transform_publication`, so a final record-level filter can still inspect or override the complete record.

ATmosphere models one root publication per WordPress site. It verifies that publication at `/.well-known/site.standard.publication` and does not currently implement Standard.site's non-root publication verification path (`/.well-known/site.standard.publication/path/to/publication`). Social Standard.site lexicons such as `site.standard.graph.subscription` and `site.standard.graph.recommend` are also out of scope for the plugin's publishing flow; ATmosphere requests explicit `repo:` scopes only for `app.bsky.feed.post`, `site.standard.document`, and `site.standard.publication`, and intentionally keeps the documented `include:site.standard.authFull` permission set for Standard.site compatibility even though it does not publish or manage social records itself.

## Previewing AT Protocol Records

Append `?atproto` to a URL while logged in to see the JSON records ATmosphere would publish, without writing anything. Post previews require the `edit_post` capability for that specific post (the same gate as the block-editor panel); the front-page publication preview requires `edit_posts`:

| URL | Returns |
|-----|---------|
| `?atproto` on a post | The `site.standard.document` record (default). |
| `?atproto=app.bsky.feed.post` on a post | The Bluesky record(s) — a single post or a thread. |
| `?atproto` / `?atproto=site.standard.publication` on the front page | The site-level `site.standard.publication` record. |
| `?atproto=all` | Every record family for that view, keyed by its lexicon `$type`. |
| `?atproto={unknown}` | A `400` JSON error listing the supported selectors. |

Each selector is the lexicon NSID of a transformer ([`Atmosphere\Transformer\Base`](../includes/transformer/class-base.php)). The preview reuses the same transformers as the publish path, so what you see is what would be written. That includes the document strongRef in a long-form Bluesky record's `associatedRefs` — its CID is computed from the previewed document record, exactly like the publish path computes it. One caveat: on a post that has never been published to the PDS the ref is omitted, because its rkey is only reserved when the post is first published; it appears once the post has been published.

### Adding your own lexicon to the preview

The `atmosphere_atproto_preview_transformers` filter receives the transformers offered for the current view and the queried post (`null` on the front page). Append any `Base` subclass; it becomes available under `?atproto={its-collection-nsid}` and in `?atproto=all` automatically — its `get_collection()` NSID is the selector, and `get_preview_records()` (which defaults to a single `transform()`, overridden when a post fans out into multiple records) supplies the JSON. `get_preview_records()` must be read-only — it runs on a GET request, so it must not upload blobs, write meta, or reserve rkeys.

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

## Publishing Programmatically

Publishing UIs, syndication managers, and other companion plugins can drive
cross-posting per post instead of relying on the automatic save-flow.

### Per-post controls

Two registered post metas (both `show_in_rest`, writable with `edit_post`)
control what a cross-post looks like before it happens:

| Meta key | Constant | Effect |
|----------|----------|--------|
| `atmosphere_disabled` | `ATMOSPHERE_META_DISABLED` | Sharing is opt-out: `'1'` excludes the post from cross-posting. |
| `atmosphere_custom_text` | `ATMOSPHERE_META_CUSTOM_TEXT` | Replaces the derived Bluesky post text for this post. |

While the automatic save-flow is active, changing either meta schedules a
reconcile that publishes, updates, or removes the remote records to match.
Integrations that disable `atmosphere_auto_publish` (below) must run that
reconcile themselves by calling `\Atmosphere\Publisher::update_post()`
after changing the metas — setting `atmosphere_disabled` alone does not
remove already-published records.

### Taking over the publish flow

The automatic save-flow is gated by the `atmosphere_auto_publish` option
(`'1'` by default). An integration that wants to decide *when* a post is
cross-posted can disable it and call the publisher directly:

```php
\update_option( 'atmosphere_auto_publish', '0' );

$result = \Atmosphere\Publisher::publish_post( $post );

if ( \is_wp_error( $result ) ) {
    // Post was ineligible or the write failed.
}
```

`publish_post()` enforces `\Atmosphere\is_post_publishable()` — the post
must be published, not password-protected, of a [supported post
type](#custom-post-type-support), and not opted out via
`atmosphere_disabled`. It fires
[`atmosphere_publish_post_result`](#public-hooks) once with the final
outcome, and returns the `applyWrites` response(s) or a `WP_Error`.
`\Atmosphere\Publisher::update_post()` and
`\Atmosphere\Publisher::delete_post()` complete the lifecycle;
`update_post()` doubles as the reconcile — when the post is no longer
publishable (trashed, unpublished, or opted out via `atmosphere_disabled`)
it removes the remote records.

### Reading back the published record

After a successful publish the post carries the created record's
references:

| Meta key | Constant | Value |
|----------|----------|-------|
| `_atmosphere_bsky_uri` | `\Atmosphere\Transformer\Post::META_URI` | The `at://` URI of the Bluesky post. |
| `_atmosphere_bsky_tid` | `\Atmosphere\Transformer\Post::META_TID` | The record key (TID). |

```php
$at_uri = \get_post_meta( $post_id, \Atmosphere\Transformer\Post::META_URI, true );
```

Replies, likes, and reposts synced back from Bluesky arrive as native
WordPress comments (comment types `comment`, `like`, and `repost`, all
with `protocol` comment meta `atproto`). Replies carry a link to the
reply's Bluesky page in `source_url`; likes and reposts have no Bluesky
landing page, so their `source_url` is intentionally empty and
`comment_author_url` (the author's profile) is the outbound link.
Integrations can react to each via
[`atmosphere_reaction_synced`](#public-hooks).

## Outgoing Reaction Controls

Outbound reactions are WordPress comments that ATmosphere publishes as
Bluesky replies. Administrators can turn these writes off under
**Settings → ATmosphere → Reactions**. The underlying
`atmosphere_publish_reactions` option defaults to enabled so existing sites
keep their current behavior.

The naming is deliberately broader than today's behavior: comment replies
are currently the only outgoing reaction type, so the settings-screen label
says "Outgoing replies" — but the option, the constant, and
`outgoing_reactions_enabled()` are the switch for *all* outgoing reaction
writes. If ATmosphere ever publishes other reaction types (likes, reposts),
they will honor the same option and constant rather than growing parallel
switches.

Managed environments can enforce the boundary in `wp-config.php`:

```php
define( 'ATMOSPHERE_DISABLE_OUTGOING_REACTIONS', true );
```

The constant takes precedence over the saved option. While outgoing reactions
are disabled, ATmosphere does not create, update, or delete Bluesky reply
records for WordPress comments, including work that was already queued in
WP-Cron. Replies that were previously published remain unchanged. Post and
standard.site document publishing continues normally, as does inbound syncing
of Bluesky replies, likes, and reposts.

Direct calls to the `Publisher` comment methods return a WP_Error with the
`atmosphere_outgoing_reactions_disabled` code while the control is off. Use
`\Atmosphere\outgoing_reactions_enabled()` when an integration needs to inspect
the effective state.

## Token Encryption

OAuth tokens are encrypted at rest with a key derived from the site's `AUTH_KEY` and `AUTH_SALT`. That keeps the key out of the database, but it also means the stored tokens become unreadable when the salts change — after a migration, a regenerated `wp-config.php`, or a security plugin that rotates salts on a schedule. ATmosphere detects that case, flags the connection, and asks the user to reconnect.

Sites that rotate their salts deliberately can pin a dedicated key instead, which takes precedence over the salts:

```php
define( 'ATMOSPHERE_ENCRYPTION_KEY', 'a long random secret that never changes' );
```

Define it in `wp-config.php` **before** connecting (or reconnect afterwards — changing key material always orphans previously stored tokens). Treat it like a salt: long, random, and never committed to version control.

## Templates and Admin UI

ATmosphere's admin screens render from `templates/`. The settings page is rendered from a single template; the editor sidebar panel is a React surface registered through `class-admin.php`. There is currently no public template-override mechanism — file an issue if you have a use case that requires one.

## Reporting Issues

Bugs and feature requests: [GitHub Issues](https://github.com/Automattic/wordpress-atmosphere/issues).

Security issues: see the project's security disclosure policy in [`README.md`](../README.md#security).
