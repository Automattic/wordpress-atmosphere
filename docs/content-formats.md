# AT Protocol Content Formats

The `site.standard.document` record defines a `content` field as an **open union** — any object with a valid `$type` is accepted. This allows different content formats to coexist across the AT Protocol ecosystem.

This document surveys the known content format types and their design approaches.

## Overview

| Content type | Approach | Namespace owner |
|---|---|---|
| `at.markpub.markdown` | Markdown string | [markpub.at](https://markpub.at/) |
| `pub.leaflet.content` | Structured block model | [leaflet.pub](https://leaflet.pub/) |
| `blog.pckt.content` | Hybrid block model | [pckt.blog](https://pckt.blog/) |
| `org.wordpress.html` | Rendered HTML | [wordpress.org](https://wordpress.org/) |

## at.markpub.markdown

Carries a raw markdown string alongside metadata about how it should be rendered.

```json
{
  "$type": "at.markpub.markdown",
  "text": {
    "$type": "at.markpub.text",
    "markdown": "## Hello\n\nThis is **bold** text."
  },
  "flavor": "gfm",
  "extensions": ["strikethrough", "table"]
}
```

**Key properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `text` | `at.markpub.text` | yes | Object containing the markdown string. |
| `flavor` | string | no | Rendering flavor — `gfm` or `commonmark`. |
| `renderingRules` | string | no | Renderer system (e.g. marked, pandoc). |
| `extensions` | string[] | no | Expected markdown extensions (e.g. LaTeX, YAML). |
| `frontMatter` | union[] | no | Parsed front matter objects. |

The `at.markpub.text` object holds the `markdown` string and optionally supports `facets` (rendered representations with byte ranges) and `lenses` (translation layers between facet types). A `textBlob` field allows storing markdown as a PDS blob for large documents.

**Design:** Simple and portable. Any client that understands markdown can render it. The `facets` and `lenses` system provides optional pre-rendered formatting hints for clients that don't want to parse markdown themselves.

Source: [markpub.at](https://markpub.at/)

## pub.leaflet.content

A structured block model where content is organized into pages, each containing an ordered list of typed blocks with rich text facets.

```json
{
  "$type": "pub.leaflet.content",
  "items": [
    {
      "$type": "pub.leaflet.pages.linearDocument",
      "blocks": [
        {
          "block": {
            "$type": "pub.leaflet.block.text",
            "text": "Hello world",
            "facets": [
              {
                "$type": "pub.leaflet.richtext.facet",
                "index": { "byteStart": 6, "byteEnd": 11 },
                "features": [{ "$type": "pub.leaflet.richtext.facet#bold" }]
              }
            ]
          }
        }
      ]
    }
  ]
}
```

**Block types include:** text, heading (with level), blockquote, code (with language), image (with aspect ratio), ordered/unordered lists, horizontal rule, website embed, Bluesky post embed, button, math expression, and poll.

**Rich text facets:** bold, italic, strikethrough, link, DID mention, AT-URI mention, code span, highlight, and underline. Each facet references a byte range in the text.

**Page types:** `linearDocument` for sequential layouts and `canvas` for free-form positioned blocks.

**Design:** Closest to a structured editor format like Gutenberg or ProseMirror. Preserves semantic block structure and inline formatting as data. Clients must understand the block schema to render content, but get full control over presentation.

Source: [leaflet.pub](https://leaflet.pub/)

## blog.pckt.content

A hybrid block model that stores content inline when small and falls back to a blob reference for large documents.

```json
{
  "$type": "blog.pckt.content",
  "items": [
    {
      "$type": "blog.pckt.block.text",
      "text": "Hello ",
      "marks": [
        { "$type": "blog.pckt.richtext.facet#bold" }
      ]
    },
    {
      "$type": "blog.pckt.block.text",
      "text": "world"
    }
  ]
}
```

For large content (>20KB), the blocks move to an external blob:

```json
{
  "$type": "blog.pckt.content",
  "blob": { "$type": "blob", "ref": { "$link": "..." }, "mimeType": "application/json", "size": 52000 },
  "references": []
}
```

**Block types include:** text, heading, blockquote, code block, image, gallery, ordered/unordered list, list item, task list, task item, table, table row, table header, table cell, horizontal rule, hard break, iframe, mention, and Bluesky embed.

**Design:** Similar to Leaflet's structured approach, but adds a size-aware storage strategy. The `references` array preserves blob references used in the content so the PDS doesn't garbage-collect them when content is stored externally. Pragmatic for platforms that handle both short and long-form content.

Source: [pckt.blog](https://pckt.blog/)

## org.wordpress.html

Rendered HTML — the full post output ready for display. The simplest content format.

```json
{
  "$type": "org.wordpress.html",
  "html": "<p>Hello <strong>world</strong>.</p>"
}
```

**Key properties:**

| Property | Type | Required | Description |
|---|---|---|---|
| `html` | string | yes | Rendered HTML content. |

**Design:** Analogous to RSS `content:encoded`. No parsing or block schema required — clients render the HTML directly. Ideal for CMS platforms like WordPress that already produce fully rendered HTML via their content pipeline (`the_content` filter). Trades structural semantics for universal compatibility — any client that can render HTML can display the content.

Source: [org.wordpress.html spec](org.wordpress.html.md)

## Comparison

| | Markpub | Leaflet | pckt | WordPress HTML |
|---|---|---|---|---|
| **Format** | Markdown string | Structured blocks | Structured blocks | Raw HTML |
| **Rendering** | Client parses markdown | Client interprets blocks | Client interprets blocks | Client renders HTML |
| **Portability** | High | Medium | Medium | Highest |
| **Semantic structure** | Minimal | Full | Full | None |
| **Large content** | `textBlob` (PDS blob) | Inline only | Blob fallback >20KB | Inline only |
| **Rich text** | Markdown syntax | Facets (byte ranges) | Marks on text blocks | HTML tags |
| **Complexity** | Low | High | High | Lowest |
