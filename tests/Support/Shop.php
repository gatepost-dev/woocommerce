<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests\Support;

use WC_Product_Simple;
use WC_Shipping_Zone;

/**
 * Sets up a shop that can take an order: one book in the cart, flat-rate shipping and cash on
 * delivery.
 */
final class Shop {

	/**
	 * Fills the cart, and turns on the shipping method and the payment method.
	 */
	public static function fill_cart(): void {
		update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );
		WC()->payment_gateways()->init();
		$zone = new WC_Shipping_Zone( 0 );
		if ( array() === $zone->get_shipping_methods() ) {
			$zone->add_shipping_method( 'flat_rate' );
			$zone->save();
		}
		$book = new WC_Product_Simple();
		$book->set_name( 'Book' );
		$book->set_regular_price( '1000' );
		$book->save();
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $book->get_id() );
	}
}
