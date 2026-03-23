# org.wordpress.html

HTML content type for the AT Protocol. Carries rendered HTML content for use in open union fields like `site.standard.document#content`.

Analogous to RSS `content:encoded` — the full HTML representation of a post, ready for display.

## Lexicon

```json
{
  "lexicon": 1,
  "id": "org.wordpress.html",
  "defs": {
    "main": {
      "type": "object",
      "required": ["html"],
      "properties": {
        "html": {
          "type": "string",
          "maxGraphemes": 100000,
          "description": "Rendered HTML content."
        }
      }
    }
  }
}
```

## Properties

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `html` | string | yes | Rendered HTML content. |

## Example Record

```json
{
  "$type": "org.wordpress.html",
  "html": "<p>Hello <strong>world</strong>.</p>\n<figure><img src=\"https://example.com/photo.jpg\" alt=\"A photo\" /></figure>"
}
```

## Context: site.standard.document

The `site.standard.document` record defines a `content` field as an **open union** — any object with a valid `$type` is accepted. This allows different content formats to coexist:

| Content type | Approach | Source |
|---|---|---|
| `at.markpub.markdown` | Raw markdown string with flavor/extensions metadata | [markpub.at](https://markpub.at/) |
| `pub.leaflet.content` | Structured block model — pages containing typed blocks with rich text facets | [leaflet.pub](https://leaflet.pub/) |
| `blog.pckt.content` | Hybrid block model — inline blocks when small, blob reference for large content | [pckt.blog](https://pckt.blog/) |
| `org.wordpress.html` | Rendered HTML — the full post output, analogous to RSS `content:encoded` | This spec |

A document record may include both `textContent` (plain text for search/indexing) and `content` (rich format for display):

```json
{
  "$type": "site.standard.document",
  "title": "My Post",
  "publishedAt": "2026-03-23T12:00:00Z",
  "site": "at://did:plc:example/site.standard.publication/tid",
  "textContent": "Hello world.",
  "content": {
    "$type": "org.wordpress.html",
    "html": "<p>Hello <strong>world</strong>.</p>"
  }
}
```

## Generating HTML

The HTML should be the rendered post content, equivalent to what the `the_content` filter produces in WordPress. This includes fully resolved media URLs, applied shortcodes, and block rendering.

## Namespace

The `org.wordpress` namespace is rooted in the `wordpress.org` domain, maintained by the WordPress Foundation. NSID authority follows the [AT Protocol Lexicon specification](https://atproto.com/specs/lexicon), which roots namespace ownership in DNS domain control.
