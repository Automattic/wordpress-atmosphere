<?php
/**
 * Reply recovery CLI commands.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Cli;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\Reaction_Sync;

/**
 * Recover missing Bluesky replies.
 */
class Replies_Command extends \WP_CLI_Command {

	/**
	 * Backfill replies from one published post's current Bluesky thread.
	 *
	 * Existing replies are left unchanged. WordPress moderation, the reply
	 * sync setting, and the `atmosphere_should_sync_reply` filter still apply.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : WordPress post ID whose Bluesky thread should be scanned.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp atmosphere replies backfill 7434
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function backfill( $args, $assoc_args ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$raw_post_id = (string) ( $args[0] ?? '' );

		if ( ! \ctype_digit( $raw_post_id ) ) {
			\WP_CLI::error( \__( 'Provide a valid numeric WordPress post ID.', 'atmosphere' ) );
		}

		$post_id = \absint( $raw_post_id );

		if ( 0 === $post_id || ! \get_post( $post_id ) ) {
			\WP_CLI::error( \__( 'The requested WordPress post was not found.', 'atmosphere' ) );
		}

		\WP_CLI::log(
			\sprintf(
				/* translators: %d: WordPress post ID. */
				\__( 'Scanning the Bluesky thread for WordPress post %d…', 'atmosphere' ),
				$post_id
			)
		);

		$result = Reaction_Sync::backfill_replies( $post_id );

		if ( \is_wp_error( $result ) ) {
			\WP_CLI::error( $result );
		}

		\WP_CLI::success(
			\sprintf(
				/* translators: 1: replies visible to Bluesky's public API, 2: imported replies, 3: existing replies, 4: skipped replies, 5: imported replies not publicly approved. */
				\__( 'Visible replies: %1$d; imported: %2$d; already present: %3$d; skipped: %4$d; imported but not publicly approved: %5$d.', 'atmosphere' ),
				$result['found'],
				$result['imported'],
				$result['existing'],
				$result['skipped'],
				$result['pending']
			)
		);
	}
}
