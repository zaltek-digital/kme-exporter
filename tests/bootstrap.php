<?php
/**
 * PHPUnit bootstrap for the WordPress integration suite.
 *
 * Needs the WordPress test library (a `WP_TESTS_DIR` + a test database). Set
 * `WP_TESTS_DIR` to point at it, or place one at the conventional
 * `~/.wp-tests/wordpress-phpunit`.
 *
 * @package kme-exporter
 */

$kme_export_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $kme_export_tests_dir ) {
	$kme_export_home = (string) getenv( 'HOME' );

	if ( '' === $kme_export_home ) {
		$kme_export_home = (string) getenv( 'USERPROFILE' );
	}

	$kme_export_tests_dir = rtrim( str_replace( DIRECTORY_SEPARATOR, '/', $kme_export_home ), '/' ) . '/.wp-tests/wordpress-phpunit';
}

if ( ! file_exists( $kme_export_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test library at {$kme_export_tests_dir}.\n" .
		"Set WP_TESTS_DIR to a WordPress test library (and give it a test database).\n"
	);
	exit( 1 );
}

// Composer autoload provides the Yoast polyfills the WP test suite requires.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $kme_export_tests_dir . '/includes/functions.php';

/**
 * Load the plugin under test before WordPress finishes booting.
 *
 * @return void
 */
function kme_export_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/kme-exporter.php';
}

tests_add_filter( 'muplugins_loaded', 'kme_export_manually_load_plugin' );

require $kme_export_tests_dir . '/includes/bootstrap.php';
