<?php
/**
 * PHPUnit bootstrap — loads WordPress test environment and the plugin autoloader.
 *
 * Requires WP_TESTS_DIR to point to a wordpress-develop/tests/phpunit directory,
 * or use wp-env which sets this up automatically.
 *
 * @package WPLedger
 */

$tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $tests_dir ) {
	$tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $tests_dir/includes/functions.php\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

// Load WP test functions (does not load WP itself yet).
require_once $tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin during WordPress bootstrap.
 */
function wpledger_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/wpledger.php';
}
tests_add_filter( 'muplugins_loaded', 'wpledger_manually_load_plugin' );

// Bootstrap WordPress.
require $tests_dir . '/includes/bootstrap.php';
