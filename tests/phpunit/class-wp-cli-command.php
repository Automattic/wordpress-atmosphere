<?php
/**
 * Stub for WP-CLI's command base class.
 *
 * Loaded from {@see tests/phpunit/bootstrap.php} so the CLI command
 * classes under `includes/cli/` can be autoloaded under PHPUnit. WP-CLI
 * itself is not loaded by the WP test bootstrap; without this stub,
 * autoloading `Atmosphere\Cli\Backfill_Command` fatals on the missing
 * parent class. This stub only has to supply the (empty) parent class
 * so the command autoloads; the command's outer flow is exercised via
 * the WP_CLI facade stub in `tests/phpunit/class-wp-cli.php`.
 *
 * @package Atmosphere
 */

/*
 * No ABSPATH guard: this file is required from `tests/phpunit/bootstrap.php`
 * *before* WordPress core's test bootstrap defines ABSPATH. An `|| exit`
 * at the top would terminate PHPUnit before any test ran. The
 * `class_exists` guard below is the actual safety: it prevents a real
 * WP-CLI runtime (which would already define this class) from re-declaring.
 */

if ( ! \class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Minimal stub of WP-CLI's command base class.
	 */
	class WP_CLI_Command {}
}
