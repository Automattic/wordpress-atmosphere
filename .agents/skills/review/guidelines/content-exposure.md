# Content exposure

Which posts may reach the PDS or a public surface, and what happens when a post stops being public.

Applies to: `includes/class-publisher.php`, `includes/class-backfill.php`, `includes/class-post-types.php`, `includes/transformer/**`, `includes/rest/**`, `includes/class-atmosphere.php`, `includes/class-reaction-sync.php`, `templates/**`

## Past issues

- **Write side effects on a read-only GET** (#165 review): `Document::transform()` / `Publication::transform()` uploaded uncached cover-image and site-icon blobs to the PDS, and wrote attachment meta, when a preview rendered them.
- **Request-scoped constant outliving its branch** (#170 review): `REST_REQUEST` was defined before routing, so early-`return` branches left it defined for the rest of the page render and silently changed unrelated plugins' behavior.
- **Generic capability on the preview** (#170 review): see "Object-level capabilities" in `authorization.md`.

From the ActivityPub plugin. These are not confirmed ATmosphere bugs; they are regression classes to check whenever ATmosphere touches publishing, deletion, previews, public endpoints or transformers.

- **Public to password-protected** (ActivityPub, 2026): a federated public post that became password-protected kept exposing its content. The scheduler treated the save as an Update, content helpers read raw `post_content` / `post_excerpt`, and public representations reused lifecycle gates meant for Delete delivery.
- **Non-public status on content negotiation** (ActivityPub, 2026): posts moved to draft, pending, private, trash or a custom non-public status stayed renderable while waiting for Delete delivery.
- **Snapshots keep the leak** (ActivityPub): outbox snapshots froze leaked Update activities. ATmosphere writes to a PDS instead, and the same rule holds: once protected text, excerpts, embeds, thumbnails, tags, `site.standard.document#textContent` or parsed `content` are written, the leak persists remotely until an explicit update or delete removes it. Later WordPress visibility checks do not undo it.

## What may be published

- Only published posts reach the PDS: no drafts, private, password-protected or trashed posts.
- "Published" means all of: `post_status === 'publish'`, a supported post type, and an empty `post_password`. Treat `post_password` as a first-class visibility column; a check that only reads status, post type support, TID meta or sync options is incomplete.
- Use `! empty( $post->post_password )`, never `post_password_required()`, for anything sent to the PDS. The latter is for the current web request and honors the visitor's unlock cookie, so it returns false for an editor who unlocked the post.
- Backfill checks visibility before syncing, and skips password-protected posts even when they have `publish` status and a public post type.
- Visibility is checked at every boundary: the publisher, backfill, reaction-sync write-back and any admin preview.

## When a post stops being public

- Previously published records are deleted, not updated or redacted, when a post leaves public visibility: publish → draft, pending, private or trash; publish → a custom non-public status; a password applied; post type support removed; the `atmosphere_syncable_post_types` allow list narrowed after publication.
- Each of these has a cleanup signal, even when WordPress does not change `post_status` (see `cron.md` for stale events).
- Publish and update cron handlers re-check visibility when they fire. A stale `atmosphere_update_post` event must not push an edit that made the post protected or non-public after it was queued; when local record meta exists, it calls `Publisher::delete_post( $post )` directly instead of queueing another cleanup event.
- Transformer redaction is centralized and covers both record families: `app.bsky.feed.post` text, facets, embeds, thumbnails and tags, and `site.standard.document` title, description, cover, `textContent`, `content` and tags.

## Public surfaces

Files: well-known handlers, REST controllers, anything that exposes post or publication data without authentication.

- Public surfaces do not use lifecycle cleanup gates as exposure gates. A now-private post may pass a cleanup path so Delete reaches the PDS; that path must not render its current fields to an unauthenticated request.
- An outbox-like endpoint, if one is added, filters on `post_status === 'publish'` and excludes password-protected and private posts.
- A public route that returns existing PDS metadata for a post does not expose stale AT-URIs or CIDs for posts that are now private or password-protected, unless an authorized user needs them for deletion.
- `/.well-known/site.standard.publication` exposes only the configured publication AT-URI (see `well-known.md`).

## Front-end previews (`?atproto`)

Files: `includes/transformer/class-preview.php`, `includes/transformer/class-base.php` (`get_preview_records()`), the transformers' projecting modes.

The `?atproto` selector serves capability-gated JSON projections of records on public front-end URLs. Audit it as its own surface.

- **Capability.** Singular previews gate per post: `Preview::current_user_can_preview()` → `edit_post`, `$post->ID`. The front page has no post to scope to and keeps the `edit_posts` floor. A blanket capability on a singular preview is a cross-author disclosure.
- **Strictly read-only.** Trace every transformer path a GET can reach: no blob uploads (`Document` and `Publication` project via cached blob refs, `Post::cached_image_blob()`, never `upload_thumbnail()`), no post or attachment meta writes, no TID reservation (`Document::get_rkey()` writes `META_TID`, a publish-state marker `Publisher::update_post()` uses for create-vs-update detection), no DNS or HTTPS mention resolution (`Post::$projecting` skips `Facet::extract()`).
- **`REST_REQUEST`.** A constant that marks "this request is special" is defined only on branches that immediately `exit`.
- **Cache headers.** The response is per-user content on a URL-keyed public path. `nocache_headers()` (or `Cache-Control: private, no-store`) is sent before the JSON; status and Content-Type alone do not stop an edge cache from serving an authorized preview to anonymous visitors. Open as of 2026-07; if still absent, report it.
- **Fall through, never 404.** A request that fails the gates renders the normal page untouched; an error for an unauthorized `?atproto` hit tells the visitor the parameter means something.
- **Redaction for authorized viewers too.** Password-protected and non-publish posts project redacted fields (`Base::is_post_redacted()`), so an editor cannot copy protected content into a shareable JSON URL.
- **Third-party transformers.** `atmosphere_atproto_preview_transformers` lets other plugins expose records under the same gates. The read-only contract is documented on `Base::get_preview_records()`, and filter-added output falls under the same capability decision.
