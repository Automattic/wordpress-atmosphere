# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-07-08
### Added
- Add an ATmosphere Reactions block that shows the Bluesky likes and reposts that your posts have received, as a facepile of avatars with a count. [#148]
- Add filters so links to Bluesky can point at an alternative AT Protocol appview, including ones hosted on a subdomain or subpath. [#159]
- Before publishing, the editor now shows whether a post will be shared to Bluesky, how it will appear, and how its text measures against Bluesky’s character limit. [#139]
- Failed attempts to share a post to Bluesky are now retried automatically for about twenty minutes, so a brief network or server hiccup no longer means the post silently never appears. [#182]
- Mention a Bluesky account with @handle.tld in your post: the mention now links to their profile on your site, and they are notified on Bluesky even on longer posts. [#165]
- Preview AT Protocol output by record type, including an all-record view for comparing records in the current page context. [#170]
- The editor's Bluesky panel now has a custom text field: write your own message for Bluesky and it is posted with a link back to your post, instead of the automatically composed text. [#152]
- The editor's Bluesky panel now has a switch to turn sharing on or off for an individual post; switching it off after a post was shared removes it from Bluesky. [#139]
- The editor now shows a notice when sharing a post to Bluesky fails, including whether it will be retried automatically or needs the post to be updated again. [#183]
- The settings page now warns you when auto-publishing is on but no post types are selected, so nothing would be published. [#174]

### Changed
- ATmosphere now requires WordPress 6.5 or later. [#148]
- Standard.site records and OAuth permissions are now more compatible with current long-form publishing tools and discovery. [#167]

### Fixed
- Bluesky replies that quote another post now keep a link to the quoted post when imported as comments, instead of dropping it. [#157]
- Deleting a post now reliably removes it from Bluesky even when it has a large number of replies to clean up. [#138]
- Disconnecting or deactivating now reliably removes all pending background tasks, so a task queued under a previous connection can no longer run against a newly connected account. [#182]
- Emoji now count as a single character against Bluesky's 300-character limit, the same way Bluesky's own composer counts them. Posts with emoji are no longer trimmed earlier than necessary, and the editor's character count matches what you would see on Bluesky. [#156]
- Hardened the security of connections to your Bluesky account. [#171]
- Import Bluesky likes, reposts, and replies on all your published content types. Previously these interactions were only brought back for standard posts, so likes and replies on pages and other content published to Bluesky were quietly missed. [#185]
- Kept standard.site document references in Bluesky posts pointing at the current document record and made discovery URLs resolve more consistently.
- Links in Bluesky replies now keep their full web address when imported as comments, instead of showing a shortened, unclickable preview. [#134]
- Links inside short posts shared to Bluesky now stay clickable, instead of being flattened to plain text with the link dropped. [#146]
- Long posts without a title are now shared to Bluesky as a summary with a link back to the original, instead of being cut off mid-sentence with no way to reach the full post. [#145]
- Reliably import nested Bluesky replies. Reactions are now processed oldest-first within each sync, so a reply threads under its parent comment in the same run instead of being dropped when a whole thread arrives between syncs. [#186]
- Send private, no-cache headers on the record preview so a caching layer cannot store a logged-in preview and show it to other visitors. [#187]
- Shared posts on Bluesky now keep your site's publication details up to date automatically when you publish, so Standard.site readers always see current information. [#167]
- The AT Protocol record preview no longer shows sharing buttons or other theme and plugin extras that are not part of the published record. [#170]
- Your site's theme colours now display correctly in enhanced link cards on Bluesky and other apps, instead of being dropped because the publication record failed validation. [#154]

## [1.2.0] - 2026-06-15
### Security
- Hardened error logging so details from cryptographic failures are never written to the log. [#128]
- Hardened the Bluesky connect flow so a redirect interruption cannot loosen redirect safety checks for the rest of the request. [#129]
- Hardened the Bluesky server address checks to also catch unsafe addresses hidden behind URL encoding. [#130]

### Added
- Add a Content format setting so you can choose how your posts are saved for standard.site readers — rendered HTML, Markdown, Leaflet, or pckt. [#112]
- Add rich content support for standard.site documents using the Markpub format. [#112]
- A new `wp atmosphere backfill` WP-CLI command publishes your older posts to Bluesky in bulk from the command line. [#91]
- New settings let you stop importing Bluesky likes and reposts, or replies. [#122]

### Changed
- Keep diagnostic messages out of your site's error log unless WordPress debugging (WP_DEBUG) is turned on. [#135]

### Removed
- The "Start Backfill" button has moved from the settings page to WP-CLI. Run "wp atmosphere backfill" to sync existing posts. [#91]

### Fixed
- Accept Bluesky handles entered with a leading "@" — pasting "@alice.bsky.social" now connects just like "alice.bsky.social". [#69]
- Apply your auto-publish, post-type, and long-form preferences even when a post is published outside the WordPress admin (REST API, WP-CLI, or scheduled posts). [#69]
- Fix domain handle verification failing on some sites when using your site's domain as your Bluesky handle. [#93]
- Restore the cover image and Bluesky link preview thumbnail for posts whose featured image is served from a CDN, such as on WordPress.com sites. [#114]
- Send your site's theme colours to standard.site in the format the network expects so they show up on your publication page. [#110]
- Show a clear error instead of crashing when connecting on a server whose OpenSSL build cannot create the secure key Bluesky requires. [#117]
- Stop caches from holding on to a stale domain handle or publication link, so reconnecting or switching accounts takes effect right away. [#111]
- Trim very long site titles and taglines when syncing your publication so the record is always accepted by the network. [#108]

## [1.1.1] - 2026-06-01
### Added
- Posts shared to Bluesky now link back to both the site's publication record and the per-post document record, so Bluesky shows your site source, profile, and richer document metadata alongside the link preview. [#106]

### Changed
- Likes and reposts synced from Bluesky now include a readable comment body ("… liked this!" / "… reposted this!") so they display sensibly in themes that render activity-feed comments. [#104]

### Fixed
- Aside, status, and other short-form posts now include their images when published to Bluesky. [#96]
- Fix a fatal error when saving the Bluesky handle on sites without auth keys defined in wp-config.php. [#101]
- Fix unexpected disconnections by refreshing the AT Protocol session more reliably. [#102]
- Preserve your AT Protocol identity when disconnecting so a custom domain handle (`example.com` instead of `alice.bsky.social`) can be used to reconnect later. Disconnect no longer wipes the verification endpoint that the handle resolver depends on, no longer auto-reverts the Bluesky handle back to its pre-domain value, and shows a clearer "disconnected" notice instead of a "session expired" warning. [#85]
- Refresh admin assets after updating, so the latest styles and scripts load correctly. [#80]
- Site names and descriptions containing characters like apostrophes or ampersands now publish correctly instead of showing raw HTML codes. [#98]
- Stop reinjecting replies into the moderation queue when the parent Bluesky message has been deleted or blocked. [#95]
- Your site icon now appears on your standard.site publication. [#98]

## [1.1.0] - 2026-05-21
### Added
- Add `atmosphere_post_embed` filter so downstream code can swap the default external link card for a richer embed (`app.bsky.embed.images`, `app.bsky.embed.video`, …) or attach an embed to a short-form post that would otherwise ship with none. The filter accepts `null` (suppress) or an array with a non-empty string `$type` key; non-array, empty-array, or missing-`$type` returns are rejected with `_doing_it_wrong` and the pre-filter value is restored. `Post::upload_thumbnail()` becomes a backward-compatible alias for the new generic `Post::upload_image_blob()`; a new `Post::get_attachment_aspect_ratio()` helper exposes the pixel dimensions consumers need for `embed.images`. [#72]
- Advertise the site's standard.site publication record from the front page and from each published post via a new `<link rel="site.standard.publication">` tag, alongside the existing document link. [#75]

### Changed
- Refresh the site's publication record when the site URL, the active theme, or the theme's colours change, so the published record always reflects the current site state. [#76]

### Fixed
- Fix posts not appearing on Bluesky after a frontend visit lazily stamped the post, restore standard.site verification after reconnecting to a different account, and let the "use my domain as my Bluesky handle" button complete reliably without timing out. [#74]
- Stop publishing replies to local-only WordPress comments to Bluesky. Previously such replies were demoted to a top-level reply on the post; they are now skipped so the Bluesky thread only mirrors comments whose ancestors are also on Bluesky. [#78]

## [1.0.0] - 2026-05-20
### Security
- Harden OAuth and PDS HTTP request paths against SSRF, encrypt the temporary DPoP key used during connect, and validate URLs received from third-party servers before they are used or stored. [#61]
- Tighten DPoP proof lifetime when talking to the AT Protocol auth server and PDS, and harden the OAuth and PDS HTTP paths against malformed server responses. [#64]
- Tighten OAuth redirect handling, validate hook return values from third-party plugins, gate DNS lookups for @mentions, and clean up additional plugin data on uninstall. [#62]

### Added
- Add extensible content parser support and a JSON preview endpoint for AT Protocol records. [#8]
- Add `atmosphere_publish_post_result` and `atmosphere_publish_comment_result` actions so subscribers can react to publish success or failure (e.g., for metrics and notifications) without observing internal state. [#56]
- Add `atmosphere_should_sync_reply` filter so consumers can suppress specific incoming replies before they become WordPress comments — primarily useful for teaser-thread publishers that don't want their own follow-up records re-ingested as self-replies. [#57]
- Automatically sync the publication record when the site name, tagline, or site icon changes. [#16]
- Choose how long-form posts publish to Bluesky from the ATmosphere settings page — link card (default), a single post combining body text with the permalink, or a two-post teaser thread. [#34]
- Choose which post types are published to AT Protocol from the ATmosphere settings page. Plugins and themes can also opt their custom post types in directly with `add_post_type_support( 'your_type', 'atmosphere' )`. [#38]
- Liftoff! ATmosphere has cleared the troposphere — version 1.0 is now generally available. [#67]
- Long-form posts can now be published to Bluesky as a short thread that points readers back to the full article. Sites can keep the existing single-post behavior, publish a shortened text version with a link, or use a two-post teaser thread. When a threaded post is edited, ATmosphere updates the existing Bluesky posts when possible so links and replies stay connected. If the publishing format changes, ATmosphere replaces the old Bluesky posts with new ones. [#34]
- Preserve the connection success notice after completing Bluesky setup, and let integrating plugins customize the OAuth callback destination. [#33]
- Publish replies from registered WordPress users to Bluesky as native replies, with edit and unapprove/delete synced back to the AT Protocol record. [#32]
- Request the identity:handle permission when connecting to Bluesky so handle changes can be kept in sync. [#53]
- Short-form posts (untitled or with a post format) now publish as native Bluesky posts instead of link cards, matching the ActivityPub plugin's Note discriminator. Added the `atmosphere_is_short_form_post` filter for downstream override. [#29]
- Sync Bluesky replies, likes, and reposts back as WordPress comments. [#6]
- Use your site domain as your Bluesky handle with one click from the ATmosphere settings page. [#55]
- Use your WordPress domain as your Bluesky handle with automatic domain verification. [#18]

### Changed
- Always use HTTPS for the AT Protocol OAuth callback URL, and keep encrypted connection tokens out of the always-loaded options cache. [#66]
- Improved Bluesky connection reliability and disconnect speed, fixed a rare duplicate-record issue when publishing simultaneously from multiple workers, and now respects your comment moderation and spam filter settings when importing Bluesky reactions and replies. [#65]
- Improve the development test setup so automated tests can run while another local WordPress environment is already using the default ports. [#40]
- Limit backfill to the 10 most recent unsynced posts to avoid overwhelming the server on large sites. [#15]
- Long-form teaser threads now use a 3-post default (hook, body chunk, "continue reading" reply with a link card), so the thread reliably surfaces on bsky.app profiles and the terminal post offers a clear path back to the WordPress article. [#49]
- Redesign the settings page to use the standard WordPress Settings API for a cleaner, more consistent admin experience. [#16]
- Replace third-party JWT library with native OpenSSL signing and add a custom class autoloader. [#23]

### Fixed
- Break up large cleanup batches when removing a post and its replies so deletion still completes on threads with many comments. [#32]
- Clear every plugin-owned scheduled event on deactivate and uninstall so leftover jobs don't linger after the plugin is removed. [#35]
- Clear queued sync events on disconnect, deactivation, and uninstall so leftover jobs cannot fire against a different connected account. [#32]
- Editing a WordPress post that was published before connecting to Bluesky no longer creates a new Bluesky post on save. Use the Backfill tool to sync existing posts on purpose. [#58]
- Fix auto-publish being disabled by default after saving settings. [#26]
- Fix PHPCS warnings about unprefixed global variables and hook names. [#28]
- Fix published posts being incorrectly deleted from Bluesky when editing. [#22]
- Fix restoring a trashed post not republishing it to Bluesky. [#24]
- Fix the settings page, meta box, and backfill actions not loading after the previous admin hook change. [#37]
- Keep your AT Protocol verification headers and publishing preferences in place when your session expires. Reconnect is required to resume publishing, but your settings no longer reset and standard.site verification keeps working. [#68]
- Move scheduled action hook registration into the standard plugin initialization flow. [#20]
- Preserve remote cleanup of already-synced posts when their post type is removed from the syncable allowlist. [#38]
- Preserve the OAuth connection when token refresh fails due to temporary server errors. [#21]
- Prevent concurrent token refreshes from racing each other and accidentally disconnecting the plugin. [#68]
- Prevent password-protected or otherwise non-public posts from being published to AT Protocol records, and remove existing records when public posts become protected. [#63]
- Remove a comment reply from Bluesky if the comment was deleted or unapproved while it was being published, instead of leaving an orphan reply behind. [#32]
- Short posts under the long-form teaser-thread strategy no longer ship a redundant "continue reading" reply when the entire body already fits in a single Bluesky post. The link-back is preserved as a card on the same post. [#51]

[2.0.0]: https://github.com/Automattic/wordpress-atmosphere/compare/1.2.0...2.0.0
[1.2.0]: https://github.com/Automattic/wordpress-atmosphere/compare/1.1.1...1.2.0
[1.1.1]: https://github.com/Automattic/wordpress-atmosphere/compare/1.1.0...1.1.1
[1.1.0]: https://github.com/Automattic/wordpress-atmosphere/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/Automattic/wordpress-atmosphere/releases
