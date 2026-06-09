=== ATmosphere ===
Contributors: automattic, pfefferle, kraftbj, jeherve, ryanc413
Tags: at-protocol, bluesky, fediverse, atproto, crossposting
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Share your WordPress posts on Bluesky and the wider AT Protocol network — and let conversations there come back as comments on your site.

== Description ==

**ATmosphere** turns your WordPress site into a first-class citizen of the AT Protocol — the open network behind [Bluesky](https://bsky.social/).

When you publish a post, ATmosphere automatically shares it on Bluesky and stores the full article on your AT Protocol account so any compatible app can read it. When people on Bluesky reply, like, or repost what you shared, those reactions show up as comments on your post. And approved comments your readers leave on WordPress are sent right back to Bluesky as replies under your original post — so the same conversation happens in both places without you having to copy anything by hand.

= What you get =

* **Your posts on Bluesky, automatically.** Hit "Publish" on WordPress, and a moment later your post appears on Bluesky. Links, @-mentions, and #hashtags are detected for you.
* **Long posts done right.** A long article becomes a short, readable Bluesky thread that links back to the full piece on your site. Edits are kept tidy so existing replies and reposts on Bluesky don't get orphaned.
* **Use your own domain as your Bluesky handle.** With one click, your handle becomes something like `@yourblog.com` instead of `@you.bsky.social`. ATmosphere does the technical bit; Bluesky verifies it.
* **Bluesky reactions become WordPress comments.** Replies appear in your comments. Likes and reposts show up alongside them with their own counts so the engagement is visible to your readers.
* **WordPress comments become Bluesky replies.** When a logged-in reader leaves an approved comment on a cross-posted article, it's sent to Bluesky as a reply under the original post.
* **Catch up on older posts.** A `wp atmosphere backfill` command can publish posts you wrote before installing the plugin.
* **Per-post control.** You can opt individual posts out of cross-posting straight from the editor sidebar.
* **No middleman.** ATmosphere talks directly to your Bluesky account using modern, secure sign-in. Nothing is routed through a third-party service, and your tokens never leave your WordPress site.
* **Translation-ready.** Help translate ATmosphere into your language.

= How it works =

1. Install ATmosphere and activate it.
2. Go to **Settings → ATmosphere** and click "Connect" — sign in to Bluesky in the normal Bluesky window, then come back to WordPress.
3. Fill in a name, description, and icon for your "publication" — this is how your site is represented on the AT Protocol.
4. Publish a post.
5. Open Bluesky — your post is there. People can reply, like, repost, and follow as they normally would.
6. Replies, likes, and reposts will start appearing as comments on your WordPress post. Comments you approve on WordPress will appear as replies on Bluesky.

**Note:** Cross-posting only kicks in for posts you publish *after* connecting. To bring older posts across, run `wp atmosphere backfill` from WP-CLI.

== Installation ==

1. Upload the `atmosphere` folder to `/wp-content/plugins/` (or install from the Plugins screen in WordPress).
2. Activate the plugin through the "Plugins" menu.
3. Go to **Settings → ATmosphere** and click "Connect" to sign in with your Bluesky account.
4. Set the name, description, and icon for how your site should appear on the AT Protocol.
5. You're done — your next WordPress post will appear on Bluesky.

== Frequently Asked Questions ==

= Do I need a Bluesky account? =

Yes — or an account on any AT Protocol provider. Most people sign up at [bsky.app](https://bsky.app/), but the plugin works with any compatible AT Protocol service.

= Does my account information stay on my site? =

Yes. ATmosphere signs in to Bluesky directly from your WordPress site. Nothing is routed through Automattic or any other intermediary, and your sign-in tokens are stored encrypted on your site.

= Can I use my own domain as my Bluesky handle? =

Yes — that's one of the headline features. Once you've connected, open Bluesky's app settings, choose "Change Handle", pick "I have my own domain", and enter your WordPress site's domain. Bluesky will check that it really is your site (ATmosphere takes care of the verification file) and switch your handle.

= Can I stop a single post from being cross-posted? =

Yes. In the post editor sidebar there's an "ATmosphere" panel where you can opt the current post out before publishing.

= What about long posts? =

Long posts are turned into a short Bluesky thread of a few connected posts, with the last one linking back to the full article on your site. The full text of your post lives on your AT Protocol account, so other AT Protocol-aware apps and readers can show it too.

= Are my WordPress comments published to Bluesky? =

Yes — approved comments left by logged-in readers on cross-posted articles are sent to Bluesky as replies under your original post. Anonymous comments, trackbacks, and pingbacks are skipped.

= Are Bluesky replies and reposts pulled back into WordPress? =

Yes. ATmosphere checks Bluesky periodically and turns replies, likes, and reposts into WordPress comments on the matching post. Likes and reposts have their own comment types so they show up as engagement counts, not as duplicate comment text.

= What about posts I already published before installing? =

By default, only new posts are shared. You can publish older ones on demand by running `wp atmosphere backfill` from WP-CLI.

= Can I undo a cross-post? =

Yes. If you delete or unpublish a WordPress post, the matching Bluesky post and AT Protocol records are removed too. If you trash a post and then restore it, ATmosphere re-publishes it.

= Does ATmosphere support WordPress Multisite? =

Not at this time. ATmosphere is designed for a single WordPress site. On a Network-activated install only the current site's data is read and written, and uninstall only cleans the current site — credentials and records on other sites in the network are left intact.

== Changelog ==

### 1.1.1 - 2026-06-01
#### Added
- Posts shared to Bluesky now link back to both the site's publication record and the per-post document record, so Bluesky shows your site source, profile, and richer document metadata alongside the link preview.

#### Changed
- Likes and reposts synced from Bluesky now include a readable comment body ("… liked this!" / "… reposted this!") so they display sensibly in themes that render activity-feed comments.

#### Fixed
- Aside, status, and other short-form posts now include their images when published to Bluesky.
- Fix a fatal error when saving the Bluesky handle on sites without auth keys defined in wp-config.php.
- Fix unexpected disconnections by refreshing the AT Protocol session more reliably.
- Preserve your AT Protocol identity when disconnecting so a custom domain handle (`example.com` instead of `alice.bsky.social`) can be used to reconnect later. Disconnect no longer wipes the verification endpoint that the handle resolver depends on, no longer auto-reverts the Bluesky handle back to its pre-domain value, and shows a clearer "disconnected" notice instead of a "session expired" warning.
- Refresh admin assets after updating, so the latest styles and scripts load correctly.
- Site names and descriptions containing characters like apostrophes or ampersands now publish correctly instead of showing raw HTML codes.
- Stop reinjecting replies into the moderation queue when the parent Bluesky message has been deleted or blocked.
- Your site icon now appears on your standard.site publication.

### 1.1.0 - 2026-05-21
#### Added
- Add `atmosphere_post_embed` filter so downstream code can swap the default external link card for a richer embed (`app.bsky.embed.images`, `app.bsky.embed.video`, …) or attach an embed to a short-form post that would otherwise ship with none. The filter accepts `null` (suppress) or an array with a non-empty string `$type` key; non-array, empty-array, or missing-`$type` returns are rejected with `_doing_it_wrong` and the pre-filter value is restored. `Post::upload_thumbnail()` becomes a backward-compatible alias for the new generic `Post::upload_image_blob()`; a new `Post::get_attachment_aspect_ratio()` helper exposes the pixel dimensions consumers need for `embed.images`.
- Advertise the site's standard.site publication record from the front page and from each published post via a new `<link rel="site.standard.publication">` tag, alongside the existing document link.

#### Changed
- Refresh the site's publication record when the site URL, the active theme, or the theme's colours change, so the published record always reflects the current site state.

#### Fixed
- Fix posts not appearing on Bluesky after a frontend visit lazily stamped the post, restore standard.site verification after reconnecting to a different account, and let the "use my domain as my Bluesky handle" button complete reliably without timing out.
- Stop publishing replies to local-only WordPress comments to Bluesky. Previously such replies were demoted to a top-level reply on the post; they are now skipped so the Bluesky thread only mirrors comments whose ancestors are also on Bluesky.

### 1.0.0 - 2026-05-20
#### Security
- Harden OAuth and PDS HTTP request paths against SSRF, encrypt the temporary DPoP key used during connect, and validate URLs received from third-party servers before they are used or stored.
- Tighten DPoP proof lifetime when talking to the AT Protocol auth server and PDS, and harden the OAuth and PDS HTTP paths against malformed server responses.
- Tighten OAuth redirect handling, validate hook return values from third-party plugins, gate DNS lookups for @mentions, and clean up additional plugin data on uninstall.

#### Added
- Add extensible content parser support and a JSON preview endpoint for AT Protocol records.
- Add `atmosphere_publish_post_result` and `atmosphere_publish_comment_result` actions so subscribers can react to publish success or failure (e.g., for metrics and notifications) without observing internal state.
- Add `atmosphere_should_sync_reply` filter so consumers can suppress specific incoming replies before they become WordPress comments — primarily useful for teaser-thread publishers that don't want their own follow-up records re-ingested as self-replies.
- Automatically sync the publication record when the site name, tagline, or site icon changes.
- Choose how long-form posts publish to Bluesky from the ATmosphere settings page — link card (default), a single post combining body text with the permalink, or a two-post teaser thread.
- Choose which post types are published to AT Protocol from the ATmosphere settings page. Plugins and themes can also opt their custom post types in directly with `add_post_type_support( 'your_type', 'atmosphere' )`.
- Liftoff! ATmosphere has cleared the troposphere — version 1.0 is now generally available.
- Long-form posts can now be published to Bluesky as a short thread that points readers back to the full article. Sites can keep the existing single-post behavior, publish a shortened text version with a link, or use a two-post teaser thread. When a threaded post is edited, ATmosphere updates the existing Bluesky posts when possible so links and replies stay connected. If the publishing format changes, ATmosphere replaces the old Bluesky posts with new ones.
- Preserve the connection success notice after completing Bluesky setup, and let integrating plugins customize the OAuth callback destination.
- Publish replies from registered WordPress users to Bluesky as native replies, with edit and unapprove/delete synced back to the AT Protocol record.
- Request the identity:handle permission when connecting to Bluesky so handle changes can be kept in sync.
- Short-form posts (untitled or with a post format) now publish as native Bluesky posts instead of link cards, matching the ActivityPub plugin's Note discriminator. Added the `atmosphere_is_short_form_post` filter for downstream override.
- Sync Bluesky replies, likes, and reposts back as WordPress comments.
- Use your site domain as your Bluesky handle with one click from the ATmosphere settings page.
- Use your WordPress domain as your Bluesky handle with automatic domain verification.

#### Changed
- Always use HTTPS for the AT Protocol OAuth callback URL, and keep encrypted connection tokens out of the always-loaded options cache.
- Improved Bluesky connection reliability and disconnect speed, fixed a rare duplicate-record issue when publishing simultaneously from multiple workers, and now respects your comment moderation and spam filter settings when importing Bluesky reactions and replies.
- Improve the development test setup so automated tests can run while another local WordPress environment is already using the default ports.
- Limit backfill to the 10 most recent unsynced posts to avoid overwhelming the server on large sites.
- Long-form teaser threads now use a 3-post default (hook, body chunk, "continue reading" reply with a link card), so the thread reliably surfaces on bsky.app profiles and the terminal post offers a clear path back to the WordPress article.
- Redesign the settings page to use the standard WordPress Settings API for a cleaner, more consistent admin experience.
- Replace third-party JWT library with native OpenSSL signing and add a custom class autoloader.

#### Fixed
- Break up large cleanup batches when removing a post and its replies so deletion still completes on threads with many comments.
- Clear every plugin-owned scheduled event on deactivate and uninstall so leftover jobs don't linger after the plugin is removed.
- Clear queued sync events on disconnect, deactivation, and uninstall so leftover jobs cannot fire against a different connected account.
- Editing a WordPress post that was published before connecting to Bluesky no longer creates a new Bluesky post on save. Use the Backfill tool to sync existing posts on purpose.
- Fix auto-publish being disabled by default after saving settings.
- Fix PHPCS warnings about unprefixed global variables and hook names.
- Fix published posts being incorrectly deleted from Bluesky when editing.
- Fix restoring a trashed post not republishing it to Bluesky.
- Fix the settings page, meta box, and backfill actions not loading after the previous admin hook change.
- Keep your AT Protocol verification headers and publishing preferences in place when your session expires. Reconnect is required to resume publishing, but your settings no longer reset and standard.site verification keeps working.
- Move scheduled action hook registration into the standard plugin initialization flow.
- Preserve remote cleanup of already-synced posts when their post type is removed from the syncable allowlist.
- Preserve the OAuth connection when token refresh fails due to temporary server errors.
- Prevent concurrent token refreshes from racing each other and accidentally disconnecting the plugin.
- Prevent password-protected or otherwise non-public posts from being published to AT Protocol records, and remove existing records when public posts become protected.
- Remove a comment reply from Bluesky if the comment was deleted or unapproved while it was being published, instead of leaving an orphan reply behind.
- Short posts under the long-form teaser-thread strategy no longer ship a redundant "continue reading" reply when the entire body already fits in a single Bluesky post. The link-back is preserved as a card on the same post.

[1.1.1]: https://github.com/Automattic/wordpress-atmosphere/compare/1.1.0...1.1.1
[1.1.0]: https://github.com/Automattic/wordpress-atmosphere/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/Automattic/wordpress-atmosphere/releases

See full Changelog on [GitHub](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/CHANGELOG.md).

== Upgrade Notice ==

= 0.1.0 =

Initial release.
