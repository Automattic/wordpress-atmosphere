---
name: dev
description: Development workflows for WordPress ATmosphere plugin including wp-env setup, testing commands, linting, and build processes. Use when setting up development environment, running tests, checking code quality, or working with wp-env.
---

# ATmosphere Development Cycle

Quick reference for everyday workflows. Full reference: [`docs/development-environment.md`](../../../docs/development-environment.md).

## Core Commands

```bash
# Environment.
npm run env-start            # WordPress at http://localhost:8884.
npm run env-stop

# Tests.
npm run env-test                            # All PHP tests.
npm run env-test -- --filter=pattern        # Subset by name.
npm run env-test -- --group=name            # Subset by @group.
composer test                               # Local (needs MySQL).

# Code quality.
composer lint                # PHPCS check.
composer lint:fix            # PHPCS auto-fix.
composer dump-autoload       # Regenerate classmap after adding/renaming classes.

# Release.
npm run release              # Interactive release script. See the release skill.
```

## Default URLs

- Frontend: http://localhost:8884
- Admin: http://localhost:8884/wp-admin — user `admin`, password `password`.
- Tests instance: http://localhost:8885

## Typical Loop

```bash
# 1. Make changes.
# 2. If a class file was added/renamed:
composer dump-autoload

# 3. Run relevant tests + lint.
npm run env-test -- --filter=FeatureName
composer lint

# 4. Commit.
```

## WP-CLI Inside wp-env

```bash
npm run env -- run cli wp plugin list
npm run env -- run cli wp option get atmosphere_settings
npm run env -- run cli wp transient delete --all
npm run env -- run cli wp db cli
```

## When to Read the Full Docs

Read [`docs/development-environment.md`](../../../docs/development-environment.md) when you need:

- **Prerequisites** (Node version, Docker setup, Composer, `gh` CLI).
- **Troubleshooting** (Docker not running, port conflicts, DB connection errors, slow performance on macOS).
- **Code coverage** (Xdebug coverage mode, HTML coverage reports).
- **PHPUnit argument reference** (every flag we use).
- **wp-env configuration** (overriding PHP / WP versions, mounts, custom ports).

## Related Skills

- **code-style** — PHP conventions, hooks, security, error handling.
- **test** — PHPUnit patterns, the Publisher capture fixture, simulating in-flight races.
- **pr** — pre-PR checklist, commit format, special situations.
- **release** — `npm run release`, patch releases.
