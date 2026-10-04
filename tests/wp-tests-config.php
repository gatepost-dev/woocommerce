<?php
/**
 * The WordPress test suite reads this file. scripts/install-wp puts WordPress in build/wp. The
 * tests use SQLite, or the MySQL server that GATEPOST_DB_HOST names when GATEPOST_DB is mysql.
 *
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/build/wp/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

/**
 * Reads one database setting from the environment.
 *
 * @param string $name     The name of the variable.
 * @param string $fallback The value when the variable is missing or empty.
 */
function gatepost_test_setting( string $name, string $fallback ): string {
	$value = getenv( $name );
	return false === $value || '' === $value ? $fallback : $value;
}

define( 'DB_NAME', gatepost_test_setting( 'GATEPOST_DB_NAME', 'wordpress_tests' ) );
define( 'DB_USER', gatepost_test_setting( 'GATEPOST_DB_USER', 'root' ) );
define( 'DB_PASSWORD', gatepost_test_setting( 'GATEPOST_DB_PASSWORD', 'root' ) );
define( 'DB_HOST', gatepost_test_setting( 'GATEPOST_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
// The SQLite drop-in keeps the test database apart from the site that the browser tests use.
define( 'DB_FILE', '.ht.tests.sqlite' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
