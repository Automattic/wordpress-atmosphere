# ATmosphere Plugin

WordPress plugin that publishes posts to AT Protocol in both `app.bsky.feed.post` (Bluesky) and `site.standard.document` / `site.standard.publication` (standard.site) formats via native OAuth.

**Tech stack:** PHP 8.2+, WordPress 6.5+, wp-env for local dev, PHPUnit for tests, Jetpack Changelogger for changelog management.

**Do NOT:**
- Edit WordPress core files
- Hardcode new version numbers in changelog messages
- Create a PR without running `composer lint` and `npm run env-test` first
- Create a PR without a changelog entry in `.github/changelog/` or a "Skip Changelog" label

## Directory Structure

```
atmosphere.php                  # Main plugin file.
includes/
├── class-*.php                 # Core classes (Atmosphere, API, Publisher, Backfill, Handle, Post_Types, Reaction_Sync, Connectors).
├── functions.php               # Helper functions.
├── content-parser/             # Pluggable content formats for site.standard.document (interface only by default).
├── oauth/                      # OAuth flow (Client, DPoP, Encryption, Resolver, Nonce).
├── rest/                       # REST controllers (public + admin-only under rest/admin/).
├── transformer/                # AT Protocol record transformers (Post, Document, Publication, Comment, Facet, TID).
└── wp-admin/                   # Admin UI (settings page, sidebar panel).
integrations/                   # Plugin-specific content-parser integrations (stubs).
templates/                      # PHP template files.
assets/                         # CSS, JS, and images.
src/                            # Source for the wp-scripts build (editor panels, reactions block, connectors card, settings typeahead).
├── editor-plugin/              # Document sidebar share-to-Bluesky panel.
├── pre-publish-panel/          # Pre-publish share status panel.
├── reactions/                  # Bluesky likes/reposts block.
├── connectors-card/            # Connectors screen connect/disconnect card.
├── settings-connect/           # Settings page connect-field typeahead enhancement.
└── shared/                     # Shared JS reused across the above (incl. the handle typeahead component).
tests/
└── phpunit/                    # PHPUnit tests.
```

## Commands

```bash
# Environment
npm run env-start               # Start WordPress at http://localhost:8884.
npm run env-stop                # Stop WordPress environment.

# PHP tests (require wp-env running)
npm run env-test                            # All PHP tests.
npm run env-test -- --filter=pattern        # Tests matching pattern.

# Local tests (require MySQL)
composer test                               # Full test suite.
vendor/bin/phpunit --filter=pattern         # Matching tests.

# Code quality
composer lint                   # Check PHP coding standards (PHPCS).
composer lint:fix               # Auto-fix PHP issues.

# Changelog
composer changelog:add          # Add a changelog entry.
composer changelog:write        # Write entries to CHANGELOG.md.

# Release
npm run release                 # Interactive release: bumps version, regenerates CHANGELOG/readme.txt, pushes a release/X.Y.Z PR.
```

## Common Pitfalls

- **Custom autoloader, NOT Composer.** Runtime classes are loaded by `includes/class-autoloader.php` (PSR-4-ish with WordPress `class-{name}.php` filenames); `composer.json`'s `autoload` is empty. `composer install`/`update` only refreshes dev dependencies (PHPUnit, PHPCS, Changelogger) and cannot break the running plugin. New classes work as soon as the file is in place — no `composer dump-autoload` step.
- **Changelog entries MUST be end-user friendly and end with punctuation.** Users see these in the WordPress update screen. Describe what changed from their perspective — no jargon, class names, or method names.

## PHP Conventions

Files: `class-{name}.php`

Namespaces: `Atmosphere`, `Atmosphere\{OAuth,Transformer,WP_Admin}`

Text domain: always `'atmosphere'`.

**MUST** backslash-prefix all WordPress/PHP global functions in namespaced code: `\get_option()`, `\add_action()`, `\apply_filters()`, `\strlen()`, `\time()`, etc.

**MUST** use `use` imports for cross-namespace references — no inline `\Namespace\Class`.

**MUST** build any front-end-displayed Bluesky/appview web link (`profile`, `post`, `hashtag`, `mention`) through `Atmosphere\appview_url( $path, $context )` in `includes/functions.php` — never hardcode `https://bsky.app/...`. The helper centralizes the host and makes it filterable (`atmosphere_appview_host` / `atmosphere_appview_url`). Keep escaping at the call site (`\esc_url()` for HTML, `\esc_url_raw()` for storage); the helper returns an unescaped URL on purpose. This applies to display links only, not to AT Protocol records being published.

## Autoloading

Uses the custom `Atmosphere\Autoloader` in `includes/class-autoloader.php`, which respects WordPress filename conventions (`class-foo.php`, lowercase, hyphenated). `composer.json` declares an empty `autoload` block — Composer is only used for dev tooling (PHPUnit, PHPCS, Changelogger). Helper functions in `includes/functions.php` are loaded via a direct `require_once` from `atmosphere.php`.

## Testing Conventions

Tests use namespace `Atmosphere\Tests` and extend `WP_UnitTestCase`. Use `@group` annotations to categorize tests (`@group atmosphere`, `@group transformer`).

Test files live in `tests/phpunit/tests/` mirroring `includes/` structure. Files are prefixed `class-test-`.

## Architectural Patterns

**Transformers** — Convert WordPress content into AT Protocol records. Extend `Atmosphere\Transformer\Base`. Each transformer defines `transform()`, `get_collection()`, and `get_rkey()`. See `includes/transformer/`.

**OAuth** — Full PKCE + DPoP + PAR native OAuth flow. Handle → DID → PDS → Auth Server resolution chain. See `includes/oauth/`.

**API Client** — DPoP-authenticated PDS requests with automatic nonce retry. See `includes/class-api.php`.

**Publisher** — Atomic batch `applyWrites` for both bsky post + standard.site document. See `includes/class-publisher.php`.

**Well-known endpoints** — Rewrite rules + `template_redirect` handlers in `Atmosphere` class serve `/.well-known/atproto-did` (domain handle verification) and `/.well-known/site.standard.publication` (publication AT-URI). All share the `atmosphere_wellknown` query var.

**Connectors API** — Progressive-enhancement registration on the WordPress 7.0 Settings → Connectors screen. `Atmosphere\Connectors` (`includes/class-connectors.php`), guarded throughout by `class_exists( 'WP_Connector_Registry' )`.

- **Registration & card.** Registers an `atmosphere` connector on `wp_connectors_init` with `authentication.method => 'none'` (core only auto-renders a UI for `api_key` connectors) and ships a script module (`src/connectors-card/`) that renders the connect/disconnect card. The card drives the flow through `Atmosphere\Rest\Admin\Connection_Controller` (authorize/disconnect), reusing the OAuth `Client`.
- **Two screen URLs.** Core's top-level `options-connectors.php` (`Connectors::SCREEN`) vs. the Gutenberg plugin's Settings submenu (`options-general.php?page=options-connectors-wp-admin`). Both matched via the shared `Connectors::SCREEN_SLUG` marker: `Connectors::is_connectors_screen()` gates the card enqueue; `Connectors::screen_url()` resolves the OAuth return destination.
- **Return destination is server-side only.** The card sets a boolean `atmosphere_oauth_from_connectors` transient — no URL crosses the wire. `Admin::handle_oauth_callback()` derives the destination from `screen_url()` (which reads the registered admin `$submenu`), so nothing external can steer the `wp_safe_redirect()`.
- **Handle typeahead.** The card's handle field uses the `atmosphere_handle_typeahead_url` filter (default `public.api.bsky.app`; `''` disables). Shared with the plugin's own Settings connect field via `Atmosphere\handle_typeahead_url()` and `src/shared/handle-typeahead.js`.

**Icon library** — Progressive-enhancement registration with the WordPress 7.1 icon library. `Atmosphere\Icons` (`includes/class-icons.php`), gated on `function_exists( 'wp_register_icon_collection' )` (same shape as Connectors; the plugin's floor is 6.5). Registers the `atmosphere` collection with the ATmosphere mark and the Bluesky butterfly, monochrome, from `assets/svg/`. Those SVGs MUST stay within the library sanitizer's allow-list (`svg`/`path`/`polygon` elements only, no `stroke`); a test pins that property, so redraw assets accordingly.

**Embedding as a connection layer** — one filter lets a host plugin reuse just the connection:

- **`atmosphere_connection_only_mode`** (via `Atmosphere\is_connection_only_mode()`, default `false`) forces cross-posting, reaction sync, reply sync, comment publishing, and `site.standard.publication` upkeep **off**, unschedules the reaction-sync crons, and hides the plugin's own settings page. The settings page stays *registered* (`add_options_page`) because the OAuth callback lands on its URL, but drops from the menu. The reauth notice still renders in this mode, pointing its reconnect link at the Connectors screen when one exists.
- **Layered gating.** Each *behavior* resolves through a dedicated helper (`is_auto_publish_enabled()`, `is_reaction_sync_enabled()`, `is_reply_sync_enabled()`, `is_comment_publishing_enabled()`, `is_publication_sync_enabled()`): read the stored option (via the shared `feature_option_enabled()` resolver, except publication which has no user option) → force off in connection-only mode → apply a per-feature filter **last** (`atmosphere_should_auto_publish`, `atmosphere_should_sync_publication`, etc.), so a host can re-enable a single lane. Settings-page visibility (`Admin::is_settings_page_visible()`) has no separate override — it simply follows connection-only mode. All behavioral call sites route through these helpers; only the settings form fields read the raw option.

## Documentation Index

```
README.md                          — public-facing repo entry point (lean: intro + docs links).
readme.txt                         — WordPress.org plugin readme (end-user friendly description, FAQ, changelog).

docs/developer-docs.md             — developer entry doc; index over the rest of docs/.
docs/development-environment.md    — wp-env setup, prerequisites, troubleshooting, coverage.
docs/php-coding-standards.md       — naming, escaping, error handling, performance, cron rules.
docs/php-class-structure.md        — directory layout, namespaces, architectural patterns.
docs/code-linting.md               — PHPCS rules and common fixes.
docs/pull-request.md               — branching, pre-PR checklist, commit format, special situations.
docs/release-process.md            — `npm run release`, patch releases, GitHub Release UI.
docs/translations.md               — text domain, GlotPress, translator-friendly strings.
docs/content-formats.md            — survey of AT Protocol `content` types for site.standard.document.
docs/org.wordpress.html.md         — Lexicon for the org.wordpress.html content type.

integrations/README.md             — registering custom Content_Parser implementations from third-party plugins.
.github/PULL_REQUEST_TEMPLATE.md   — PR template (changelog block + testing instructions).
```

The skills under `.agents/skills/` are quick-references that link into these docs. Update the docs when conventions change; the skills inherit the change.

## Release Process

```bash
npm run release
```

The release script (`bin/release.js`) does all of the version bookkeeping in one step:

1. Runs `composer changelog:write` to roll up `.github/changelog/` entries into `CHANGELOG.md` (the next semver version is inferred from the entries' significance).
2. Creates a `release/X.Y.Z` branch.
3. Updates the version in `atmosphere.php` (header + `ATMOSPHERE_VERSION`), `readme.txt` (`Stable tag`), and `package.json`.
4. Mirrors the new changelog section into `readme.txt` (same major-version history, with a link to the full GitHub `CHANGELOG.md`).
5. Replaces `@since unreleased` / `@deprecated unreleased` and the equivalent `_deprecated_*` / `_doing_it_wrong` literals in PHP files with the new version.
6. Optionally prompts for an Upgrade Notice.
7. Pushes the branch and opens a PR titled `Release X.Y.Z` against `trunk`.

The `.gitattributes` file controls what's excluded from `git archive` exports and GitHub release tarballs.

## Skills and Agents

Skills are complex procedures loaded on demand. Canonical files live in `.agents/skills/`; `.claude/skills/*/SKILL.md` are 1-line stubs that point at them so both Claude Code and other agents pick up the same instructions.

| Skill | Use when… |
|-------|-----------|
| **code-style** | Writing PHP code, creating classes, implementing hooks. |
| **dev** | Setting up environment, running tests, linting. |
| **test** | Writing tests, debugging failures, test patterns. |
| **pr** | Creating or reviewing pull requests. MUST invoke before any PR creation. |
| **release** | Creating releases, bumping versions, managing changelogs. |

| Agent | Trigger |
|-------|---------|
| **code-review** | Auto-invoked before PR creation to review changes. |
| **spec-check** | Audit against AT Protocol and standard.site Lexicon specs. |
| **security-audit** | Audit for OAuth bypass, SSRF, token leakage, XSS, and DPoP issues. |
