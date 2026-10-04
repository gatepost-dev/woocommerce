<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests\Support;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Places an order through the Store API, as the checkout block does. Fill the cart with
 * Shop::fill_cart() first.
 */
final class StoreApiCheckout {

	/**
	 * Sends the checkout request.
	 *
	 * @param array<string, string> $billing  The billing address, on top of a Nigerian one.
	 * @param array<string, string> $shipping The shipping address, on top of a Nigerian one.
	 */
	public static function place( array $billing, array $shipping ): WP_REST_Response {
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		// The database of the last test is gone, so its draft order must not come back.
		WC()->session->set( 'store_api_draft_order', null );
		$address = array(
			'first_name' => 'Ada',
			'last_name'  => 'Obi',
			'address_1'  => '1 Test Road',
			'city'       => 'Abuja',
			'state'      => 'FC',
			'country'    => 'NG',
			'postcode'   => '',
			'phone'      => '08000000000',
		);
		$billing = array_merge( $address, array( 'email' => 'ada@example.com' ), $billing );
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'billing_address'  => $billing,
					'shipping_address' => array_merge( $address, $shipping ),
					'payment_method'   => 'cod',
				)
			)
		);
		// The checkout route keeps the order of its last request. A real request starts with a new
		// route, so each test starts a new REST server with new routes.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_rest_server'] = null;
		// WooCommerce tells a Store API request by its path, and keeps the locale of a request.
		$page                   = $_SERVER['REQUEST_URI'] ?? '';
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';
		WC()->countries->locale = null;
		try {
			return rest_do_request( $request );
		} finally {
			$_SERVER['REQUEST_URI'] = $page;
			WC()->countries->locale = null;
		}
	}
}
