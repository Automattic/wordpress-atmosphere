# Development Environment Setup

## Overview

This guide walks through setting up a local development environment for the ATmosphere WordPress plugin. You'll clone the repo, install dependencies, run a local WordPress instance, and verify the test suite.

## Table of Contents
- [Prerequisites](#prerequisites)
- [Getting Started](#getting-started)
- [wp-env Configuration](#wp-env-configuration)
- [Docker Management](#docker-management)
- [Running Tests](#running-tests)
- [Code Coverage](#code-coverage)
- [Troubleshooting](#troubleshooting)
- [Environment Variables](#environment-variables)

## Prerequisites

### Required Software

1. **Node.js** (v18 or later)
   ```bash
   node --version
   ```

2. **npm** (ships with Node.js)
   ```bash
   npm --version
   ```

3. **Docker Desktop** — backs `wp-env`. Must be running before any `npm run env-*` command.
   - [Download Docker Desktop](https://www.docker.com/products/docker-desktop)

4. **Composer** — PHP dependencies, lint, tests, Jetpack Changelogger.
   ```bash
   # macOS via Homebrew.
   brew install composer

   # Or the official installer.
   curl -sS https://getcomposer.org/installer | php
   ```

5. **Git** with SSH key setup
   - [GitHub's SSH key guide](https://docs.github.com/en/authentication/connecting-to-github-with-ssh)
   ```bash
   ssh -T git@github.com
   ```

6. **`gh` CLI** — required by `npm run release`.
   ```bash
   gh --version
   ```

## Getting Started

### Clone the Repository

```bash
git clone git@github.com:Automattic/wordpress-atmosphere.git
cd wordpress-atmosphere
```

### Install Dependencies

```bash
# JavaScript dependencies.
npm install

# PHP dependencies.
composer install
```

### Start the Development Environment

```bash
npm run env-start
```

This boots WordPress with the ATmosphere plugin installed and activated.

### Access WordPress

- **Frontend:** http://localhost:8884
- **Admin:** http://localhost:8884/wp-admin
- **Username:** `admin`
- **Password:** `password`
- **Tests instance:** http://localhost:8885

### Stop the Environment

```bash
npm run env-stop
```

## wp-env Configuration

### Default Configuration

`wp-env` reads `.wp-env.json`. The defaults shipped with ATmosphere mount the plugin, set ports `8884` / `8885`, and force `WP_ENVIRONMENT_TYPE=production` for the tests instance so production code paths are exercised.

### Common Commands

```bash
npm run env-start            # Start.
npm run env-stop             # Stop.
npm run env-test             # Run PHPUnit inside wp-env.

npm run env -- <wp-env-args> # Pass through any wp-env subcommand.

# WP-CLI inside the container.
npm run env -- run cli wp plugin list
npm run env -- run cli wp option get atmosphere_settings
npm run env -- run cli wp transient delete --all
npm run env -- run cli wp db cli   # MySQL shell.
```

### Multiple WordPress / PHP Versions

Override in `.wp-env.json`:

```json
{
  "core": "WordPress/WordPress#6.7",
  "phpVersion": "8.3"
}
```

## Docker Management

```bash
# List running containers.
docker ps

# View container logs.
docker logs $(docker ps -q --filter name=wordpress)

# Shell into the WordPress container.
docker exec -it $(docker ps -q --filter name=wordpress) bash

# Resource usage.
docker system df
docker system prune -a   # Reclaim space.
```

### Ports

| Service | Port |
|---------|------|
| WordPress | 8884 |
| Tests WordPress | 8885 |
| MySQL | Random external port (see `docker ps`) |

Override in `.wp-env.json` if 8884/8885 are in use:

```json
{ "port": 8886, "testsPort": 8887 }
```

## Running Tests

```bash
npm run env-start
npm run env-test                                # All tests.
npm run env-test -- --filter=pattern            # Tests matching a name pattern.
npm run env-test -- --group=transformer         # Tests with @group transformer.
npm run env-test -- tests/phpunit/tests/transformer/class-test-post.php
```

Common PHPUnit flags:

- `--filter=pattern` — only tests matching the name regex.
- `--group=name` — only tests tagged `@group name`.
- `--exclude-group=name` — skip a group.
- `--stop-on-failure` — stop at the first failure.
- `--verbose` — more diagnostics.
- `--debug` — print test names as they run (useful when a test hangs).

For test-writing patterns (fixtures, the Publisher capture filter, in-flight race simulation), see the **test** skill.

### Local (without wp-env)

If you have a local MySQL and the `WP_TESTS_*` env vars configured, you can run the suite without Docker:

```bash
composer test
vendor/bin/phpunit --filter=pattern
```

## Code Coverage

Restart `wp-env` with Xdebug in coverage mode:

```bash
npm run env-start -- --xdebug=coverage
```

Then run tests with a coverage flag:

```bash
npm run env-test -- --coverage-text                  # Terminal summary.
npm run env-test -- --coverage-html ./coverage       # Full HTML report.
open coverage/index.html                              # macOS.
```

`phpunit.xml.dist` scopes coverage to `includes/`.

## Troubleshooting

### "Docker is not running"

```bash
open -a Docker          # macOS.
# Wait for the daemon to start, then retry.
npm run env-start
```

### Port already in use

The `env-start` script passes `--auto-port`, so `wp-env` normally picks a free port automatically. If it still fails:

```bash
lsof -i :8884
kill -9 <PID>
# Or pick a different port.
npm run env-start -- --port=8886
```

### "Error establishing database connection"

```bash
npm run env-stop
npm run env-start
# Still broken? Clear dangling containers and try again.
docker system prune -f
```

### Plugin not activated in the test environment

```bash
npm run env -- run cli wp plugin activate atmosphere
npm run env -- run cli wp plugin list --status=active
```

### Slow performance on macOS

- Increase Docker CPU/RAM in Docker Desktop → Settings → Resources.
- Enable VirtioFS in Docker Desktop → Settings → General.
- Exclude `node_modules`, `vendor`, `.git` from Spotlight / Time Machine watchers.

### Verbose wp-env output

```bash
DEBUG=wp-env:* npm run env-start
```

## Environment Variables

`wp-env` understands these:

- `WP_ENV_HOME` — wp-env home directory.
- `WP_ENV_PORT` — WordPress port.
- `WP_ENV_TESTS_PORT` — tests port.

## Next Steps

- [PHP Coding Standards](php-coding-standards.md) — naming, escaping, error handling, performance.
- [Class Structure](php-class-structure.md) — directory layout and architectural patterns.
- [Code Linting](code-linting.md) — PHPCS rules and how to fix common findings.
- [Pull Request Guide](pull-request.md) — branch naming, checklists, commit format.
- [Release Process](release-process.md) — `npm run release` and patch-release workflow.
