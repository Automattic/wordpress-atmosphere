# Federation in ATmosphere

The plugin connects a WordPress site to the AT Protocol network. It writes records into the connected account's repository on its PDS, reads interactions back from the Bluesky appview, and verifies the site's identity in both directions. This file describes that surface, following the [FEDERATION.md convention](https://codeberg.org/fediverse/fep/src/branch/main/fep/67ff/fep-67ff.md); the AT Protocol community has no distinct name for federating, so the name fits here too.

## Supported protocols and standards

- [AT Protocol](https://atproto.com/specs/atp) repository writes, batched atomically via `com.atproto.repo.applyWrites`
- [AT Protocol OAuth](https://atproto.com/specs/oauth) with PKCE, DPoP (including nonce retry), and PAR; the plugin serves its own client metadata document as `client_id`
- [Handle resolution](https://atproto.com/specs/handle): handle → DID → PDS → authorization server, consuming `/.well-known/atproto-did`, `/.well-known/did.json`, PLC directory lookups, `/.well-known/oauth-protected-resource`, and `/.well-known/oauth-authorization-server`
- [standard.site](https://standard.site/) documents and publications, including the published permission set (`site.standard.authFull`)

## Lexicons

**Records written:**

- `app.bsky.feed.post` — one companion post per shared WordPress post, optionally as a teaser thread; embeds via `app.bsky.embed.external`, `app.bsky.embed.images`, and `app.bsky.embed.record`
- `app.bsky.feed.threadgate` — per-post reply restrictions, written in the same atomic batch as the post
- `site.standard.document` — one document record per shared post
- `site.standard.publication` — the site-level publication record, kept in sync automatically; theming via `site.standard.theme.basic`
- Comment replies as `app.bsky.feed.post` records when comment publishing is enabled

**Records read:**

- `app.bsky.feed.like` and `app.bsky.feed.repost` from the connected account's own repository, for self-reactions
- Replies, likes, and reposts from others via `app.bsky.notification.listNotifications` and `app.bsky.feed.getPostThread`, imported as WordPress comments

**Own lexicon:**

- [`org.wordpress.html`](docs/org.wordpress.html.md) — a content type for `site.standard.document` carrying rendered WordPress HTML, implemented by the built-in content parser and open to third-party parsers (see [integrations/README.md](integrations/README.md))

## Identity

- The site serves `/.well-known/atproto-did` so the domain itself can be used as the account's handle, and can switch the PDS-managed handle via `com.atproto.identity.updateHandle`
- Singular posts carry a `site.standard.document` link tag, confirming the bidirectional link between page and record

## OAuth scopes

```
atproto
repo:app.bsky.feed.post
repo:app.bsky.feed.threadgate
repo:site.standard.document
repo:site.standard.publication
blob:image/*
rpc:app.bsky.actor.getProfile?aud=did:web:api.bsky.app#bsky_appview
rpc:app.bsky.notification.listNotifications?aud=did:web:api.bsky.app#bsky_appview
identity:handle
include:site.standard.authFull
```

## Endpoints

**Served by the site:**

- `/.well-known/atproto-did` — domain handle verification
- `/.well-known/site.standard.publication` — the publication's AT-URI
- `/wp-json/atmosphere/v1/client-metadata` — the OAuth client metadata document, doubling as the `client_id`

**Consumed:**

- `com.atproto.repo.applyWrites`, `createRecord`, `putRecord`, `getRecord`, `listRecords`, `uploadBlob`
- `com.atproto.identity.updateHandle`
- `app.bsky.notification.listNotifications`, `app.bsky.feed.getPostThread`, `app.bsky.actor.getProfile`, `app.bsky.actor.searchActorsTypeahead`

## Known limitations

- Interaction sync reads the Bluesky appview (`did:web:api.bsky.app`) only: the RPC scopes are audience-locked to it, so likes and replies that exist solely on another appview are not imported.
- The web links the plugin renders default to `bsky.app` but can be pointed at any appview via the `atmosphere_appview_host` filter.

## Additional documentation

- Developer documentation: [docs/developer-docs.md](docs/developer-docs.md)
- Content formats for `site.standard.document`: [docs/content-formats.md](docs/content-formats.md)
- Changelog: [CHANGELOG.md](CHANGELOG.md)
