# Records

What goes into `app.bsky.feed.post` and `site.standard.document`, and what building it costs. Who may see a record is in `content-exposure.md`; whether it matches the Lexicon is the spec-check agent's job.

Applies to: `includes/class-publisher.php`, `includes/transformer/**`, `includes/content-parser/**`, `includes/class-mention.php`, `includes/class-link.php`

## Past issues

- **Per-call egress cap read as per-publish** (#165 review, residual): `MAX_RESOLVED_HANDLES` bounds one `Facet::extract()` call, but a teaser thread re-enters `extract()` per entry and multiplies the bound (about 5 × 20 lookups per publish, each up to DNS plus a 10 s HTTPS fetch).

## Building records

- Post content is sanitized before transformation.
- The `applyWrites` batch cannot be steered into writing unexpected records or collections.
- TID generation is not predictable and does not collide.
- Facet detection (`class-facet.php`) cannot inject malicious links or mentions.
- The bsky post transformer respects Bluesky's character and image limits.

## Mention resolution budget

Check four things together:

1. The worst case per publish or request, not per function call: add up every `Facet::extract()` call the publish makes (thread entries and retries multiply a per-call cap).
2. Misses are cached as well as hits, or a dead handle is resolved again on every call.
3. Commenter-controlled content never triggers resolution unless the site opts in with a boolean filter that is cast with `(bool)`.
4. `Resolver::is_valid_handle()` rejects reserved and numeric TLDs before any network I/O (see "Handle syntax" in `remote-fetch.md`).

The fix for attacker-chosen DNS is at the threat-model layer (gating the commenter path, an allow list, DoH with a timeout), not in the syntax regex.

## Publish and display parity

Whatever decides "this `@handle` is a mention" or "this is a link" is shared between the front-end linkifier and the facet extractor. Two tokenizers drift: notifications without links, links without notifications, including `#mention` facets (real Bluesky notifications) for handles inside `<code>`, `<pre>` or `<a>` that the site's own display refuses to link.
