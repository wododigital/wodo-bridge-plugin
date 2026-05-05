<?php
/**
 * PHPUnit bootstrap.
 *
 * Boots the WordPress test suite, registers the plugin, and exposes the
 * shared TestCase base class to suites under tests/.
 *
 * Required environment:
 *   WP_TESTS_DIR   absolute path to a checkout of the WP test library
 *                  (e.g. ~/wp-tests). See tests/README.md for setup.
 *
 * @package WODO_Bridge\Tests
 */

declare( strict_types=1 );

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wp_tests_dir ) {
	$wp_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find {$wp_tests_dir}/includes/functions.php — set WP_TESTS_DIR or run bin/install-wp-tests.sh.\n"
	);
	exit( 1 );
}

// Composer autoloader for dev deps (PHPUnit polyfills, Brain Monkey if used).
$composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
	require_once $composer_autoload;
}

// Ensure the polyfills bootstrap before WP test boots so 9.6+ assertions resolve.
if ( file_exists( dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
}

require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Hook the plugin into the test suite before WP loads it.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/wodo-bridge.php';
	}
);

require $wp_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/TestCase.php';
