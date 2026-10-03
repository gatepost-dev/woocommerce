<?php
/**
 * Plugin Name: Gatepost Postcode for WooCommerce
 * Plugin URI: https://github.com/gatepost-dev/woocommerce
 * Description: Unofficial. Not made or endorsed by NIPOST. Checks Nigerian postcodes at checkout.
 * Version: 0.1.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.9
 * WC tested up to: 11.1
 * Author: The Gatepost authors
 * License: Apache-2.0
 * License URI: https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain: gatepost-postcode-for-woocommerce
 *
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/vendor-prefixed/autoload.php';

add_action(
	'before_woocommerce_init',
	static function () {
		$features = Automattic\WooCommerce\Utilities\FeaturesUtil::class;
		$features::declare_compatibility( 'custom_order_tables', __FILE__ );
		$features::declare_compatibility( 'cart_checkout_blocks', __FILE__ );
	}
);
