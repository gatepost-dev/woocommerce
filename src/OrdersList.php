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
use WC_Order;

/**
 * Adds a Postcode column to the orders list. WooCommerce has one list for order tables (HPOS)
 * and another for orders stored as posts, so the column hooks into both.
 */
final class OrdersList {

	const COLUMN = 'gatepost_postcode';

	/**
	 * Adds the hooks.
	 */
	public static function register(): void {
		$tables = 'woocommerce_page_wc-orders';
		$posts  = 'shop_order';
		$class  = self::class;
		add_filter( "manage_{$tables}_columns", array( $class, 'add_column' ) );
		add_action( "manage_{$tables}_custom_column", array( $class, 'show_order' ), 10, 2 );
		add_filter( "manage_edit-{$posts}_columns", array( $class, 'add_column' ) );
		add_action( "manage_{$posts}_posts_custom_column", array( $class, 'show_post' ), 10, 2 );
	}

	/**
	 * Puts the column after the shipping address.
	 *
	 * @param array<string, string> $columns The column titles, by id.
	 * @return array<string, string>
	 */
	public static function add_column( array $columns ): array {
		$title  = __( 'Postcode', 'gatepost-postcode-for-woocommerce' );
		$result = array();
		foreach ( $columns as $id => $column_title ) {
			$result[ $id ] = $column_title;
			if ( 'shipping_address' === $id ) {
				$result[ self::COLUMN ] = $title;
			}
		}
		// A list without a shipping address column gets the column at the end.
		return $result + array( self::COLUMN => $title );
	}

	/**
	 * Prints the cell in the list of order tables.
	 *
	 * @param mixed $column The column id.
	 * @param mixed $order  The order of the row. Another plugin that fires the hook can pass
	 *                      anything.
	 */
	public static function show_order( $column, $order ): void {
		if ( self::COLUMN === $column && $order instanceof WC_Order ) {
			self::print_cell( $order );
		}
	}

	/**
	 * Prints the cell in the list of orders stored as posts.
	 *
	 * @param mixed $column  The column id.
	 * @param mixed $post_id The order id.
	 */
	public static function show_post( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		try {
			$order = wc_get_order( is_numeric( $post_id ) ? (int) $post_id : 0 );
		} catch ( Throwable $failure ) {
			self::log( $failure );
			return;
		}
		if ( $order instanceof WC_Order ) {
			self::print_cell( $order );
		}
	}

	/**
	 * Prints the cell of one order. A failure leaves the cell empty and logs one line, because
	 * nothing here may break the orders list.
	 *
	 * @param WC_Order $order The order of the row.
	 */
	private static function print_cell( WC_Order $order ): void {
		try {
			$cell = self::cell( $order );
		} catch ( Throwable $failure ) {
			self::log( $failure );
			return;
		}
		echo wp_kses( $cell, array( 'br' => array() ) );
	}

	/**
	 * Logs a failure of the column as one warning.
	 *
	 * @param Throwable $failure The failure.
	 */
	private static function log( Throwable $failure ): void {
		OrderPostcodes::warn_of( 'The plugin could not show the Postcode column.', $failure );
	}

	/**
	 * Gives the postcodes of the order and the check status. Two different postcodes each get a
	 * line with the address. When staff changed the postcode of the order after the check, the
	 * cell shows the new postcode and says so, because the check was about the old one. The cell
	 * is empty for an order without a checked postcode.
	 *
	 * @param WC_Order $order The order of the row.
	 */
	public static function cell( WC_Order $order ): string {
		$status = CheckStatus::tryFrom( (string) $order->get_meta( OrderPostcodes::CHECK_META ) );
		$shown  = array();
		$edited = false;
		foreach ( OrderPostcodes::GROUPS as $group ) {
			$stored = (string) $order->get_meta( OrderPostcodes::meta_key( $group ) );
			if ( '' === $stored ) {
				continue;
			}
			$native          = 'billing' === $group
				? $order->get_billing_postcode()
				: $order->get_shipping_postcode();
			$edited          = $edited || ( '' !== $native && $native !== $stored );
			$shown[ $group ] = '' !== $native ? $native : $stored;
		}
		if ( array() === $shown || null === $status ) {
			return '';
		}
		$label = $edited
			? __( 'Changed after the check', 'gatepost-postcode-for-woocommerce' )
			: self::label( $status );
		$lines = array_map( 'esc_html', self::lines( $shown ) );
		return implode( '<br>', $lines ) . '<br>' . esc_html( $label );
	}

	/**
	 * Gives the postcode lines of the cell. One postcode, or two equal ones, give one line.
	 *
	 * @param array<string, string> $shown The postcode of each address.
	 * @return array<int, string>
	 */
	private static function lines( array $shown ): array {
		if ( 1 === count( array_unique( $shown ) ) ) {
			return array( array_values( $shown )[0] );
		}
		/* translators: %s: the billing postcode of the order. */
		$billing = __( 'Billing: %s', 'gatepost-postcode-for-woocommerce' );
		/* translators: %s: the shipping postcode of the order. */
		$shipping = __( 'Shipping: %s', 'gatepost-postcode-for-woocommerce' );
		return array(
			sprintf( $billing, $shown['billing'] ),
			sprintf( $shipping, $shown['shipping'] ),
		);
	}

	/**
	 * Gives the words that the column shows for a status.
	 *
	 * @param CheckStatus $status The check status of the order.
	 */
	private static function label( CheckStatus $status ): string {
		return match ( $status ) {
			CheckStatus::Valid     => __( 'Checked', 'gatepost-postcode-for-woocommerce' ),
			CheckStatus::Invalid   => __( 'Not found', 'gatepost-postcode-for-woocommerce' ),
			CheckStatus::Unchecked => __( 'Not checked', 'gatepost-postcode-for-woocommerce' ),
			CheckStatus::Error     => __( 'Check failed', 'gatepost-postcode-for-woocommerce' ),
		};
	}
}
