<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Throwable;
use WC_Customer;
use WC_Order;

/**
 * Answers export and erase requests. A postcode of the new format names one building, so it is
 * personal data. WooCommerce's own tools page through the orders and the customers, and these
 * hooks add the plugin's data to what they export and remove it with what they erase. The check
 * status is not personal data, so an erase request keeps it.
 */
final class Privacy {

	/**
	 * Adds the hooks.
	 */
	public static function register(): void {
		$class = self::class;
		add_action( 'admin_init', array( $class, 'add_policy_text' ) );
		add_filter(
			'woocommerce_privacy_export_order_personal_data',
			array( $class, 'export_order' ),
			10,
			2
		);
		add_action(
			'woocommerce_privacy_remove_order_personal_data',
			array( $class, 'erase_order' )
		);
		add_filter(
			'woocommerce_privacy_export_customer_personal_data',
			array( $class, 'export_customer' ),
			10,
			2
		);
		add_filter(
			'woocommerce_privacy_erase_personal_data_customer',
			array( $class, 'erase_customer' ),
			10,
			2
		);
	}

	/**
	 * Adds the suggested text for the privacy policy page of the store.
	 */
	public static function add_policy_text(): void {
		wp_add_privacy_policy_content(
			__( 'Gatepost Postcode for WooCommerce', 'gatepost-postcode-for-woocommerce' ),
			wp_kses_post( self::policy_text() )
		);
	}

	/**
	 * Gives the suggested text. It names the lookup, what it sends and to whom. Each paragraph
	 * is a list of whole sentences, and the paragraph joins them with a space.
	 */
	public static function policy_text(): string {
		$paragraphs = array(
			array(
				__(
					'A customer with a Nigerian address can enter a postcode at checkout.',
					'gatepost-postcode-for-woocommerce'
				),
				__(
					'The store keeps it with the order and in the saved address of an account.',
					'gatepost-postcode-for-woocommerce'
				),
			),
			array(
				__(
					'The store owner can enter a secret key from NIPOST, the postal service.',
					'gatepost-postcode-for-woocommerce'
				),
				__(
					'The store then sends each new postcode to api.postcode.gov.ng.',
					'gatepost-postcode-for-woocommerce'
				),
				__(
					'The request holds the postcode and the secret key.',
					'gatepost-postcode-for-woocommerce'
				),
				__(
					'It holds no name, email address or street.',
					'gatepost-postcode-for-woocommerce'
				),
				__(
					'NIPOST also sees the IP address of the store\'s server.',
					'gatepost-postcode-for-woocommerce'
				),
			),
			array(
				__(
					'The store keeps the result of the check with the order.',
					'gatepost-postcode-for-woocommerce'
				),
				__(
					'The result is valid, invalid, unchecked or error.',
					'gatepost-postcode-for-woocommerce'
				),
				__(
					'The store keeps nothing else from the reply.',
					'gatepost-postcode-for-woocommerce'
				),
				__(
					'NIPOST\'s privacy policy covers the requests that NIPOST receives.',
					'gatepost-postcode-for-woocommerce'
				),
			),
		);
		$html       = '';
		foreach ( $paragraphs as $sentences ) {
			$html .= '<p>' . esc_html( implode( ' ', $sentences ) ) . '</p>';
		}
		return $html;
	}

	/**
	 * Adds the postcodes of an order to its export.
	 *
	 * @param mixed $data  The name and value pairs of the order.
	 * @param mixed $order The order.
	 * @return mixed
	 */
	public static function export_order( $data, $order ) {
		if ( ! is_array( $data ) || ! $order instanceof WC_Order ) {
			return $data;
		}
		foreach ( OrderPostcodes::GROUPS as $group ) {
			foreach ( OrderPostcodes::postcode_keys( $group ) as $key ) {
				$value = (string) $order->get_meta( $key );
				if ( '' !== $value ) {
					$data[] = array(
						'name'  => self::label( $group ),
						'value' => $value,
					);
					break;
				}
			}
		}
		return $data;
	}

	/**
	 * Removes every full postcode of an order, and keeps the check status. WooCommerce has
	 * saved the order and made its own fields anonymous before this runs.
	 *
	 * @param mixed $order The order.
	 */
	public static function erase_order( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		try {
			foreach ( OrderPostcodes::GROUPS as $group ) {
				foreach ( OrderPostcodes::postcode_keys( $group ) as $key ) {
					$order->delete_meta_data( $key );
				}
				// The memory of an address holds a hash of its postcode.
				$order->delete_meta_data( OrderPostcodes::memory_key( $group ) );
			}
			$order->save();
		} catch ( Throwable $failure ) {
			OrderPostcodes::warn_of(
				'The plugin could not erase the postcodes of an order.',
				$failure
			);
		}
	}

	/**
	 * Adds the postcodes of the address book to the export of a customer.
	 *
	 * @param mixed $data     The name and value pairs of the customer.
	 * @param mixed $customer The customer.
	 * @return mixed
	 */
	public static function export_customer( $data, $customer ) {
		if ( ! is_array( $data ) || ! $customer instanceof WC_Customer ) {
			return $data;
		}
		foreach ( OrderPostcodes::GROUPS as $group ) {
			$value = '';
			foreach ( self::customer_keys( $group ) as $key ) {
				$value = '' === $value ? (string) $customer->get_meta( $key ) : $value;
			}
			if ( '' !== $value ) {
				$data[] = array(
					'name'  => self::label( $group ),
					'value' => $value,
				);
			}
		}
		return $data;
	}

	/**
	 * Removes the postcodes of the address book of a customer.
	 *
	 * @param mixed $response The response of WooCommerce's eraser.
	 * @param mixed $customer The customer.
	 * @return mixed
	 */
	public static function erase_customer( $response, $customer ) {
		if ( ! is_array( $response ) || ! $customer instanceof WC_Customer ) {
			return $response;
		}
		try {
			foreach ( OrderPostcodes::GROUPS as $group ) {
				$held = false;
				foreach ( self::customer_keys( $group ) as $key ) {
					$held = $held || '' !== (string) $customer->get_meta( $key );
					$customer->delete_meta_data( $key );
				}
				if ( ! $held ) {
					continue;
				}
				$customer->save();
				/* translators: %s: a piece of data, such as "Billing Nigerian postcode". */
				$removed = __( 'Removed customer "%s"', 'gatepost-postcode-for-woocommerce' );

				$response['messages'][]    = sprintf( $removed, self::label( $group ) );
				$response['items_removed'] = true;
			}
		} catch ( Throwable $failure ) {
			OrderPostcodes::warn_of(
				'The plugin could not erase the postcodes of a customer.',
				$failure
			);
		}
		return $response;
	}

	/**
	 * Gives the user meta keys that can hold the postcode of one address of a customer: the key
	 * of the classic field, and the key that the checkout block saves.
	 *
	 * @param string $group The address: billing or shipping.
	 * @return array<int, string>
	 */
	private static function customer_keys( string $group ): array {
		return array(
			$group . '_' . AddressLocale::FIELD,
			sprintf( OrderPostcodes::BLOCK_COPY, $group ),
		);
	}

	/**
	 * Gives the name that an export shows for the postcode of one address.
	 *
	 * @param string $group The address: billing or shipping.
	 */
	private static function label( string $group ): string {
		return 'billing' === $group
			? __( 'Billing Nigerian postcode', 'gatepost-postcode-for-woocommerce' )
			: __( 'Shipping Nigerian postcode', 'gatepost-postcode-for-woocommerce' );
	}
}
