<?php
/**
 * WP-CLI commands for ATmosphere.
 *
 * Headless counterpart to the admin-AJAX backfill in
 * {@see Backfill::handle_count()} and {@see Backfill::handle_batch()}.
 * Reuses {@see Backfill::get_unsynced_post_ids()} so the CLI and AJAX
 * paths apply the same eligibility rules.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Transformer\Document;

/**
 * WP-CLI command class.
 *
 * Registered from `atmosphere.php` only when WP-CLI is available, so
 * the regular web-request path never autoloads this class.
 */
class CLI {

	/**
	 * Default batch size for progress reporting and per-batch cache
	 * eviction.
	 *
	 * The publish loop is one post at a time regardless; this controls
	 * how often the progress bar ticks an intermediate "n of m" log
	 * line, and how often the per-loop object cache is flushed to keep
	 * long runs from growing unboundedly.
	 *
	 * @var int
	 */
	private const DEFAULT_BATCH = 25;

	/**
	 * Backfill existing WordPress posts to AT Protocol.
	 *
	 * Headless counterpart to the admin-AJAX backfill in the settings
	 * UI. Walks the same unsynced-posts query so the two surfaces stay
	 * in lockstep.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<type>]
	 * : Limit the run to a single supported post type. Defaults to all
	 * supported post types. Ignored when --ids is also supplied (a
	 * warning is printed in that case).
	 *
	 * [--ids=<csv>]
	 * : Explicit comma-separated list of post IDs. Bypasses the unsynced
	 * query. Each ID is still gated on `is_post_publishable()`; ineligible
	 * IDs are reported as skipped.
	 *
	 * [--limit=<n>]
	 * : Maximum posts to process. Default: 0 (no cap). Note the AJAX UI
	 * defaults to 10 posts per run; the CLI default is unlimited so a
	 * scripted backfill can drain a queue in one invocation. Non-numeric
	 * or negative values are rejected.
	 *
	 * [--batch=<n>]
	 * : Batch size used for progress reporting and periodic object-cache
	 * eviction. Default: 25. Does not change the publish loop semantics —
	 * posts are still published one at a time — but tunes how often
	 * progress ticks and how often per-post caches are dropped to keep
	 * long runs from growing memory unboundedly.
	 *
	 * [--dry-run]
	 * : List the posts that would be published. Does NOT call the
	 * publisher.
	 *
	 * [--force]
	 * : Re-sync posts even when they already carry the document URI
	 * meta. Posts with a prior successful publish are updated in place
	 * (existing TIDs and URIs are preserved; the PDS replaces the
	 * record contents with the current WordPress state). Without this
	 * flag, already-synced posts are skipped.
	 *
	 * [--original-time]
	 * : Use the original publish date when generating record identifiers.
	 * Currently equivalent to the default; this flag is reserved for an
	 * upcoming change that adds historical timestamp support.
	 *
	 * ## EXAMPLES
	 *
	 *     # Backfill every unsynced post.
	 *     wp atmosphere backfill
	 *
	 *     # Preview the next 50 unsynced posts without publishing.
	 *     wp atmosphere backfill --limit=50 --dry-run
	 *
	 *     # Republish a specific post even if it is already synced.
	 *     wp atmosphere backfill --ids=123 --force
	 *
	 *     # Restrict to a single post type.
	 *     wp atmosphere backfill --post-type=article
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Flag arguments.
	 * @return void
	 */
	public static function backfill( array $args, array $assoc_args ): void {
		unset( $args );

		$supported = get_supported_post_types();

		if ( empty( $supported ) ) {
			\WP_CLI::warning( \__( 'No post types are configured to sync to AT Protocol. Nothing to do.', 'atmosphere' ) );
			return;
		}

		$post_type_arg = isset( $assoc_args['post-type'] ) ? (string) $assoc_args['post-type'] : '';
		$ids_arg       = isset( $assoc_args['ids'] ) ? (string) $assoc_args['ids'] : '';

		/*
		 * Validate --limit before coercion. A bare `--limit` (no value)
		 * arrives as boolean true, which `(int)` would silently promote
		 * to 1 — running a single-post backfill the user did not ask
		 * for. A negative value would short-circuit our `> 0` cap check
		 * and run unbounded. Reject both with a clear message; literal
		 * `0` and an absent flag still mean "no cap".
		 */
		if ( isset( $assoc_args['limit'] ) ) {
			$raw_limit = $assoc_args['limit'];

			if ( ! \is_numeric( $raw_limit ) || (int) $raw_limit < 0 ) {
				\WP_CLI::error(
					\sprintf(
						/* translators: %s: the rejected --limit value. */
						\__( 'Invalid --limit value "%s": expected 0 (no cap) or a positive integer.', 'atmosphere' ),
						\is_scalar( $raw_limit ) ? (string) $raw_limit : \gettype( $raw_limit )
					)
				);
			}

			$limit = (int) $raw_limit;
		} else {
			$limit = 0;
		}

		$batch         = isset( $assoc_args['batch'] ) ? \max( 1, (int) $assoc_args['batch'] ) : self::DEFAULT_BATCH;
		$dry_run       = ! empty( $assoc_args['dry-run'] );
		$force         = ! empty( $assoc_args['force'] );
		$original_time = ! empty( $assoc_args['original-time'] );

		/*
		 * `--original-time` is intentionally captured but unused in this
		 * PR. A follow-up will branch on this flag to call a
		 * historical-TID code path. Reference the variable so static
		 * analysers do not flag it as unused while the wire-up is in
		 * flight.
		 */
		unset( $original_time );

		$post_types = $supported;

		if ( '' !== $post_type_arg ) {
			if ( ! \in_array( $post_type_arg, $supported, true ) ) {
				\WP_CLI::error(
					\sprintf(
						/* translators: 1: requested post type slug, 2: comma-separated list of supported post type slugs. */
						\__( 'Post type "%1$s" is not configured to sync to AT Protocol. Supported types: %2$s.', 'atmosphere' ),
						$post_type_arg,
						\implode( ', ', $supported )
					)
				);
			}

			$post_types = array( $post_type_arg );
		}

		if ( '' !== $ids_arg && '' !== $post_type_arg ) {
			\WP_CLI::warning(
				\__( '--post-type is ignored when --ids is supplied; the explicit ID list takes precedence.', 'atmosphere' )
			);
		}

		if ( '' !== $ids_arg ) {
			$parsed   = self::parse_ids( $ids_arg );
			$post_ids = $parsed['ids'];

			if ( ! empty( $parsed['rejected'] ) ) {
				/*
				 * Loud failure on partially numeric / non-digit tokens.
				 * A silent drop of `"1.5"` (operator meant "15") would
				 * publish post 1 instead — the kind of false positive
				 * that's strictly worse than refusing to run for a CLI
				 * whose action is publish-to-the-internet.
				 */
				\WP_CLI::error(
					\sprintf(
						/* translators: %s: comma-separated list of rejected tokens. */
						\__( 'Invalid post ID tokens in --ids: %s. Expected a comma-separated list of positive integers; aborting before any publish.', 'atmosphere' ),
						\implode( ', ', $parsed['rejected'] )
					)
				);
			}

			if ( empty( $post_ids ) ) {
				\WP_CLI::error(
					\sprintf(
						/* translators: %s: the raw --ids flag value. */
						\__( 'No valid post IDs parsed from --ids "%s". Expected a comma-separated list of positive integers.', 'atmosphere' ),
						$ids_arg
					)
				);
			}

			if ( $limit > 0 && \count( $post_ids ) > $limit ) {
				$post_ids = \array_slice( $post_ids, 0, $limit );
			}
		} else {
			$post_ids = Backfill::get_unsynced_post_ids( $limit, $post_types );
		}

		if ( empty( $post_ids ) ) {
			\WP_CLI::success( \__( 'No posts to backfill.', 'atmosphere' ) );
			return;
		}

		$total = \count( $post_ids );

		\WP_CLI::log(
			$dry_run
				? \sprintf(
					/* translators: %d: number of posts queued. */
					\_n(
						'Preparing %d post for backfill (dry run).',
						'Preparing %d posts for backfill (dry run).',
						$total,
						'atmosphere'
					),
					$total
				)
				: \sprintf(
					/* translators: %d: number of posts queued. */
					\_n(
						'Preparing %d post for backfill.',
						'Preparing %d posts for backfill.',
						$total,
						'atmosphere'
					),
					$total
				)
		);

		$progress = null;

		if ( ! $dry_run && \function_exists( '\WP_CLI\Utils\make_progress_bar' ) ) {
			$progress = \WP_CLI\Utils\make_progress_bar(
				\__( 'Publishing posts', 'atmosphere' ),
				$total
			);
		}

		$synced    = 0;
		$skipped   = 0;
		$errors    = 0;
		$ticks     = 0;
		$batch_ids = array();

		foreach ( $post_ids as $post_id ) {
			$post_id       = (int) $post_id;
			$tick_progress = true;

			$post = \get_post( $post_id );

			if ( ! $post instanceof \WP_Post ) {
				\WP_CLI::warning(
					\sprintf(
						/* translators: %d: post ID. */
						\__( 'Skipping post %d: not found.', 'atmosphere' ),
						$post_id
					)
				);
				++$skipped;
			} elseif ( ! is_post_publishable( $post ) ) {
				\WP_CLI::warning(
					\sprintf(
						/* translators: %d: post ID. */
						\__( 'Skipping post %d: not eligible for sync (draft, password-protected, or unsupported post type).', 'atmosphere' ),
						$post_id
					)
				);
				++$skipped;
			} else {
				$already_synced = ! empty( \get_post_meta( $post_id, Document::META_URI, true ) );

				if ( $already_synced && ! $force ) {
					\WP_CLI::warning(
						\sprintf(
							/* translators: %d: post ID. */
							\__( 'Skipping post %d: already synced. Pass --force to republish.', 'atmosphere' ),
							$post_id
						)
					);
					++$skipped;
				} elseif ( $dry_run ) {
					\WP_CLI::log(
						\sprintf(
							/* translators: 1: post ID, 2: post title. */
							\__( 'Would publish post %1$d: %2$s', 'atmosphere' ),
							$post_id,
							\get_the_title( $post )
						)
					);
					++$synced;
					// Dry-run does not drive the progress bar (none is created).
					$tick_progress = false;
				} else {
					/*
					 * `--force` on an already-synced post routes through
					 * `update_post()`, not `publish_post()`. The publish path
					 * issues `applyWrites#create` ops keyed off the stored
					 * TIDs; the PDS rejects creates whose rkey already
					 * exists. The update path issues `applyWrites#update`
					 * against the same TIDs and preserves the records'
					 * external engagement (likes, reposts, replies) instead
					 * of orphaning it. The skip check above ensures we only
					 * land here with $force when META_URI is set.
					 */
					$result = $already_synced
						? Publisher::update_post( $post )
						: Publisher::publish_post( $post );

					if ( \is_wp_error( $result ) ) {
						\WP_CLI::warning(
							\sprintf(
								/* translators: 1: post ID, 2: error message. */
								\__( 'Failed to publish post %1$d: %2$s', 'atmosphere' ),
								$post_id,
								$result->get_error_message()
							)
						);
						++$errors;
					} else {
						/*
						 * Both branches pass the same placeholders so PHPCS's
						 * translators-comment sniff is satisfied by attaching
						 * the comment to each `__()` site rather than to the
						 * `sprintf()` site.
						 */
						$message = $already_synced
							/* translators: 1: post ID, 2: post title. */
							? \__( 'Updated post %1$d: %2$s', 'atmosphere' )
							/* translators: 1: post ID, 2: post title. */
							: \__( 'Published post %1$d: %2$s', 'atmosphere' );
						\WP_CLI::success(
							\sprintf( $message, $post_id, \get_the_title( $post ) )
						);
						++$synced;
					}
				}
			}

			if ( $progress && $tick_progress ) {
				$progress->tick();
			}

			++$ticks;
			$batch_ids[] = $post_id;

			/*
			 * Every $batch posts, drop the per-post object-cache entries
			 * accumulated since the last flush. Long runs (10k+ posts)
			 * would otherwise hold every visited WP_Post + meta + term
			 * row in the in-process cache for the entire process
			 * lifetime. Per-post `clean_post_cache()` matches the rest
			 * of the codebase's pattern
			 * (`Atmosphere::on_comment_insert`,
			 * `Publisher::publish_post`); it's narrower than a full
			 * `wp_cache_flush()` so it does not evict unrelated entries
			 * a long-running CLI script may have warmed.
			 *
			 * Important: evict every ID visited in the batch, not just
			 * the boundary one — the boundary-only variant the previous
			 * round shipped only freed 1 entry per `--batch` posts.
			 */
			if ( ! $dry_run && 0 === $ticks % $batch ) {
				foreach ( $batch_ids as $cached_id ) {
					\clean_post_cache( $cached_id );
				}
				$batch_ids = array();

				if ( $ticks < $total ) {
					\WP_CLI::log(
						\sprintf(
							/* translators: 1: number processed, 2: total. */
							\__( 'Progress: %1$d of %2$d posts processed.', 'atmosphere' ),
							$ticks,
							$total
						)
					);
				}
			}
		}

		// Final-batch sweep so the trailing posts under one full $batch are not left cached.
		if ( ! $dry_run && ! empty( $batch_ids ) ) {
			foreach ( $batch_ids as $cached_id ) {
				\clean_post_cache( $cached_id );
			}
		}

		if ( $progress ) {
			$progress->finish();
		}

		$summary = \sprintf(
			/* translators: 1: synced count, 2: total count, 3: skipped count, 4: error count. */
			\__( 'Synced %1$d of %2$d posts (%3$d skipped, %4$d errors).', 'atmosphere' ),
			$synced,
			$total,
			$skipped,
			$errors
		);

		/*
		 * Surface a non-zero exit when at least one post failed to
		 * publish. Scripted callers (the entire point of the CLI command)
		 * detect failure via the process exit code — `success()` would
		 * exit 0 even when every publish errored, hiding the failure from
		 * cron and CI wrappers.
		 */
		if ( $errors > 0 ) {
			\WP_CLI::error( $summary );
		}

		\WP_CLI::success( $summary );
	}

	/**
	 * Parse the `--ids=<csv>` flag into a deduped list of positive
	 * integers plus a list of rejected raw tokens.
	 *
	 * Returns a `{ids, rejected}` array:
	 *
	 * - `ids`      — deduped list of positive integers, in the order
	 *                the user supplied them.
	 * - `rejected` — raw tokens that failed strict validation. The CLI
	 *                surfaces this list as a fatal error before any
	 *                publish runs, so a typo like "1.5" (operator meant
	 *                "15") never silently publishes post 1 instead.
	 *
	 * Empty and whitespace-only tokens are skipped without being
	 * rejected (a trailing comma is not user error). Anything else
	 * non-digit — including `1.5`, `123abc`, `1-2`, negatives — lands
	 * in `rejected`, because PHP's `(int)` cast silently truncates
	 * those to a different number than the operator typed.
	 *
	 * Public so the test suite can exercise the parsing rules without
	 * resorting to reflection; the rules are part of the CLI's
	 * documented contract.
	 *
	 * @param string $raw Raw flag value.
	 * @return array{ids: int[], rejected: string[]}
	 */
	public static function parse_ids( string $raw ): array {
		$parts    = \explode( ',', $raw );
		$ids      = array();
		$rejected = array();

		foreach ( $parts as $part ) {
			$trimmed = \trim( $part );

			if ( '' === $trimmed ) {
				continue;
			}

			/*
			 * Strict digit check. `(int) '1.5'` would silently become 1
			 * and republish a different post than the operator typed —
			 * exactly the safety bug codex flagged. `ctype_digit` only
			 * accepts strings of digits 0-9, so leading signs, decimals,
			 * range syntax (`1-2`), and trailing junk all land in
			 * `$rejected` and the caller errors out before any publish.
			 */
			if ( ! \ctype_digit( $trimmed ) ) {
				$rejected[] = $part;
				continue;
			}

			$id = (int) $trimmed;

			if ( $id <= 0 ) {
				continue;
			}

			$ids[] = $id;
		}

		return array(
			'ids'      => \array_values( \array_unique( $ids ) ),
			'rejected' => $rejected,
		);
	}
}
