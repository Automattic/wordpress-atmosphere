---
name: test
description: Testing patterns for PHPUnit tests. Use when writing tests, debugging test failures, setting up test coverage, or implementing test patterns for ATmosphere features.
---

# ATmosphere Testing

## Quick Reference

### Key Commands
- **All tests:** `npm run env-test`
- **Single test:** `npm run env-test -- --filter=test_name`
- **Local (needs MySQL):** `composer test`

## PHPUnit Testing

### Test Structure

```php
<?php
namespace Atmosphere\Tests;

use WP_UnitTestCase;

class Test_Feature extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        // Setup.
    }

    public function tear_down(): void {
        // Cleanup.
        parent::tear_down();
    }

    public function test_functionality() {
        // Test implementation.
    }
}
```

### Test File Location

Tests live in `tests/phpunit/tests/` with a directory structure mirroring `includes/`:

```
tests/phpunit/tests/
├── class-test-functions.php
├── transformer/
│   ├── class-test-tid.php
│   ├── class-test-facet.php
│   └── class-test-publication.php
└── ...
```

Test files are prefixed with `class-test-` and the class name is `Test_` + feature name.

### Test Groups

Use `@group` annotations:
```php
/**
 * @group atmosphere
 * @group transformer
 */
public function test_transformer_feature() {
    // Test code.
}
```

### HTTP Requests in Tests

All HTTP requests are disabled by default in the bootstrap. To allow specific requests:
```php
add_filter( 'tests_allow_http_request', function( $allow, $args, $url ) {
    if ( str_contains( $url, 'expected-domain.com' ) ) {
        return true;
    }
    return $allow;
}, 10, 3 );
```

## Debugging Tests

```bash
# Run with verbose output.
npm run env-test -- --verbose --debug

# Stop on first failure.
npm run env-test -- --stop-on-failure

# Run single test method.
npm run env-test -- --filter=test_specific_method
```
