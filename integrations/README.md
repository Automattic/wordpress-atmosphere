# Integrations

Plugin-specific integrations that teach ATmosphere how to handle custom block types and content from third-party plugins.

## How it works

When ATmosphere converts a WordPress post to an AT Protocol record, the Markpub content parser walks through each Gutenberg block and converts it to markdown. Core blocks (paragraph, heading, image, list, etc.) are handled by default. For custom blocks from other plugins, integrations hook into the `atmosphere_markpub_block` filter to provide their own conversion.

## Adding an integration

1. Create `class-{plugin-name}.php` in this directory.
2. Register the integration in `class-load.php` with a check for the target plugin.
3. Hook into `atmosphere_markpub_block` to handle the plugin's custom blocks.

### Example: Jetpack

**`class-jetpack.php`**

```php
<?php
namespace Atmosphere\Integrations;

\defined( 'ABSPATH' ) || exit;

class Jetpack {

    public static function init(): void {
        \add_filter( 'atmosphere_markpub_block', array( self::class, 'transform_block' ), 10, 2 );
    }

    public static function transform_block( ?string $markdown, array $block ): ?string {
        return match ( $block['blockName'] ) {
            'jetpack/slideshow' => self::slideshow( $block ),
            'jetpack/tiled-gallery' => self::gallery( $block ),
            default => $markdown,
        };
    }

    private static function slideshow( array $block ): ?string {
        // Convert slideshow block to markdown image list.
    }

    private static function gallery( array $block ): ?string {
        // Convert gallery block to markdown image list.
    }
}
```

**In `class-load.php`**

```php
public static function register(): void {
    if ( \defined( 'JETPACK__VERSION' ) ) {
        Jetpack::init();
    }
}
```

## Available filters

| Filter | Arguments | Description |
|---|---|---|
| `atmosphere_markpub_block` | `?string $markdown`, `array $block` | Handle a custom block type. Return markdown string or `null` to pass through to default handling. |
| `atmosphere_content_parser` | `Content_Parser\|null $parser`, `WP_Post $post` | Replace the entire content parser or return `null` to disable. |
| `atmosphere_document_content` | `array $content`, `WP_Post $post`, `Content_Parser $parser` | Modify the parsed content object before it is added to the document record. |
| `atmosphere_html_to_markdown` | `string $markdown`, `string $content` | Override the final markdown output from the Markpub parser. |

## Conventions

- One class per plugin, all methods static.
- File naming: `class-{plugin-name}.php`.
- Namespace: `Atmosphere\Integrations`.
- Always guard with a plugin check (`\defined()`, `\class_exists()`, etc.) in `class-load.php`.
- Return `$markdown` (the first argument) unchanged from the filter when the block is not yours.
