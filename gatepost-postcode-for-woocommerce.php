<?php
/**
 * Plugin Name: Gatepost Postcode for WooCommerce
 * Plugin URI: https://github.com/gatepost-dev/woocommerce
 * Description: Unofficial. Not made or endorsed by NIPOST. Checks Nigerian postcodes at checkout.
 * Version: 0.1.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 10.0
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

// GitHub's source archive has no bundled SDK. Only the release zip has it.
$gatepost_autoload = __DIR__ . '/vendor-prefixed/autoload.php';
if ( ! is_readable( $gatepost_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			// Each sentence is one string, and the notice joins them with a space.
			printf(
				'<div class="notice notice-error"><p>%1$s %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__(
					'Gatepost Postcode for WooCommerce cannot start. Its files are missing.',
					'gatepost-postcode-for-woocommerce'
				),
				esc_html__(
					'Install the plugin from the release zip, not from the source archive.',
					'gatepost-postcode-for-woocommerce'
				),
				esc_url( 'https://github.com/gatepost-dev/woocommerce/releases' ),
				esc_html__( 'Get the release zip.', 'gatepost-postcode-for-woocommerce' )
			);
		}
	);
	return;
}
require_once $gatepost_autoload;
register_activation_hook( __FILE__, array( Gatepost\WooCommerce\Plugin::class, 'activate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		$features = Automattic\WooCommerce\Utilities\FeaturesUtil::class;
		$features::declare_compatibility( 'custom_order_tables', __FILE__ );
		$features::declare_compatibility( 'cart_checkout_blocks', __FILE__ );
	}
);

add_action( 'woocommerce_loaded', array( Gatepost\WooCommerce\Plugin::class, 'boot' ) );
