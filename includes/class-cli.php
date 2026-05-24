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
	 * Default batch size for progress reporting.
	 *
	 * The publish loop is one post at a time regardless; this only
	 * affects how often the progress bar ticks and the cadence of
	 * intermediate "n of m" log lines.
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
	 * supported post types.
	 *
	 * [--ids=<csv>]
	 * : Explicit comma-separated list of post IDs. Bypasses the unsynced
	 * query. Each ID is still gated on `is_post_publishable()`; ineligible
	 * IDs are reported as skipped.
	 *
	 * [--limit=<n>]
	 * : Maximum posts to process. Default: 0 (no cap). Note the AJAX UI
	 * defaults to 10 posts per run; the CLI default is unlimited so a
	 * scripted backfill can drain a queue in one invocation.
	 *
	 * [--batch=<n>]
	 * : Batch size for progress reporting. Default: 25. Does not change
	 * the publish loop semantics — posts are still published one at a
	 * time — but tunes how often progress ticks.
	 *
	 * [--dry-run]
	 * : List the posts that would be published. Does NOT call the
	 * publisher.
	 *
	 * [--force]
	 * : Republish posts even when they already carry the document URI
	 * meta. Without this flag, already-synced posts are skipped.
	 *
	 * [--original-time]
	 * : Wired but currently a no-op. A follow-up to issue 89 will use
	 * this flag to route published records through a historical TID so
	 * the AT Protocol timeline reflects the original WordPress publish
	 * date. Landing the flag now keeps the CLI's external contract
	 * stable before that work merges.
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
		$limit         = isset( $assoc_args['limit'] ) ? \max( 0, (int) $assoc_args['limit'] ) : 0;
		$batch         = isset( $assoc_args['batch'] ) ? \max( 1, (int) $assoc_args['batch'] ) : self::DEFAULT_BATCH;
		$dry_run       = ! empty( $assoc_args['dry-run'] );
		$force         = ! empty( $assoc_args['force'] );
		$original_time = ! empty( $assoc_args['original-time'] );

		/*
		 * `--original-time` is intentionally captured but unused in this
		 * PR. The follow-up to issue 89 will branch on this flag to call
		 * a historical-TID code path. Reference the variable so static
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

		$post_ids = '' !== $ids_arg
			? self::parse_ids( $ids_arg )
			: Backfill::get_unsynced_post_ids( $limit, $post_types );

		if ( '' !== $ids_arg && $limit > 0 && \count( $post_ids ) > $limit ) {
			$post_ids = \array_slice( $post_ids, 0, $limit );
		}

		if ( empty( $post_ids ) ) {
			\WP_CLI::success( \__( 'No posts to backfill.', 'atmosphere' ) );
			return;
		}

		$total = \count( $post_ids );

		\WP_CLI::log(
			\sprintf(
				/* translators: 1: number of posts queued, 2: "(dry run)" suffix when dry-run is on, else empty. */
				\_n(
					'Preparing %1$d post for backfill%2$s.',
					'Preparing %1$d posts for backfill%2$s.',
					$total,
					'atmosphere'
				),
				$total,
				$dry_run ? ' (dry run)' : ''
			)
		);

		$progress = null;

		if ( ! $dry_run && \class_exists( '\WP_CLI\Utils\ProgressBar' ) ) {
			$progress = \WP_CLI\Utils\make_progress_bar(
				\__( 'Publishing posts', 'atmosphere' ),
				$total
			);
		}

		$synced  = 0;
		$skipped = 0;
		$errors  = 0;
		$ticks   = 0;

		foreach ( $post_ids as $post_id ) {
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
				if ( $progress ) {
					$progress->tick();
				}
				continue;
			}

			if ( ! is_post_publishable( $post ) ) {
				\WP_CLI::warning(
					\sprintf(
						/* translators: %d: post ID. */
						\__( 'Skipping post %d: not eligible for sync (draft, password-protected, or unsupported post type).', 'atmosphere' ),
						$post_id
					)
				);
				++$skipped;
				if ( $progress ) {
					$progress->tick();
				}
				continue;
			}

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
				if ( $progress ) {
					$progress->tick();
				}
				continue;
			}

			if ( $dry_run ) {
				\WP_CLI::log(
					\sprintf(
						/* translators: 1: post ID, 2: post title. */
						\__( 'Would publish post %1$d: %2$s', 'atmosphere' ),
						$post_id,
						\get_the_title( $post )
					)
				);
				++$synced;
				continue;
			}

			$result = Publisher::publish_post( $post );

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
				\WP_CLI::success(
					\sprintf(
						/* translators: 1: post ID, 2: post title. */
						\__( 'Published post %1$d: %2$s', 'atmosphere' ),
						$post_id,
						\get_the_title( $post )
					)
				);
				++$synced;
			}

			if ( $progress ) {
				$progress->tick();
			}

			++$ticks;

			/*
			 * `--batch` only controls the cadence of intermediate
			 * progress lines. The publish loop itself is per-post; we
			 * do not bulk the Publisher calls.
			 */
			if ( ! $dry_run && 0 === $ticks % $batch && $ticks < $total ) {
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

		if ( $progress ) {
			$progress->finish();
		}

		\WP_CLI::success(
			\sprintf(
				/* translators: 1: synced count, 2: total count, 3: skipped count, 4: error count. */
				\__( 'Synced %1$d of %2$d posts (%3$d skipped, %4$d errors).', 'atmosphere' ),
				$synced,
				$total,
				$skipped,
				$errors
			)
		);
	}

	/**
	 * Parse the `--ids=<csv>` flag into a deduped list of positive integers.
	 *
	 * Preserves the order the user supplied — the CLI run will visit
	 * them in that order, which is the principle of least surprise for
	 * scripted invocations that have already sorted their input.
	 *
	 * @param string $raw Raw flag value.
	 * @return int[]
	 */
	private static function parse_ids( string $raw ): array {
		$parts = \explode( ',', $raw );
		$ids   = array();

		foreach ( $parts as $part ) {
			$id = (int) \trim( $part );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return \array_values( \array_unique( $ids ) );
	}
}
