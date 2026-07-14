=== ATmosphere ===
Contributors: automattic, pfefferle, kraftbj, jeherve, ryanc413
Tags: at-protocol, bluesky, fediverse, atproto, crossposting
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Share your WordPress posts on Bluesky and the wider AT Protocol network — and let conversations there come back as comments on your site.

== Description ==

**ATmosphere** turns your WordPress site into a first-class citizen of the AT Protocol — the open network behind [Bluesky](https://bsky.social/).

When you publish a post, ATmosphere automatically shares it on Bluesky and stores the full article on your AT Protocol account so any compatible app can read it. When people on Bluesky reply, like, or repost what you shared, those reactions show up as comments on your post. And approved comments your readers leave on WordPress are sent right back to Bluesky as replies under your original post — so the same conversation happens in both places without you having to copy anything by hand.

= What you get =

* **Your posts on Bluesky, automatically.** Hit "Publish" on WordPress, and a moment later your post appears on Bluesky. Links, @-mentions, and #hashtags are detected for you. Mention a Bluesky account with `@handle.tld` and the mention links to their profile on your site, while they get notified on Bluesky — even on longer posts.
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

= Why does ATmosphere say it needs to reconnect? =

Your Bluesky sign-in tokens are stored encrypted using your site's WordPress security keys. If those keys change — after a site migration, or when a security plugin rotates them — the saved login can no longer be read, and ATmosphere asks you to reconnect. Reconnecting on the settings page fixes it in one step. If it keeps happening, look for a security plugin that rotates your keys on a schedule.

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

ATmosphere also checks a small rolling batch of published posts each day, so older missed replies are recovered automatically without requiring post IDs. To check a specific post immediately, run `wp atmosphere replies backfill <post-id>` from WP-CLI. Both paths scan replies currently visible through Bluesky's public API and import those that are not already present.

= What about posts I already published before installing? =

By default, only new posts are shared. You can publish older ones on demand by running `wp atmosphere backfill` from WP-CLI.

= Can I undo a cross-post? =

Yes. If you delete or unpublish a WordPress post, the matching Bluesky post and AT Protocol records are removed too. If you trash a post and then restore it, ATmosphere re-publishes it.

= Does ATmosphere support WordPress Multisite? =

Not at this time. ATmosphere is designed for a single WordPress site. On a Network-activated install only the current site's data is read and written, and uninstall only cleans the current site — credentials and records on other sites in the network are left intact.

== Changelog ==

### 2.0.0 - 2026-07-08
#### Added
- Add an ATmosphere Reactions block that shows the Bluesky likes and reposts that your posts have received, as a facepile of avatars with a count.
- Add filters so links to Bluesky can point at an alternative AT Protocol appview, including ones hosted on a subdomain or subpath.
- Before publishing, the editor now shows whether a post will be shared to Bluesky, how it will appear, and how its text measures against Bluesky’s character limit.
- Failed attempts to share a post to Bluesky are now retried automatically for about twenty minutes, so a brief network or server hiccup no longer means the post silently never appears.
- Mention a Bluesky account with @handle.tld in your post: the mention now links to their profile on your site, and they are notified on Bluesky even on longer posts.
- Preview AT Protocol output by record type, including an all-record view for comparing records in the current page context.
- The editor's Bluesky panel now has a custom text field: write your own message for Bluesky and it is posted with a link back to your post, instead of the automatically composed text.
- The editor's Bluesky panel now has a switch to turn sharing on or off for an individual post; switching it off after a post was shared removes it from Bluesky.
- The editor now shows a notice when sharing a post to Bluesky fails, including whether it will be retried automatically or needs the post to be updated again.
- The settings page now warns you when auto-publishing is on but no post types are selected, so nothing would be published.

#### Changed
- ATmosphere now requires WordPress 6.5 or later.
- Standard.site records and OAuth permissions are now more compatible with current long-form publishing tools and discovery.

#### Fixed
- Bluesky replies that quote another post now keep a link to the quoted post when imported as comments, instead of dropping it.
- Deleting a post now reliably removes it from Bluesky even when it has a large number of replies to clean up.
- Disconnecting or deactivating now reliably removes all pending background tasks, so a task queued under a previous connection can no longer run against a newly connected account.
- Emoji now count as a single character against Bluesky's 300-character limit, the same way Bluesky's own composer counts them. Posts with emoji are no longer trimmed earlier than necessary, and the editor's character count matches what you would see on Bluesky.
- Hardened the security of connections to your Bluesky account.
- Import Bluesky likes, reposts, and replies on all your published content types. Previously these interactions were only brought back for standard posts, so likes and replies on pages and other content published to Bluesky were quietly missed.
- Kept standard.site document references in Bluesky posts pointing at the current document record and made discovery URLs resolve more consistently.
- Links in Bluesky replies now keep their full web address when imported as comments, instead of showing a shortened, unclickable preview.
- Links inside short posts shared to Bluesky now stay clickable, instead of being flattened to plain text with the link dropped.
- Long posts without a title are now shared to Bluesky as a summary with a link back to the original, instead of being cut off mid-sentence with no way to reach the full post.
- Reliably import nested Bluesky replies. Reactions are now processed oldest-first within each sync, so a reply threads under its parent comment in the same run instead of being dropped when a whole thread arrives between syncs.
- Send private, no-cache headers on the record preview so a caching layer cannot store a logged-in preview and show it to other visitors.
- Shared posts on Bluesky now keep your site's publication details up to date automatically when you publish, so Standard.site readers always see current information.
- The AT Protocol record preview no longer shows sharing buttons or other theme and plugin extras that are not part of the published record.
- Your site's theme colours now display correctly in enhanced link cards on Bluesky and other apps, instead of being dropped because the publication record failed validation.

See full Changelog on [GitHub](https://github.com/Automattic/wordpress-atmosphere/blob/trunk/CHANGELOG.md).

== Upgrade Notice ==

= 0.1.0 =

Initial release.
