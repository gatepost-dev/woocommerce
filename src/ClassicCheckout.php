<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

use Throwable;
use WC_Order;
use WP_Error;

/**
 * Checks the fields billing_gatepost_postcode and shipping_gatepost_postcode of the classic
 * checkout, which AddressLocale adds.
 */
final class ClassicCheckout {

	/**
	 * Adds the hooks.
	 */
	public static function register(): void {
		add_action(
			'woocommerce_after_checkout_validation',
			array( self::class, 'validate' ),
			10,
			2
		);
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'record' ) );
	}

	/**
	 * Adds an error for each postcode in a Nigerian address that has the wrong format.
	 *
	 * @param array<string, mixed> $data   The posted checkout data, after WooCommerce cleaned it.
	 * @param WP_Error             $errors The checkout errors.
	 */
	public static function validate( array $data, WP_Error $errors ): void {
		try {
			$checker = new Checker( Settings::load()->accept_legacy );
			$groups  = array( 'billing' );
			if ( ! empty( $data['ship_to_different_address'] ) ) {
				$groups[] = 'shipping';
			}
			foreach ( $groups as $group ) {
				$key = $group . '_' . AddressLocale::FIELD;
				if ( 'NG' !== ( $data[ $group . '_country' ] ?? '' ) ) {
					continue;
				}
				$problem = $checker->format_problem( (string) ( $data[ $key ] ?? '' ) );
				if ( null !== $problem ) {
					$errors->add( $key . '_validation', $problem, array( 'id' => $key ) );
				}
			}
		} catch ( Throwable ) {
			// A failure of the plugin never stops an order. Only a wrong format does.
			return;
		}
	}

	/**
	 * Checks the postcodes of the new order. WooCommerce saved each field as order meta, so this
	 * reads that meta, then removes it: the plugin's own meta keys hold the postcodes.
	 *
	 * @param WC_Order $order The new order.
	 */
	public static function record( WC_Order $order ): void {
		try {
			$typed = array();
			foreach ( array( 'billing', 'shipping' ) as $group ) {
				$meta_key        = '_' . $group . '_' . AddressLocale::FIELD;
				$typed[ $group ] = (string) $order->get_meta( $meta_key );
				$order->delete_meta_data( $meta_key );
			}
			( new OrderPostcodes( Plugin::checker() ) )->record( $order, $typed );
			$order->save();
		} catch ( Throwable $failure ) {
			// Nothing here may stop an order or break the thank-you page.
			try {
				wc_get_logger()->warning(
					sprintf(
						'Order %d: the plugin could not check the postcodes. It failed with %s.',
						$order->get_id(),
						$failure::class
					),
					array( 'source' => 'gatepost' )
				);
			} catch ( Throwable ) {
				return;
			}
		}
	}
}
