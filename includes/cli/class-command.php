<?php
/**
 * Base CLI command.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Cli;

\defined( 'ABSPATH' ) || exit;

/**
 * Manage ATmosphere plugin functionality.
 *
 * @package Atmosphere
 */
class Command extends \WP_CLI_Command {

	/**
	 * Display the ATmosphere plugin version.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp atmosphere version
	 *     ATmosphere 1.1.1
	 *
	 * @subcommand version
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function version( $args, $assoc_args ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		\WP_CLI::line( 'ATmosphere ' . ATMOSPHERE_VERSION );
	}
}
