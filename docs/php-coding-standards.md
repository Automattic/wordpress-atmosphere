# PHP Coding Standards Reference

## Table of Contents
- [WordPress Coding Standards](#wordpress-coding-standards)
- [File Organization](#file-organization)
- [Naming Conventions](#naming-conventions)
- [Namespaces and Imports](#namespaces-and-imports)
- [Hook Patterns](#hook-patterns)
- [Documentation Standards](#documentation-standards)
- [Security Practices](#security-practices)
- [Performance Considerations](#performance-considerations)
- [Error Handling](#error-handling)
- [Cron-Specific Rules](#cron-specific-rules)

## WordPress Coding Standards

The ATmosphere plugin follows the WordPress Coding Standards (WPCS) with the following PHPCS configuration:

| Standard | Purpose |
|----------|---------|
| **WordPress** | Full WordPress Coding Standards. |
| **PHPCompatibility** | PHP 8.2+ compatibility (matches `Requires PHP` in `atmosphere.php`). |
| **PHPCompatibilityWP** | WordPress 6.2+ compatibility (matches `Requires at least` in `readme.txt`). |
| **VariableAnalysis** | Flags undefined or unused variables. |

The full ruleset lives in `phpcs.xml`. Run `composer lint` to check and `composer lint:fix` to auto-fix what can be fixed.

### Indentation and Spacing

```php
// Tabs for indentation.
function example_function() {
→   $variable = 'value';
→   if ( $condition ) {
→   →   do_something();
→   }
}

// Spaces inside parentheses.
if ( $condition ) {       // Correct.
if ($condition) {         // Incorrect.

// Spaces around operators.
$sum = $a + $b;

// Use array() — not the short syntax [].
$array = array(
→   'key_one'   => 'value',
→   'key_two'   => 'value',
→   'key_three' => 'value',
);
```

### Control Structures

```php
if ( $condition ) {
→   // Code.
} elseif ( $other_condition ) {
→   // Code.
} else {
→   // Code.
}

switch ( $variable ) {
→   case 'value1':
→   →   do_something();
→   →   break;

→   case 'value2':
→   →   do_something_else();
→   →   break;

→   default:
→   →   do_default();
}

foreach ( $items as $key => $item ) {
→   process_item( $item );
}
```

### Yoda Conditions

Yoda conditions are preferred for value-against-variable comparisons, to prevent accidental assignment:

```php
if ( 'value' === $variable ) {
if ( true === $condition ) {
if ( null !== $result ) {
```

Readable conditions (no value-on-the-left to flip) are fine without Yoda:

```php
if ( $user->has_cap( 'edit_posts' ) ) {
if ( \is_array( $data ) ) {
```

## File Organization

### File Naming Patterns

```
class-{name}.php         # Regular classes.
trait-{name}.php         # Traits.
interface-{name}.php     # Interfaces (e.g. interface-content-parser.php).
functions.php            # Global functions.
```

### File Header Template

```php
<?php
/**
 * {Feature} class file.
 *
 * @package Atmosphere
 * @subpackage {Component}
 * @since {version}
 */

namespace Atmosphere\{Component};

use Atmosphere\Other\Class;
use WP_Error;

/**
 * {Feature} Class.
 *
 * Handles {what the class does}.
 *
 * @since {version}
 */
class {Feature} {
```

Use the literal `unreleased` for `@since` and `@deprecated` markers on new code — the release script rewrites them at release time.

## Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| **Classes** | `Pascal_Snake_Case` | `class Reaction_Sync` |
| **Methods** | `snake_case` | `public function get_rkey()` |
| **Functions** | `snake_case` | `function to_iso8601()` |
| **Properties** | `snake_case` | `private $access_token` |
| **Constants** | `UPPER_SNAKE_CASE` | `const META_TID = '_atmosphere_bsky_tid';` |
| **Hooks** | `snake_case`, `atmosphere_` prefix | `\apply_filters( 'atmosphere_should_publish_comment', … );` |
| **Files** | `hyphen-case`, `class-` prefix | `class-reaction-sync.php` |
| **Namespaces** | `PascalCase`, one segment per directory | `namespace Atmosphere\OAuth;` |

### Text Domain

Always use `'atmosphere'`:

```php
\__( 'Text', 'atmosphere' );
\esc_html_e( 'Text', 'atmosphere' );
\_n( 'one', 'many', $count, 'atmosphere' );
```

## Namespaces and Imports

### Backslash-prefix global functions

WordPress and PHP global functions are always backslash-prefixed in namespaced code. This is a project convention — PHP would fall back to the global scope anyway, but the explicit backslash makes the global call site visible at a glance and prevents accidental shadowing:

```php
\get_option( 'atmosphere_settings' );
\add_action( 'init', …, );
\apply_filters( 'atmosphere_should_publish_comment', $bool, $comment );
\is_wp_error( $result );
\strlen( $body );
\time();
```

### Use `use` imports for cross-namespace references

Never inline `\Atmosphere\OAuth\Client` — import it once at the top of the file:

```php
use Atmosphere\OAuth\Client;
use Atmosphere\Transformer\Post;
use function Atmosphere\get_did;
use function Atmosphere\is_connected;
```

## Hook Patterns

### Naming

```php
// Filters return a value; actions are fire-and-forget.
\apply_filters( 'atmosphere_{subject}',           $value );
\apply_filters( 'atmosphere_{subject}_{context}', $value, $extra );
\do_action(     'atmosphere_{event}',             $context… );
```

### Public Hooks (Atmosphere ships these)

**Transform filters** (mutate the record array before write):
```php
\apply_filters( 'atmosphere_transform_bsky_post',   $record, $post );
\apply_filters( 'atmosphere_transform_comment',     $record, $comment );
\apply_filters( 'atmosphere_transform_document',    $record, $post );
\apply_filters( 'atmosphere_transform_publication', $record );
```

**Content / composition filters:**
```php
\apply_filters( 'atmosphere_content_parser',        $parser, $post );        // Return a Content_Parser instance.
\apply_filters( 'atmosphere_document_content',      $content, $post, $parser );
\apply_filters( 'atmosphere_long_form_composition', $composition, $post );
\apply_filters( 'atmosphere_teaser_thread_posts',   $max_posts, $post );
```

**Behaviour / gating filters:**
```php
\apply_filters( 'atmosphere_syncable_post_types',     array( 'post' ) );
\apply_filters( 'atmosphere_should_publish_comment',  $bool, $comment );
\apply_filters( 'atmosphere_should_sync_reply',       $bool, $notification, $post_id );
\apply_filters( 'atmosphere_backfill_limit',          50 );
\apply_filters( 'atmosphere_oauth_redirect_uri',      $uri );
\apply_filters( 'atmosphere_client_metadata',         $metadata );
```

**Actions:**
```php
\do_action( 'atmosphere_publishing' );                                            // Once per publish/update/delete cycle (loop guard).
\do_action( 'atmosphere_publish_post_result',          $post, $result );
\do_action( 'atmosphere_publish_comment_result',       $comment, $result );
\do_action( 'atmosphere_update_skipped_unsynced_post', $post );
\do_action( 'atmosphere_long_form_strategy_downgraded', $post, $from, $to );
\do_action( 'atmosphere_reaction_synced', $comment_id, $notification, $post_id, $comment_type );
```

**Test-only short-circuit:**
```php
\apply_filters( 'atmosphere_pre_apply_writes', null, $writes );
```

## Documentation Standards

### Class

```php
/**
 * Short description (one line).
 *
 * Longer description. Multiple paragraphs are fine.
 *
 * @since 1.0.0
 *
 * @see Related_Class
 */
class Example_Class {
```

### Method

```php
/**
 * Get the stored thread records for a post.
 *
 * @since 1.0.0
 *
 * @param int $post_id Post ID.
 * @return array[]|\WP_Error Array of records on success, WP_Error on failure.
 */
public function stored_thread_records( $post_id ) {
```

### Property

```php
/**
 * Cache of resolved DIDs, keyed by handle.
 *
 * @since 1.0.0
 *
 * @var array<string, string>
 */
private static $did_cache = array();
```

### Inline Comments

- Single-line `//` for brief clarifications.
- Block `/* */` for multi-line context. Avoid stacking consecutive `//` lines for a paragraph — use a block comment instead.
- `/**` DocBlocks are for functions, classes, methods, properties, and constants.

```php
// Single-line clarification.

/*
 * Multi-line block comment for context that spans
 * more than one line.
 */

// TODO: Implement caching.
// FIXME: Handle the edge case when the PDS returns 410.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Needed for batch insert performance.
```

## Security Practices

### Input Sanitization

```php
$text  = \sanitize_text_field( $_POST['text'] );
$body  = \sanitize_textarea_field( $_POST['body'] );
$url   = \sanitize_url( $_POST['url'] );
$email = \sanitize_email( $_POST['email'] );
$slug  = \sanitize_key( $_POST['slug'] );
$int   = \absint( $_POST['count'] );
$html  = \wp_kses_post( $_POST['html'] );
```

### Output Escaping

```php
echo \esc_html( $text );
echo \esc_html__( 'Translatable text', 'atmosphere' );

echo '<input value="' . \esc_attr( $value ) . '">';
echo '<a href="' . \esc_url( $url ) . '">Link</a>';

echo '<script>var data = ' . \wp_json_encode( $data ) . ';</script>';
```

For restricted HTML:

```php
echo \wp_kses(
    $html,
    array(
        'a'      => array( 'href' => array(), 'title' => array() ),
        'br'     => array(),
        'em'     => array(),
        'strong' => array(),
    )
);
```

### Prepared SQL

Never concatenate user input into a query. Always use `$wpdb->prepare()`:

```php
$sql = $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}atmosphere_x WHERE post_id = %d AND collection = %s",
    $post_id,
    $collection
);
```

### Nonces

```php
// Issue.
\wp_nonce_field( 'atmosphere_save_settings', 'atmosphere_nonce' );

// Verify.
if ( ! \isset( $_POST['atmosphere_nonce'] )
    || ! \wp_verify_nonce( $_POST['atmosphere_nonce'], 'atmosphere_save_settings' ) ) {
    \wp_die( \__( 'Security check failed.', 'atmosphere' ) );
}

// AJAX.
\check_ajax_referer( 'atmosphere_ajax', 'nonce' );
```

### Capability Checks

```php
if ( ! \current_user_can( 'manage_options' ) ) {
    \wp_die( \__( 'Insufficient permissions.', 'atmosphere' ) );
}

if ( ! \current_user_can( 'edit_post', $post_id ) ) {
    return new \WP_Error( 'forbidden', \__( 'Access denied.', 'atmosphere' ) );
}
```

### Tokens and Secrets

OAuth tokens, DPoP private keys, and refresh tokens **must** go through `Atmosphere\OAuth\Encryption`. Never store or log them in plaintext.

### Post Visibility in Federation

AT Protocol records are remote, site-wide state. Treat a post as publishable only when all three checks pass:

- `post_status === 'publish'`.
- The post type is supported by ATmosphere.
- `post_password` is empty.

Do **not** use `post_password_required()` for federation output. It depends on the current visitor's unlock cookie, so an editor who has unlocked a protected post locally could cause protected fields to be serialized into PDS records.

Previously-published posts that leave public visibility must delete remote records, not send an update carrying redacted content. This includes draft, pending, private, trash, custom non-public statuses, applying a password, and removing post type support after records already exist. A status transition may queue the normal delete event; a stale publish/update cron callback must re-check visibility and call `Publisher::delete_post( $post )` directly when local record metadata exists.

## Performance Considerations

### Cache Expensive Lookups

```php
// Transients for cross-request reads.
$cache_key = 'atmosphere_resolve_' . \md5( $handle );
$cached    = \get_transient( $cache_key );

if ( false === $cached ) {
    $cached = self::resolve_handle( $handle );
    \set_transient( $cache_key, $cached, \HOUR_IN_SECONDS );
}

// Object cache.
\wp_cache_set( 'atmosphere_did_' . $handle, $did, 'atmosphere', \HOUR_IN_SECONDS );

// Per-request static cache for hot paths.
class Resolver {
    private static $cache = array();

    public static function get( $handle ) {
        if ( ! isset( self::$cache[ $handle ] ) ) {
            self::$cache[ $handle ] = self::resolve( $handle );
        }
        return self::$cache[ $handle ];
    }
}
```

### Cap Unbounded Collections

Any list-like meta or PDS response must have a hard upper bound. Example: `Publisher::record_thread_rollback_failure()` caps `META_ORPHAN_RECORDS` at 10 entries so a stuck cron can't grow a `wp_postmeta` row past `max_allowed_packet`. Follow the same pattern when adding new manifests.

### Database

```php
// Use get_posts() with `fields => 'ids'` if you only need IDs.
$ids = \get_posts( array(
    'post_type'      => 'post',
    'posts_per_page' => 50,
    'fields'         => 'ids',
    'meta_key'       => Post::META_URI,
) );

// Batch inserts when looping is otherwise N queries.
$values = array();
foreach ( $items as $item ) {
    $values[] = $wpdb->prepare( '(%s, %s)', $item['key'], $item['value'] );
}
if ( $values ) {
    $wpdb->query( "INSERT INTO {$table} (key, value) VALUES " . implode( ',', $values ) );
}
```

## Error Handling

### Returning Errors

```php
return new \WP_Error(
    'atmosphere_pds_unreachable',
    \__( 'PDS could not be reached.', 'atmosphere' ),
    array( 'status' => 502, 'pds' => $pds )
);
```

Use error codes prefixed with `atmosphere_` so callers can pattern-match. Include any context that helps the caller decide on retry / fallback.

### Checking Errors

```php
$result = API::apply_writes( $writes );

if ( \is_wp_error( $result ) ) {
    self::log_cron_error( 'publish_post', $post_id, $result );
    return $result;
}
```

### Aggregating

```php
$errors = new \WP_Error();

if ( empty( $args['handle'] ) ) {
    $errors->add( 'missing_handle', \__( 'Handle is required.', 'atmosphere' ) );
}
if ( empty( $args['pds'] ) ) {
    $errors->add( 'missing_pds', \__( 'PDS is required.', 'atmosphere' ) );
}

if ( $errors->has_errors() ) {
    return $errors;
}
```

### Exceptions

```php
try {
    $result = self::risky_operation();
} catch ( \Exception $e ) {
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- log for operators.
    \error_log( '[atmosphere] ' . $e->getMessage() );
    return new \WP_Error( 'atmosphere_exception', $e->getMessage(), array( 'code' => $e->getCode() ) );
}
```

## Cron-Specific Rules

### Three-Way Symmetry

Every plugin-owned `wp_schedule_*` hook **MUST** appear in `Atmosphere\get_cron_hooks()` (`includes/functions.php`). That single list is consumed by:

- `Atmosphere\deactivate()` (`atmosphere.php`)
- `Atmosphere\OAuth\Client::disconnect()` (`includes/oauth/class-client.php`)
- `uninstall.php`

When adding a new cron hook:

1. Add it to `get_cron_hooks()` — do not duplicate the literal in deactivate / disconnect / uninstall.
2. If the handler issues PDS writes without re-checking `is_connected()` (e.g. `atmosphere_delete_records`, `atmosphere_delete_comment_record`), the symmetry is load-bearing — a queued event from a previous connection would otherwise fire against a different repo on reconnect.
3. If the handler stores or sweeps post/comment meta keys, mirror those keys in `uninstall.php`.

This pattern was extracted in PR #32; see review by @kraftbj for the cross-install risk that motivated it.

### Never Swallow `WP_Error`

Cron handlers in `register_async_hooks()` MUST surface `Publisher::*` errors via `error_log()` — typically through `log_cron_error()`. `wp_schedule_single_event` does not retry, so a silent drop loses the only signal operators have for transient PDS failures, expired refresh tokens, or DPoP nonce drift.

When the handler operates on records the caller has already lost local state for (e.g. `atmosphere_delete_comment_record` after the WP comment row is gone), include the TID/identifier in the log line so the orphan is recoverable manually.

### Inflight-State Races

When a cron handler writes meta both *before* an `apply_writes` call (e.g. `Comment::get_rkey()` persists `META_TID`) and *after* (e.g. `store_comment_result()` writes `META_URI`), and a concurrent state change can short-circuit the cleanup gates that key off the *post-call* meta, the handler MUST re-check eligibility after the call returns and roll back if needed.

Concrete pattern: `atmosphere_publish_comment` → `reconcile_comment_after_publish()`. Re-fetch the WP object, re-run the eligibility gate, schedule the orphan-cleanup cron (not direct delete) so transient PDS failures retry through the standard channel.

### Idempotency

The same cron callback can fire twice — concurrent workers, plugin deactivate→reactivate (which re-runs `register_schedules()`), `wp cron event run`, or traffic spikes triggering overlapping loopback requests. If the handler has user-visible side effects (a Bluesky post, a mirrored reaction), gate on a meta sentinel **before** the side effect, not after.
