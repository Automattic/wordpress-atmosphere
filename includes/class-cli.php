<?php
/**
 * WP-CLI command registration.
 *
 * Thin registry: only loaded under `WP_CLI`, only registers the
 * commands. Each subcommand class lives under `includes/cli/` and
 * extends `\WP_CLI_Command`.
 *
 * @package Atmosphere
 */

namespace Atmosphere;

\defined( 'ABSPATH' ) || exit;

/**
 * ATmosphere CLI command registry.
 *
 * @package Atmosphere
 */
class Cli {

	/**
	 * Register all ATmosphere CLI commands.
	 *
	 * Available commands:
	 * - wp atmosphere version
	 * - wp atmosphere backfill [--post-type=<type>] [--ids=<csv>] [--limit=<n>]
	 *                          [--batch=<n>] [--dry-run] [--force] [--original-time]
	 */
	public static function register(): void {
		\WP_CLI::add_command(
			'atmosphere',
			'\Atmosphere\Cli\Command',
			array(
				'shortdesc' => 'Manage ATmosphere plugin functionality.',
			)
		);

		\WP_CLI::add_command(
			'atmosphere backfill',
			'\Atmosphere\Cli\Backfill_Command',
			array(
				'shortdesc' => 'Backfill existing posts to AT Protocol.',
			)
		);
	}
}
