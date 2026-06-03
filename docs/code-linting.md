# Linting and Code Quality

## Table of Contents
- [PHP Code Standards](#php-code-standards)
- [Running Targeted Checks](#running-targeted-checks)
- [Fixing Common Issues](#fixing-common-issues)
- [Project-Specific Rules](#project-specific-rules)

## PHP Code Standards

### Quick Commands

```bash
# Check PHP code standards across the project.
composer lint

# Auto-fix what can be fixed.
composer lint:fix

# Check or fix a specific file or directory.
vendor/bin/phpcs path/to/file.php
vendor/bin/phpcbf path/to/file.php
vendor/bin/phpcs includes/transformer/
```

### PHPCS Configuration

The ruleset is defined in `phpcs.xml`:

| Standard | Purpose |
|----------|---------|
| **WordPress** | Full WordPress Coding Standards (Core + Docs + Extra). |
| **PHPCompatibility** | PHP 8.2+ compatibility (matches `Requires PHP` in `atmosphere.php`). |
| **PHPCompatibilityWP** | WordPress 6.2+ compatibility (matches `Requires at least` in `readme.txt`). |
| **VariableAnalysis** | Flags undefined or unused variables. |

**Excluded paths:**

- `vendor/` — Composer dependencies.
- `node_modules/` — npm dependencies.
- `tests/phpunit/data/` — fixture files (if any are added).
- `.wordpress-org/` — WordPress.org repo assets.

For the full coding standards reference (file headers, naming, escaping, error handling, performance), see [PHP Coding Standards](php-coding-standards.md).

**Important:** All DocBlock descriptions must end with a period.

## Running Targeted Checks

### Files changed on the current branch

```bash
git diff --name-only origin/trunk...HEAD | grep '\.php$' | xargs vendor/bin/phpcs
```

### A single directory

```bash
vendor/bin/phpcs includes/oauth/
```

### Generate a report

```bash
vendor/bin/phpcs --report=summary
vendor/bin/phpcs --report=full > phpcs-report.txt
vendor/bin/phpcs --report=json > phpcs-report.json
```

## Fixing Common Issues

### "Missing file comment"

```php
<?php
/**
 * {Feature} class file.
 *
 * @package Atmosphere
 */
```

### "Unused use statement"

```php
// Remove or actually use the import.
use Unused\Class;  // ← remove this.
```

### "Expected 1 space after closing parenthesis"

```php
// Bad.
if ($condition){

// Good.
if ( $condition ) {
```

### "Array double arrow not aligned"

PHPCS expects double-arrows aligned when adjacent keys differ in length:

```php
// Bad.
$array = array(
    'short' => 1,
    'longer_key' => 2,
);

// Good.
$array = array(
    'short'      => 1,
    'longer_key' => 2,
);
```

`composer lint:fix` handles this automatically — keep using it.

### "Use of [] short array syntax forbidden"

WordPress standards require `array()`:

```php
// Bad.
$items = [ 'a', 'b' ];

// Good.
$items = array( 'a', 'b' );
```

### "Loose comparison"

```php
// Bad.
if ( $value == 'something' ) {

// Good — Yoda + strict.
if ( 'something' === $value ) {
```

### "Output not escaped"

```php
// Bad.
echo $value;

// Good.
echo \esc_html( $value );
echo \esc_html__( 'Translatable', 'atmosphere' );
```

See [PHP Coding Standards → Security Practices](php-coding-standards.md#security-practices) for the full sanitization / escaping reference.

### "Direct database query"

If you must run one, prepare it:

```php
$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
```

And annotate with a reason if WPCS can't infer safety:

```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Required for cross-table join on a large dataset.
```

## Project-Specific Rules

### Text Domain

Always `'atmosphere'`:

```php
\__( 'Text', 'atmosphere' );
\esc_html_e( 'Text', 'atmosphere' );
```

### Namespace Pattern

```php
namespace Atmosphere;
namespace Atmosphere\OAuth;
namespace Atmosphere\Transformer;
namespace Atmosphere\Content_Parser;
namespace Atmosphere\WP_Admin;
```

### Backslash-Prefix Globals

In namespaced code, WordPress and PHP global functions are explicitly backslash-prefixed:

```php
\get_option( 'atmosphere_settings' );
\apply_filters( 'atmosphere_should_publish_comment', $bool, $comment );
\is_wp_error( $result );
\strlen( $body );
```

This is a project convention — PHP falls back to global scope anyway, but the explicit backslash makes global-vs-namespaced calls scannable and prevents accidental shadowing.

### File Naming

```
class-{name}.php         # Regular classes.
trait-{name}.php         # Traits.
interface-{name}.php     # Interfaces (e.g. interface-content-parser.php).
```

### Ignored Files

PHPCS skips:

- `vendor/` — Composer dependencies.
- `node_modules/` — npm dependencies.
- `.wordpress-org/` — wp.org repo assets (banner, icon).

## See Also

- [PHP Coding Standards](php-coding-standards.md) — naming, security, error handling, performance.
- [Class Structure](php-class-structure.md) — directory layout, architectural patterns.
- [Pull Request Guide](pull-request.md) — when these checks must pass.
