<?php
/**
 * Loads WordPress with WooCommerce and the plugin, then WordPress's test suite. Each test runs
 * in a database transaction that the suite rolls back.
 *
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

$gatepost_root = dirname( __DIR__ );
require $gatepost_root . '/vendor/autoload.php';

if ( ! is_dir( $gatepost_root . '/build/wp' ) ) {
	echo 'WordPress is missing from build/wp. Run composer test:setup first.' . PHP_EOL;
	exit( 1 );
}

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- the suite reads its config path from it.
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $gatepost_root ) {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		// scripts/install-wp links the repo into wp-content/plugins. WooCommerce knows a plugin
		// only by that folder, and WordPress maps a linked folder to its real path.
		$slug   = 'gatepost-postcode-for-woocommerce';
		$plugin = WP_PLUGIN_DIR . "/{$slug}/{$slug}.php";
		wp_register_plugin_realpath( $plugin );
		require $plugin;
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		WC_Install::install();
		// The order tables (HPOS) exist only on stores that turn them on. The tests use both.
		wc_get_container()
			->get( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )
			->create_database_tables();
		// WooCommerce adds its roles during the install. The suite reads the roles before that.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
	}
);

// A test that reaches NIPOST's gateway without a FakeGateway fails, and no test reaches any other
// host. WooCommerce asks a few hosts for data in the background.
tests_add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( Gatepost\WooCommerce\Tests\Support\FakeGateway::HOST === $host ) {
			throw new LogicException( 'A test called the gateway with no FakeGateway.' );
		}
		return new WP_Error( 'gatepost_tests_offline', 'The tests make no network requests.' );
	},
	PHP_INT_MAX,
	3
);

require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
