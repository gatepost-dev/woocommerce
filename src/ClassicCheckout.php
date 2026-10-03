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
	 * Adds an error for each postcode in a Nigerian address that has the wrong format. When the
	 * store requires the postcode, it also adds an error for an empty one. WooCommerce makes the
	 * same check from the address rules, but those rules leave the field optional in a request
	 * that looks like a Store API request, and a classic request can fake that look. So this
	 * check does not depend on the rules.
	 *
	 * @param array<string, mixed> $data   The posted checkout data, after WooCommerce cleaned it.
	 * @param WP_Error             $errors The checkout errors.
	 */
	public static function validate( array $data, WP_Error $errors ): void {
		try {
			$required = Settings::is_required();
			$checker  = new Checker( Settings::accepts_legacy() );
			$groups   = array( 'billing' );
			if ( ! empty( $data['ship_to_different_address'] ) ) {
				$groups[] = 'shipping';
			}
			foreach ( $groups as $group ) {
				$key = $group . '_' . AddressLocale::FIELD;
				if ( 'NG' !== ( $data[ $group . '_country' ] ?? '' ) ) {
					continue;
				}
				$typed   = (string) ( $data[ $key ] ?? '' );
				$missing = $required && '' === trim( $typed );
				$asked   = in_array( $key . '_required', $errors->get_error_codes(), true );
				if ( $missing && ! $asked ) {
					$errors->add(
						$key . '_required',
						self::required_message( $group ),
						array( 'id' => $key )
					);
				}
				$problem = $checker->format_problem( $typed );
				if ( null !== $problem ) {
					$errors->add( $key . '_validation', $problem, array( 'id' => $key ) );
				}
			}
		} catch ( Throwable $failure ) {
			// A failure of the plugin never stops an order. Only a wrong format does.
			OrderPostcodes::warn_of(
				'The plugin could not check the format of a postcode.',
				$failure
			);
		}
	}

	/**
	 * Gives the error for an empty postcode that the store requires. Each address has its own
	 * whole string, so a customer with two Nigerian addresses knows which one to fix.
	 *
	 * @param string $group The address: billing or shipping.
	 */
	private static function required_message( string $group ): string {
		return 'billing' === $group
			? __( 'Billing postcode is required.', 'gatepost-postcode-for-woocommerce' )
			: __( 'Shipping postcode is required.', 'gatepost-postcode-for-woocommerce' );
	}

	/**
	 * Checks the postcodes of the new order. WooCommerce saved each field as order meta. This
	 * reads that meta, and removes it when it is empty, when the address is not in Nigeria, or
	 * when the plugin stored the postcode under its own key. On any failure, the typed text stays
	 * as the native postcode of the address.
	 *
	 * @param mixed $order The new order. Another plugin that fires the hook can pass anything.
	 */
	public static function record( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$typed = array();
		try {
			foreach ( OrderPostcodes::GROUPS as $group ) {
				$copy            = sprintf( OrderPostcodes::CLASSIC_COPY, $group );
				$typed[ $group ] = (string) $order->get_meta( $copy );
			}
			OrderPostcodes::record_new_order( $order, $typed );
			OrderPostcodes::keep_typed( $order, $typed );
			OrderPostcodes::drop_copies( $order, OrderPostcodes::CLASSIC_COPY, true );
			$order->save();
		} catch ( Throwable $failure ) {
			OrderPostcodes::recover( $order, $typed, $failure );
		}
	}
}
