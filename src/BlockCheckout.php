<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use Throwable;
use WC_Order;
use WP_Error;

/**
 * Adds the postcode field gatepost/postcode to the address forms of the checkout block. A rule
 * hides it unless the address is in Nigeria.
 */
final class BlockCheckout {

	const FIELD = 'gatepost/postcode';

	/**
	 * Adds the hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_init', array( self::class, 'register_field' ) );
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			array( self::class, 'record' )
		);
	}

	/**
	 * Registers the field. WooCommerce evaluates the hidden rule for the billing and the shipping
	 * address one at a time, and a hidden field is never required. The required setting applies
	 * from the next request.
	 */
	public static function register_field(): void {
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => self::FIELD,
				'label'             => __( 'Postcode', 'gatepost-postcode-for-woocommerce' ),
				'location'          => 'address',
				'type'              => 'text',
				'required'          => Settings::load()->required,
				'hidden'            => array(
					'type'       => 'object',
					'properties' => array(
						'customer' => array(
							'properties' => array(
								'address' => array(
									'properties' => array(
										'country' => array( 'not' => array( 'const' => 'NG' ) ),
									),
								),
							),
						),
					),
				),
				'attributes'        => array(
					'autocomplete'   => 'postal-code',
					'autocapitalize' => 'characters',
				),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'validate_callback' => array( self::class, 'validate' ),
			)
		);
	}

	/**
	 * Stores a postcode in canonical form, such as FC-01-Z99-ZZ-01. A failure of the plugin
	 * leaves the text as the customer typed it.
	 *
	 * @param string $value The text in the field.
	 */
	public static function sanitize( string $value ): string {
		try {
			return ( new Checker( Settings::load()->accept_legacy ) )->stored_form( $value );
		} catch ( Throwable ) {
			return $value;
		}
	}

	/**
	 * Rejects a postcode with the wrong format. A failure of the plugin accepts the postcode,
	 * because only a wrong format may stop an order.
	 *
	 * @param string $value The text in the field, after sanitize().
	 * @return WP_Error|true
	 */
	public static function validate( string $value ): WP_Error|bool {
		try {
			$checker = new Checker( Settings::load()->accept_legacy );
			$problem = $checker->format_problem( $value );
		} catch ( Throwable ) {
			return true;
		}
		return null === $problem ? true : new WP_Error( 'gatepost_postcode_format', $problem );
	}

	/**
	 * Checks the postcodes of the new order, and saves it.
	 *
	 * @param WC_Order $order The order that the checkout block created.
	 */
	public static function record( WC_Order $order ): void {
		try {
			$fields = Package::container()->get( CheckoutFields::class );
			$typed  = array();
			foreach ( array( 'billing', 'shipping' ) as $group ) {
				$text            = $fields->get_field_from_object( self::FIELD, $order, $group );
				$typed[ $group ] = (string) $text;
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
