# Integrations

Plugin-specific integrations that teach ATmosphere how to format the `content` field of `site.standard.document` records for content produced by third-party plugins.

## How it works

`site.standard.document` records have an [open content union](../docs/content-formats.md) — any object with a valid `$type` is accepted. ATmosphere doesn't ship a default content parser; integrations register one by hooking the `atmosphere_content_parser` filter and returning an implementation of the `Content_Parser` interface (`includes/content-parser/interface-content-parser.php`).

When the parser returns a content object, it is added to the document record under `content`. If no integration is loaded — or every integration returns `null` — the document is published without a `content` field, which is valid.

## Adding an integration

1. Create `class-{plugin-name}.php` in this directory.
2. Register it from `class-load.php` behind a check that the target plugin is active.
3. Hook `atmosphere_content_parser` to return a `Content_Parser` instance for posts your integration can handle.

### Content_Parser interface

```php
namespace Atmosphere\Content_Parser;

interface Content_Parser {
    /**
     * Parse a post's content into an AT Protocol content object.
     *
     * The returned array must include a '$type' key identifying the
     * lexicon NSID (e.g. 'org.wordpress.html', 'at.markpub.markdown').
     */
    public function parse( string $content, \WP_Post $post ): array;

    /**
     * The lexicon NSID this parser produces.
     */
    public function get_type(): string;
}
```

### Example: `org.wordpress.html`

**`class-wordpress-html.php`**

```php
<?php
namespace Atmosphere\Integrations;

use Atmosphere\Content_Parser\Content_Parser;

\defined( 'ABSPATH' ) || exit;

class WordPress_HTML implements Content_Parser {

    public static function init(): void {
        \add_filter(
            'atmosphere_content_parser',
            static function ( $parser, \WP_Post $post ): ?Content_Parser {
                return $parser ?? new self();
            },
            10,
            2
        );
    }

    public function parse( string $content, \WP_Post $post ): array {
        return array(
            '$type' => $this->get_type(),
            'html'  => (string) \apply_filters( 'the_content', $content ),
        );
    }

    public function get_type(): string {
        return 'org.wordpress.html';
    }
}
```

**In `class-load.php`**

```php
public static function register(): void {
    WordPress_HTML::init();
}
```

## Available filters

| Filter | Arguments | Description |
|---|---|---|
| `atmosphere_content_parser` | `Content_Parser\|null $parser`, `WP_Post $post` | Return a `Content_Parser` instance to provide one, or `null` to skip the content field. |
| `atmosphere_document_content` | `array $content`, `WP_Post $post`, `Content_Parser $parser` | Last-chance modification of the parsed content object before it is added to the document record. |

## Conventions

- One class per plugin, methods static unless the parser holds state.
- File naming: `class-{plugin-name}.php`.
- Namespace: `Atmosphere\Integrations` for the loader class; parsers can implement `Atmosphere\Content_Parser\Content_Parser`.
- Always guard with a plugin check (`\defined()`, `\class_exists()`, etc.) in `class-load.php`.
- Return the existing `$parser` unchanged from `atmosphere_content_parser` when the post isn't yours, so multiple integrations can coexist.

## Further reading

- [`docs/content-formats.md`](../docs/content-formats.md) — survey of known AT Protocol content formats (markpub, leaflet, pckt, org.wordpress.html).
- [`docs/org.wordpress.html.md`](../docs/org.wordpress.html.md) — Lexicon for the `org.wordpress.html` content type.
