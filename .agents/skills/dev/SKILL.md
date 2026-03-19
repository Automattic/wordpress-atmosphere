---
name: dev
description: Development workflows for WordPress ATmosphere plugin including wp-env setup, testing commands, linting, and build processes. Use when setting up development environment, running tests, checking code quality, or working with wp-env.
---

# ATmosphere Development Cycle

Quick reference for common development workflows.

## Quick Reference

### Environment Management
```bash
npm run env-start    # Start WordPress at http://localhost:8884.
npm run env-stop     # Stop WordPress environment.
```

### Testing Commands
```bash
npm run env-test                      # Run all PHP tests.
npm run env-test -- --filter=pattern  # Run tests matching pattern.
composer test                         # Run tests locally (needs MySQL).
vendor/bin/phpunit --filter=pattern   # Run matching tests locally.
```

### Code Quality
```bash
composer lint         # Check PHP coding standards.
composer lint:fix     # Auto-fix PHP issues.
```

### Autoloader
```bash
composer dump-autoload    # Regenerate classmap after adding/renaming classes.
```

### Release
```bash
bin/release.sh [version]  # Build release ZIP with production deps only.
```

## Common Development Workflows

### Initial Setup
```bash
npm install           # Installs @wordpress/env.
composer install      # Installs PHP dependencies.
npm run env-start     # WordPress at http://localhost:8884.
```

### Making Changes Workflow
```bash
# 1. Make code changes.

# 2. If you added/renamed a class file:
composer dump-autoload

# 3. Run relevant tests.
npm run env-test -- --filter=FeatureName

# 4. Check code quality.
composer lint

# 5. Commit.
git add .
git commit -m "Description"
```

### Before Creating PR
```bash
# Run full test suite.
npm run env-test

# Final lint check.
composer lint
```

### Debugging Failing Tests
```bash
# Run with verbose output.
npm run env-test -- --verbose --filter=test_name

# Stop on first failure.
npm run env-test -- --stop-on-failure
```

## Key Files

- `package.json` - Node dependencies (wp-env) and environment scripts.
- `composer.json` - PHP dependencies, autoloading, lint/test scripts.
- `.wp-env.json` - wp-env configuration.
- `phpcs.xml` - PHP coding standards.
- `phpunit.xml.dist` - PHPUnit configuration.
- `.gitattributes` - Release archive exclusions.
