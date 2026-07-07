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

Tests live in `tests/phpunit/tests/`, mirroring `includes/` (run `ls -R tests/phpunit/tests` for the current listing):

```
tests/phpunit/tests/
├── class-test-*.php        # Core classes. class-test-publisher.php is the largest fixture;
│                           # it exposes register_capture() / $captured_calls / $fail_call_indexes.
├── cli/                    # WP-CLI command tests.
├── content-parser/         # Parser tests + Fake_Parser / Minimal_Parser stubs + trait-block-fixtures.php.
├── oauth/                  # Client authorize/refresh, encryption, resolver, redirect-uri filter.
├── rest/                   # REST controllers (admin/ for the pre-publish controller).
├── transformer/            # One test file per transformer + class-stub-parser.php.
└── wp-admin/               # Settings, notices, sanitize callbacks.
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

## Common Fixtures

```php
// Connected-account fixture — get_did() etc. read the identity option.
\update_option( 'atmosphere_identity', array( 'did' => 'did:plc:test123' ), false );
// Delete it in tear_down().

// Stub blob uploads (thumbnails, icons) without HTTP — records the attempt too.
$attempted     = false;
$short_circuit = static function () use ( &$attempted ) {
	$attempted = true;
	return array( 'blob' => array( 'cid' => 'bafytest' ) );
};
\add_filter( 'atmosphere_pre_upload_blob', $short_circuit );

// HTTP tripwire — fail loudly if any code path fires a real request.
$fetched  = false;
$tripwire = static function ( $preempt ) use ( &$fetched ) {
	$fetched = true;
	return $preempt;
};
\add_filter( 'pre_http_request', $tripwire, 1, 1 );
// ...exercise...
$this->assertFalse( $fetched, 'No HTTP request should be made.' );
```

Asserting a path is read-only? Pair the tripwire with a meta assertion (e.g. `Document::META_TID` still empty) — GET-serving preview paths must not write publish-state meta.

## Stubbing `applyWrites` calls

The Publisher test fixture (`Test_Publisher`) exposes `register_capture()` plus `$captured_calls` / `$fail_call_indexes` for asserting on the `writes` batch and forcing per-call failures:

```php
$this->fail_call_indexes = array(
    2 => new \WP_Error( 'atmosphere_pds_500', 'PDS rejected.' ),
);
$this->register_capture( $post_id );
// ...exercise...
$this->assertCount( 3, $this->captured_calls );
```

Outside the Publisher test, hook the `atmosphere_pre_apply_writes` filter directly (see Publisher::apply_writes — short-circuits before the HTTP layer, so DPoP-less test environments work).

## Simulating in-flight races

To reproduce a "state changed during the API call" race in tests, mutate the WP state from inside the `atmosphere_pre_apply_writes` filter callback and return a synthetic 2xx response. The plugin's hooks fire synchronously in the test process — the filter callback is the analogue of "the API call took long enough for another request to land".

```php
\add_filter(
    'atmosphere_pre_apply_writes',
    static function ( $short, $writes ) use ( $comment_id ) {
        \wp_set_comment_status( $comment_id, 'hold' );
        return array( 'results' => array( /* synthetic */ ) );
    },
    10,
    2
);
```

Note: `wp_delete_comment( $id, true )` removes commentmeta synchronously, which can erase TIDs the reconcile path needs. Prefer status transitions (`hold`, `spam`) when possible.

## Cron handlers in tests

The plugin's `register_async_hooks()` runs at `plugins_loaded` (via the bootstrap), so cron handlers ARE registered before tests execute. Use `\do_action( 'atmosphere_publish_comment', $comment_id )` to fire a handler synchronously; assert on `\wp_next_scheduled()` for follow-up scheduling.

Always clean up scheduled hooks in `tear_down()` — leftover events from one test become flaky preconditions for the next.

## See Also

- [`docs/development-environment.md`](../../../docs/development-environment.md) — PHPUnit argument reference, coverage reports, troubleshooting wp-env.
- [`docs/php-coding-standards.md → Cron-Specific Rules`](../../../docs/php-coding-standards.md#cron-specific-rules) — the publish/update/delete patterns the tests are exercising.
