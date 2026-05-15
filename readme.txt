=== ATmosphere ===
Contributors: automattic, pfefferle, kraftbj, ryancowles
Tags: at-protocol, bluesky, fediverse, atproto, crossposting
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: unreleased
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
* **Catch up on older posts.** A built-in Backfill tool can publish posts you wrote before installing the plugin.
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

**Note:** Cross-posting only kicks in for posts you publish *after* connecting. To bring older posts across, use the **Backfill** tool on the settings page.

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

By default, only new posts are shared. You can publish older ones on demand with the **Backfill** tool on the settings page.

= Can I undo a cross-post? =

Yes. If you delete or unpublish a WordPress post, the matching Bluesky post and AT Protocol records are removed too. If you trash a post and then restore it, ATmosphere re-publishes it.

== Changelog ==

= 0.1.0 =

* Initial release.
* Native AT Protocol OAuth with PKCE and DPoP.
* Bluesky cross-posting with facet detection.
* standard.site publication and document records.
* Per-post meta box controls.
* Bulk backfill for existing posts.

== Upgrade Notice ==

= 0.1.0 =

Initial release.
