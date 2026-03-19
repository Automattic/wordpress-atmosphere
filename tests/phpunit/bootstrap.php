<?php
/**
 * Bootstrap file for ATmosphere tests.
 *
 * @package Atmosphere
 */

\define( 'ATMOSPHERE_TESTS_DIR', __DIR__ );

$_tests_dir = \getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = \rtrim( \sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! \file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . \PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require __DIR__ . '/../../atmosphere.php';
}
\tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Disable HTTP requests in tests.
 *
 * @param mixed  $response The value to return instead of making a HTTP request.
 * @param array  $args     Request arguments.
 * @param string $url      The request URL.
 * @return mixed|WP_Error
 */
function http_disable_request( $response, $args, $url ) {
	if ( false !== $response ) {
		return $response;
	}

	if ( apply_filters( 'tests_allow_http_request', false, $args, $url ) ) {
		return false;
	}

	return new WP_Error( 'cancelled', 'Live HTTP request cancelled by bootstrap.php' );
}
\tests_add_filter( 'pre_http_request', 'http_disable_request', 99, 3 );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
