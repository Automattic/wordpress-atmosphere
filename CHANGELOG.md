# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/Automattic/wordpress-atmosphere/releases