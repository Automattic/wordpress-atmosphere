# ATmosphere

> [!IMPORTANT]
> **Proof of Concept** — This plugin is under active development and not yet ready for production use. APIs, data formats, and behavior may change without notice.

Publish WordPress posts to [AT Protocol](https://atproto.com/) ([Bluesky](https://bsky.social/) + [standard.site](https://standard.site/)) via native OAuth.

## Description

ATmosphere connects your WordPress site to the AT Protocol network. When you publish a post, it is automatically cross-posted to Bluesky and registered as a standard.site document on your Personal Data Server (PDS).

The plugin uses native AT Protocol OAuth with PKCE and DPoP — no third-party proxy or intermediary service required. Your credentials never leave your site.

## Features

- **Native OAuth** — Authenticate directly with your PDS using OAuth 2.1 with PKCE and DPoP.
- **Bluesky cross-posting** — Publish `app.bsky.feed.post` records that appear in your followers' timelines.
- **standard.site records** — Create `site.standard.publication` and `site.standard.document` records on your PDS.
- **Facet detection** — Automatically detects links, mentions, and hashtags in post content.
- **Per-post control** — Enable or disable publishing for individual posts via a meta box.
- **Backfill** — Sync existing published posts to AT Protocol in bulk.

## How It Works

1. Connect your Bluesky / AT Protocol account via OAuth on the settings page.
2. Configure your publication metadata (name, description, icon).
3. Publish a post — ATmosphere creates both a Bluesky post and a standard.site document record on your PDS.

## Requirements

- PHP 8.1+
- WordPress 6.2+
- Composer

## Installation

1. Clone this repository into your `wp-content/plugins/` directory:
   ```bash
   git clone https://github.com/pfefferle/atmosphere.git wp-content/plugins/atmosphere
   ```
2. Install dependencies:
   ```bash
   cd wp-content/plugins/atmosphere
   composer install --no-dev
   ```
3. Activate the plugin through the "Plugins" menu in WordPress.
4. Go to **Settings → ATmosphere** and connect your AT Protocol account.

## Development

### Setup

```bash
composer install
npm install
```

### Local Environment (wp-env)

```bash
npm run env-start    # Start WordPress environment
npm run env-stop     # Stop environment
```

### Linting

```bash
composer lint        # Check PHP coding standards
composer lint:fix    # Auto-fix PHP issues
```

### Testing

```bash
composer test                            # Full PHPUnit test suite
npm run env-test                         # Run tests via wp-env
npm run env-test -- --filter test_name   # Run a single test
```

## Architecture

```
includes/
├── oauth/            # Full PKCE + DPoP + PAR native OAuth flow
│   ├── class-client.php
│   ├── class-dpop.php
│   ├── class-encryption.php
│   ├── class-nonce-storage.php
│   └── class-resolver.php
├── transformer/      # WP → AT Protocol record conversion
│   ├── class-base.php
│   ├── class-document.php
│   ├── class-facet.php
│   ├── class-post.php
│   ├── class-publication.php
│   └── class-tid.php
├── wp-admin/         # Settings page, meta box, REST endpoint
├── class-api.php     # DPoP-authenticated PDS requests with nonce retry
├── class-atmosphere.php
├── class-backfill.php
├── class-publisher.php  # Atomic batch applyWrites for both record types
└── functions.php
```

## FAQ

### Do I need a Bluesky account?

You need an AT Protocol account. Bluesky (`bsky.social`) is the most common provider, but any AT Protocol PDS will work.

### Does this require a third-party service?

No. The plugin authenticates directly with your PDS using native AT Protocol OAuth. No tokens are sent to or stored by any intermediary.

### What records are created?

Each published post creates:

- An `app.bsky.feed.post` record (visible on Bluesky).
- A `site.standard.document` record (structured metadata for the ATmosphere).

Your site itself is represented by a `site.standard.publication` record.

## License

ATmosphere is licensed under the [GPL v2 or later](LICENSE).
