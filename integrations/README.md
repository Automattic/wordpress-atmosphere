# Integrations

Plugin-specific integrations that teach ATmosphere how to format the `content` field of `site.standard.document` records for content produced by third-party plugins.

## How it works

`site.standard.document` records have an [open content union](../docs/content-formats.md) — any object with a valid `$type` is accepted, but the field is **singular**: exactly one parser produces the `content` object per document.

ATmosphere ships several built-in parsers (`org.wordpress.html`, `at.markpub.markdown`, `pub.leaflet.content`, `blog.pckt.content`) and registers them on a central **registry**. Integrations add their own by calling `Registry::register()`. For each post, the registry selects one parser: the format chosen in the **Content format** setting if it applies, otherwise the lowest-priority-number applicable parser. Parsers can expose `applies_to( \WP_Post $post ): bool` to opt out for a post; parsers without that method are treated as applicable. If a selected parser does not apply, ATmosphere falls back to the next applicable parser, normally rendered HTML. The selected parser's output is added to the record under `content`; if nothing applies, the document is published without a `content` field, which is valid.

## Adding an integration

1. Create `class-{plugin-name}.php` in this directory.
2. Register it from `class-load.php` behind a check that the target plugin is active.
3. Register a `Content_Parser` on the registry. Extend `Parser_Base` to inherit the block-tree, rendered-HTML, image-blob, and grapheme helpers.

### Content_Parser interface

```php
namespace Atmosphere\Content_Parser;

interface Content_Parser {
    /** The lexicon NSID this parser produces (e.g. 'org.wordpress.html'). */
    public function get_type(): string;

    /**
     * Parse a post's content into an AT Protocol content object, or null
     * to omit the content field. The returned array must include a
     * '$type' key identifying the lexicon NSID.
     */
    public function parse( string $content, \WP_Post $post ): ?array;
}
```

`applies_to( \WP_Post $post ): bool` is optional. `Parser_Base` provides it with a default `true` result; override it when a format only works for certain posts, such as block-editor-only formats. If your parser reads saved block markup directly, use `saved_content_survives_rendering( $post )` in the guard so render-time visibility filters can force a fallback to rendered HTML.

### Example

**`class-acme-format.php`**

```php
<?php
namespace Atmosphere\Integrations;

use Atmosphere\Content_Parser\Parser_Base;

\defined( 'ABSPATH' ) || exit;

class Acme_Format extends Parser_Base {

    public function get_type(): string {
        return 'com.acme.content';
    }

    public function applies_to( \WP_Post $post ): bool {
        return $this->has_blocks( $post );
    }

    public function parse( string $content, \WP_Post $post ): ?array {
        return array(
            '$type' => $this->get_type(),
            'html'  => $this->get_rendered_html( $post ),
        );
    }
}
```

**In `class-load.php`**

```php
public static function register(): void {
    \Atmosphere\Content_Parser\Registry::register( new Acme_Format(), 30 );
}
```

`register()` takes an optional priority (default `20`; lower wins). The built-in `org.wordpress.html` parser registers at `10` so it stays the automatic default; register above `10` to defer to it, or below to take precedence.

## Available filters

| Filter | Arguments | Description |
|---|---|---|
| `atmosphere_document_content` | `array $content`, `WP_Post $post`, `Content_Parser $parser` | Last-chance modification of the parsed content object before it is added to the document record. |
| `atmosphere_content_parser` *(deprecated)* | `Content_Parser\|null $parser`, `WP_Post $post` | **Deprecated.** Returning a `Content_Parser` still works (it wins over the registry) and returning `null` still suppresses `content`, but either path emits a deprecation notice. Use `Registry::register()` instead. |

## Conventions

- One class per plugin; extend `Parser_Base` so the WordPress-coupling lives in one place.
- File naming: `class-{plugin-name}.php`.
- Namespace: `Atmosphere\Integrations` for the loader class; parsers live in (or implement) `Atmosphere\Content_Parser`.
- Always guard with a plugin check (`\defined()`, `\class_exists()`, etc.) in `class-load.php`.

## Further reading

- [`docs/content-formats.md`](../docs/content-formats.md) — survey of known AT Protocol content formats (markpub, leaflet, pckt, org.wordpress.html).
- [`docs/org.wordpress.html.md`](../docs/org.wordpress.html.md) — Lexicon for the `org.wordpress.html` content type.
