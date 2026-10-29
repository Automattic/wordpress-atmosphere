# Scheduled callbacks

Publish, update, delete and reaction-sync events: running twice, running stale, surviving a disconnect.

Applies to: `includes/class-atmosphere.php` (cron registration), `includes/class-publisher.php`, `includes/class-reaction-sync.php`, `includes/class-backfill.php`, `get_cron_hooks()` in `includes/functions.php`, and any new `wp_schedule_event` or `wp_schedule_single_event`

## Running twice

- `wp_schedule_single_event` can fire twice: concurrent workers, reactivation, `wp cron event run`, traffic spikes that trigger overlapping loopback requests. A handler with a visible side effect (a published Bluesky post, a stored comment) gates on a meta sentinel before the side effect.
- The claim is atomic: `add_option( $key, $value, '', false )` only succeeds when the row does not exist yet, which makes it race-safe.

## In-flight state

- A publish writes meta before (rkey reservation in `get_rkey()`) and after (the URI in `mirror_thread_records_meta()`). A comment deletion or status change in between leaves the two inconsistent; confirm the reconcile-after-publish pattern is applied (see `reconcile_comment_after_publish()`).

## Stale events

- A lifecycle transition cancels or supersedes queued work. A publish or restore after a queued delete does not leave the delete alive to remove the restored records. A new delete does not leave an older update able to write protected content first.
- Handlers re-check visibility when they fire (see "When a post stops being public" in `content-exposure.md`).

## Unscheduling

Every plugin-owned cron hook is listed in `Atmosphere\get_cron_hooks()`, and disconnect, deactivate and uninstall clear them with `wp_unschedule_hook()`, not `wp_clear_scheduled_hook()`. The latter only clears events whose args match, so arg-carrying single events (the retry ladder carries `array( $post_id )`) survive an args-less clear and fire against a *different repo* after a disconnect and reconnect to another account.
