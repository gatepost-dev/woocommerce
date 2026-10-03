<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

use WC_Order;

/**
 * Stores the checked postcodes of a new order, in both checkouts. It copies each postcode into
 * WooCommerce's own postcode of the address, so that shipping plugins see it.
 */
final class OrderPostcodes {

	const CHECK_META = '_gatepost_postcode_check';

	/**
	 * Builds the recorder.
	 *
	 * @param Checker $checker Checks each postcode.
	 */
	public function __construct( private readonly Checker $checker ) {
	}

	/**
	 * Gives the meta key that holds the postcode of one address.
	 *
	 * @param string $group The address: billing or shipping.
	 */
	public static function meta_key( string $group ): string {
		return '_gatepost_' . $group . '_postcode';
	}

	/**
	 * Checks the postcodes and writes them to the order. The caller saves the order. Two
	 * addresses with the same postcode share one lookup.
	 *
	 * @param WC_Order              $order The new order.
	 * @param array<string, string> $typed The text of each postcode field, by address: billing or
	 *                                     shipping.
	 */
	public function record( WC_Order $order, array $typed ): void {
		$checks = array();
		foreach ( $typed as $group => $text ) {
			$country = 'billing' === $group
				? $order->get_billing_country()
				: $order->get_shipping_country();
			if ( 'NG' !== $country || '' === trim( $text ) ) {
				continue;
			}
			$form  = $this->checker->stored_form( $text );
			$check = $checks[ $form ] ?? $this->checker->check( $text );
			if ( null === $check ) {
				continue;
			}
			$checks[ $form ] = $check;
			$this->write( $order, $group, $check );
		}
		if ( array() !== $checks ) {
			$statuses = array_map( static fn( Check $check ) => $check->status, $checks );
			$status   = CheckStatus::for_order( array_values( $statuses ) );
			$order->update_meta_data( self::CHECK_META, $status->value );
		}
	}

	/**
	 * Writes one checked postcode, and leaves a note on the order when the postcode needs a
	 * person's attention.
	 *
	 * @param WC_Order $order The new order.
	 * @param string   $group The address: billing or shipping.
	 * @param Check    $check The outcome of the check.
	 */
	private function write( WC_Order $order, string $group, Check $check ): void {
		$order->update_meta_data( self::meta_key( $group ), $check->postcode );
		if ( 'billing' === $group ) {
			$order->set_billing_postcode( $check->postcode );
		} else {
			$order->set_shipping_postcode( $check->postcode );
		}
		if ( null === $check->parsed ) {
			return;
		}
		$redacted = $check->parsed->redact();
		if ( CheckStatus::Invalid === $check->status ) {
			$order->add_order_note( Notes::not_found( $group, $redacted ) );
		}
		if ( null !== $check->error ) {
			$order->add_order_note( Notes::not_checked( $group, $redacted, $check->error ) );
			wc_get_logger()->warning(
				sprintf(
					'Order %d: the %s postcode %s was not checked. The gateway client gave %s.',
					$order->get_id(),
					$group,
					$redacted,
					$check->error->value
				),
				array( 'source' => 'gatepost' )
			);
		}
	}
}
