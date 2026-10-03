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
		} catch ( Throwable $failure ) {
			// A failure of the plugin never stops an order. Only a wrong format does.
			OrderPostcodes::warn(
				'The plugin could not check the format of a postcode. It failed with '
					. $failure::class . '.'
			);
		}
	}

	/**
	 * Checks the postcodes of the new order. WooCommerce saved each field as order meta. This
	 * reads that meta, and removes it only for an address whose postcode the plugin stored under
	 * its own key. On any failure, the typed text stays as the native postcode of the address.
	 *
	 * @param WC_Order $order The new order.
	 */
	public static function record( WC_Order $order ): void {
		$typed = array();
		try {
			foreach ( array( 'billing', 'shipping' ) as $group ) {
				$typed[ $group ] = (string) $order->get_meta( self::meta_key( $group ) );
			}
			( new OrderPostcodes( Plugin::checker() ) )->record( $order, $typed );
			OrderPostcodes::keep_typed( $order, $typed );
			foreach ( array( 'billing', 'shipping' ) as $group ) {
				if ( '' !== (string) $order->get_meta( OrderPostcodes::meta_key( $group ) ) ) {
					$order->delete_meta_data( self::meta_key( $group ) );
				}
			}
			$order->save();
		} catch ( Throwable $failure ) {
			// Nothing here may stop an order or break the thank-you page.
			OrderPostcodes::warn(
				sprintf(
					'Order %d: the plugin could not check the postcodes. It failed with %s.',
					$order->get_id(),
					$failure::class
				)
			);
			self::save_typed( $order, $typed );
		}
	}

	/**
	 * Gives the meta key where WooCommerce saves a classic field.
	 *
	 * @param string $group The address: billing or shipping.
	 */
	private static function meta_key( string $group ): string {
		return '_' . $group . '_' . AddressLocale::FIELD;
	}

	/**
	 * Keeps the typed text after a failure, and saves the order. It never throws.
	 *
	 * @param WC_Order              $order The new order.
	 * @param array<string, string> $typed The text of each postcode field.
	 */
	private static function save_typed( WC_Order $order, array $typed ): void {
		OrderPostcodes::keep_typed_safely( $order, $typed );
		try {
			$order->save();
		} catch ( Throwable ) {
			return;
		}
	}
}
