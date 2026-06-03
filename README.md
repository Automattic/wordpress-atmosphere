# ATmosphere

This is the **ATmosphere** plugin repo.

Publish your WordPress posts to the [AT Protocol](https://atproto.com/) network — cross-post to [Bluesky](https://bsky.social/), register your articles as [standard.site](https://standard.site/) documents on your PDS, use your domain as your Bluesky handle, and mirror replies and reposts back into WordPress as native comments.

## Documentation

- [Developer Documentation](docs/developer-docs.md) — entry point for developers extending or integrating with ATmosphere.
- [Development Environment](docs/development-environment.md) — wp-env setup, prerequisites, troubleshooting, coverage.
- [PHP Coding Standards](docs/php-coding-standards.md) — naming, escaping, error handling, performance, cron rules.
- [Class Structure](docs/php-class-structure.md) — directory layout, namespaces, architectural patterns.
- [Code Linting](docs/code-linting.md) — PHPCS rules and common fixes.
- [Pull Request Guide](docs/pull-request.md) — branching, pre-PR checklist, commit format.
- [Release Process](docs/release-process.md) — `npm run release`, patch releases, GitHub Release UI.
- [Translations](docs/translations.md) — text domain, GlotPress, translator-friendly strings.
- [Content Formats](docs/content-formats.md) — AT Protocol `content` types for `site.standard.document`.
- [`org.wordpress.html` Lexicon](docs/org.wordpress.html.md) — the rendered-HTML content type schema.
- [Integrations](integrations/README.md) — registering custom `Content_Parser` implementations.
- [Contributor instructions](AGENTS.md) — directory structure, commands, conventions, and skills/agents.

## Protocol Support

ATmosphere implements the AT Protocol natively — no third-party proxy or intermediary service. Authentication uses OAuth 2.1 with PKCE and DPoP. Records are written to your PDS via `com.atproto.repo.applyWrites`.

Currently supported record types:

- `app.bsky.feed.post` — Bluesky posts and reply threads.
- `site.standard.publication` — your site as a publication record.
- `site.standard.document` — one document record per WordPress post.

See [`docs/content-formats.md`](docs/content-formats.md) for more on the content union used by `site.standard.document`.

## Support

If you need help, [check the issue tracker on GitHub](https://github.com/Automattic/wordpress-atmosphere/issues) or open a new bug report.

## Contribute

Contributions are welcome — bug fixes, new features, integrations, translations.

* **Keep issues focused.** Use the issue tracker for specific bugs or concrete proposals. Tangential ideas are best as GitHub Discussions.
* **Stay within scope.** Open issues should relate directly to the plugin.
* **Be concise.** Short, actionable descriptions are easier to respond to.

Before opening a pull request, please read [`AGENTS.md`](AGENTS.md) for directory layout, coding conventions, and the release workflow.

## Security

Found a security issue? Report it via [Automattic's security page](https://automattic.com/security/) or our HackerOne bug-bounty program at https://hackerone.com/automattic.

## License

ATmosphere is licensed under the [GPL v2 or later](LICENSE).
