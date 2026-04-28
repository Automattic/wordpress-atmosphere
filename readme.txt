=== ATmosphere ===
Contributors: automattic, pfefferle, kraftbj, ryancowles
Tags: at-protocol, bluesky, fediverse, atproto, crossposting
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: unreleased
License: GPL-2.0
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish WordPress posts to AT Protocol (Bluesky and standard.site) via native OAuth.

== Description ==

ATmosphere connects your WordPress site to the AT Protocol network. When you publish a post, it is automatically cross-posted to Bluesky and registered as a standard.site document on your Personal Data Server (PDS).

The plugin uses native AT Protocol OAuth with PKCE and DPoP — no third-party proxy or intermediary service required. Your credentials never leave your site.

= Features =

* **Native OAuth** — Authenticate directly with your PDS using OAuth 2.1 with PKCE and DPoP.
* **Bluesky cross-posting** — Publish `app.bsky.feed.post` records that appear in your followers' timelines.
* **standard.site records** — Create `site.standard.publication` and `site.standard.document` records on your PDS.
* **Facet detection** — Automatically detects links, mentions, and hashtags in post content.
* **Per-post control** — Enable or disable publishing for individual posts via a meta box.
* **Domain handle verification** — Use your WordPress domain as your Bluesky handle via `/.well-known/atproto-did`.
* **Backfill** — Sync existing published posts to AT Protocol in bulk.

= How It Works =

1. Connect your Bluesky / AT Protocol account via OAuth on the settings page.
2. Configure your publication metadata (name, description, icon).
3. Publish a post — ATmosphere creates both a Bluesky post and a standard.site document record on your PDS.

== Installation ==

1. Upload the `atmosphere` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **Settings → ATmosphere** and connect your AT Protocol account.

== Frequently Asked Questions ==

= Do I need a Bluesky account? =

You need an AT Protocol account. Bluesky (`bsky.social`) is the most common provider, but any AT Protocol PDS will work.

= Does this require a third-party service? =

No. The plugin authenticates directly with your PDS using native AT Protocol OAuth. No tokens are sent to or stored by any intermediary.

= What records are created? =

Each published post creates:

* An `app.bsky.feed.post` record (visible on Bluesky).
* A `site.standard.document` record (structured metadata for the ATmosphere).

Your site itself is represented by a `site.standard.publication` record.

= Can I use my domain as my Bluesky handle? =

Yes. Once you connect your account, the plugin serves your DID at `/.well-known/atproto-did`. Go to the Bluesky app settings, choose "Change Handle", select "I have my own domain", and enter your WordPress site's domain. Bluesky will verify it automatically.

= Can I disable Bluesky cross-posting? =

Per-post controls are available in the post editor meta box. Global defaults can be configured on the settings page.

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
