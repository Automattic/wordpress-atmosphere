<?php
/**
 * Stub for WP-CLI's command base class.
 *
 * Loaded from {@see tests/phpunit/bootstrap.php} so the CLI command
 * classes under `includes/cli/` can be autoloaded under PHPUnit. WP-CLI
 * itself is not loaded by the WP test bootstrap; without this stub,
 * autoloading `Atmosphere\Cli\Backfill_Command` fatals on the missing
 * parent class. Tests that exercise the command's outer flow are not
 * in scope — only its static input parser is — so an empty stub is
 * sufficient.
 *
 * @package Atmosphere
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Minimal stub of WP-CLI's command base class.
	 */
	class WP_CLI_Command {}
}
