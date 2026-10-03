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
		try {
			$this->store( $order, $typed );
		} catch ( Throwable $failure ) {
			// Nothing here may stop an order or break the thank-you page.
			self::warn_of(
				sprintf( 'Order %d: the plugin could not store the postcodes.', $order->get_id() ),
				$failure
			);
			self::keep_typed_safely( $order, $typed );
		}
	}

	/**
	 * Calls keep_typed() and never throws.
	 *
	 * @param WC_Order              $order The new order.
	 * @param array<string, string> $typed The text of each postcode field, by address.
	 */
	public static function keep_typed_safely( WC_Order $order, array $typed ): void {
		try {
			self::keep_typed( $order, $typed );
		} catch ( Throwable ) {
			return;
		}
	}

	/**
	 * Checks each postcode once and writes the results.
	 *
	 * @param WC_Order              $order The new order.
	 * @param array<string, string> $typed The text of each postcode field.
	 */
	private function store( WC_Order $order, array $typed ): void {
		$checks = array();
		foreach ( $typed as $group => $text ) {
			if ( 'billing' !== $group && 'shipping' !== $group ) {
				continue;
			}
			$country = 'billing' === $group
				? $order->get_billing_country()
				: $order->get_shipping_country();
			if ( 'NG' !== $country || '' === trim( $text ) ) {
				continue;
			}
			$form  = $this->checker->stored_form( $text );
			$fresh = ! isset( $checks[ $form ] );
			$check = $checks[ $form ] ?? $this->checker->check( $text );
			if ( null === $check ) {
				continue;
			}
			$checks[ $form ] = $check;
			$this->write( $order, $group, $check, $fresh );
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
	 * @param bool     $fresh True for the first address of a lookup, which logs a failure.
	 */
	private function write( WC_Order $order, string $group, Check $check, bool $fresh ): void {
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
		if ( null !== $check->local_failure ) {
			$order->add_order_note( Notes::not_checked_by_plugin( $group, $redacted ) );
			$cause = 'The plugin failed with ' . $check->local_failure . '.';
		} elseif ( null !== $check->error ) {
			$order->add_order_note( Notes::not_checked( $group, $redacted, $check->error ) );
			$cause = 'The gateway client gave ' . $check->error->value . '.';
		} else {
			return;
		}
		if ( $fresh ) {
			self::warn(
				sprintf(
					'Order %d: the %s postcode %s was not checked. %s',
					$order->get_id(),
					$group,
					$redacted,
					$cause
				)
			);
		}
	}

	/**
	 * Keeps the postcodes that the plugin did not store, as the customer typed them. A Nigerian
	 * address that has no plugin postcode, or no native postcode, gets the typed text as its
	 * native postcode, and the order gets the status unchecked. The caller saves the order.
	 *
	 * @param WC_Order              $order The new order.
	 * @param array<string, string> $typed The text of each postcode field, by address.
	 */
	public static function keep_typed( WC_Order $order, array $typed ): void {
		$kept = false;
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			$text = trim( $typed[ $group ] ?? '' );
			if ( '' === $text ) {
				continue;
			}
			$billing = 'billing' === $group;
			$country = $billing ? $order->get_billing_country() : $order->get_shipping_country();
			$native  = $billing ? $order->get_billing_postcode() : $order->get_shipping_postcode();
			$stored  = (string) $order->get_meta( self::meta_key( $group ) );
			if ( 'NG' !== $country || ( '' !== $stored && '' !== $native ) ) {
				continue;
			}
			if ( $billing ) {
				$order->set_billing_postcode( $text );
			} else {
				$order->set_shipping_postcode( $text );
			}
			$kept = true;
		}
		if ( $kept ) {
			$order->update_meta_data( self::CHECK_META, CheckStatus::Unchecked->value );
		}
	}

	/**
	 * Handles a failure of the plugin while it checks a new order: logs one warning, keeps the
	 * typed postcodes as the native postcodes, and saves the order. It never throws, because
	 * nothing here may stop an order or break the thank-you page.
	 *
	 * @param WC_Order              $order   The new order.
	 * @param array<string, string> $typed   The text of each postcode field, by address.
	 * @param Throwable             $failure The failure.
	 */
	public static function recover( WC_Order $order, array $typed, Throwable $failure ): void {
		self::warn_of(
			sprintf( 'Order %d: the plugin could not check the postcodes.', $order->get_id() ),
			$failure
		);
		self::keep_typed_safely( $order, $typed );
		try {
			$order->save();
		} catch ( Throwable ) {
			return;
		}
	}

	/**
	 * Logs a failure of the plugin as one warning. It names the class of the failure and never
	 * its message, which can hold a URL or a postcode.
	 *
	 * @param string    $what    What the plugin tried to do, as a sentence.
	 * @param Throwable $failure The failure.
	 */
	public static function warn_of( string $what, Throwable $failure ): void {
		self::warn( $what . ' It failed with ' . $failure::class . '.' );
	}

	/**
	 * Writes one warning to the WooCommerce log. A broken log never stops the order.
	 *
	 * @param string $message The line. It holds no key and no full postcode.
	 */
	private static function warn( string $message ): void {
		try {
			wc_get_logger()->warning( $message, array( 'source' => 'gatepost' ) );
		} catch ( Throwable ) {
			return;
		}
	}
}
