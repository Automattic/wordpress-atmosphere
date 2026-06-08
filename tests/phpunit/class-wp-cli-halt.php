<?php
/**
 * Exception thrown by the stubbed {@see WP_CLI::error()}.
 *
 * Loaded from {@see tests/phpunit/bootstrap.php} alongside the WP-CLI
 * facade stub. Mirrors WP-CLI halting the process so tests can assert
 * the backfill command's non-zero-exit and mid-run abort paths.
 *
 * @package Atmosphere
 */

/*
 * No ABSPATH guard: required before WordPress core's test bootstrap
 * defines ABSPATH (see `class-wp-cli-command.php` for the rationale).
 */

if ( ! \class_exists( 'WP_CLI_Halt' ) ) {
	/**
	 * Thrown by the stubbed {@see WP_CLI::error()} to mirror WP-CLI's
	 * process halt so tests can assert that the command aborted.
	 */
	class WP_CLI_Halt extends \Exception {}
}
