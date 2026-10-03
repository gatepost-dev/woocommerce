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
	 * The two addresses of an order.
	 *
	 * @var array<int, string>
	 */
	const GROUPS = array( 'billing', 'shipping' );

	/**
	 * Where WooCommerce saves the field of the classic checkout, for one address.
	 */
	const CLASSIC_COPY = '_%s_gatepost_postcode';

	/**
	 * Where WooCommerce saves the field of the checkout block, for one address.
	 */
	const BLOCK_COPY = '_wc_%s/gatepost/postcode';

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
	 * Gives every meta key that can hold the full postcode of one address: the plugin's own key,
	 * and the copies that each checkout leaves for WooCommerce.
	 *
	 * @param string $group The address: billing or shipping.
	 * @return array<int, string>
	 */
	public static function postcode_keys( string $group ): array {
		return array(
			self::meta_key( $group ),
			sprintf( self::CLASSIC_COPY, $group ),
			sprintf( self::BLOCK_COPY, $group ),
		);
	}

	/**
	 * Checks the postcodes of a new order. The checker, and with it the secret key, exists only
	 * for an order that has a postcode in a Nigerian address. The caller saves the order.
	 *
	 * @param WC_Order              $order The new order.
	 * @param array<string, string> $typed The text of each postcode field, by address.
	 */
	public static function record_new_order( WC_Order $order, array $typed ): void {
		if ( array() !== self::nigerian_texts( $order, $typed ) ) {
			( new self( Plugin::checker() ) )->record( $order, $typed );
			return;
		}
		foreach ( self::GROUPS as $group ) {
			$order->delete_meta_data( self::meta_key( $group ) );
		}
		$order->delete_meta_data( self::CHECK_META );
	}

	/**
	 * Gives the name of a failure's class for the log. The name of an anonymous class holds the
	 * path of the file that declared it, so the name stops at "@anonymous".
	 *
	 * @param Throwable $failure The failure.
	 */
	public static function failure_name( Throwable $failure ): string {
		$name = $failure::class;
		$cut  = strpos( $name, '@anonymous' );
		return false === $cut ? $name : substr( $name, 0, $cut + strlen( '@anonymous' ) );
	}

	/**
	 * Removes the copies that WooCommerce saved for a field, when they are empty, when the address
	 * is not in Nigeria, or, for a classic order, when the plugin stored the postcode under its
	 * own key. A Nigerian address keeps a copy that holds text which the plugin did not store.
	 *
	 * @param WC_Order $order       The new order.
	 * @param string   $pattern     The meta key of the copy, with %s for the address.
	 * @param bool     $when_stored True to remove the copy of an address that has a stored
	 *                              postcode.
	 */
	public static function drop_copies(
		WC_Order $order,
		string $pattern,
		bool $when_stored
	): void {
		foreach ( self::GROUPS as $group ) {
			$key    = sprintf( $pattern, $group );
			$stored = '' !== (string) $order->get_meta( self::meta_key( $group ) );
			$drop   = '' === trim( (string) $order->get_meta( $key ) )
				|| 'NG' !== self::country( $order, $group )
				|| ( $when_stored && $stored );
			if ( $drop ) {
				$order->delete_meta_data( $key );
			}
		}
	}

	/**
	 * Gives the text of each postcode field whose address is in Nigeria and which has text.
	 *
	 * @param WC_Order              $order The order.
	 * @param array<string, string> $typed The text of each postcode field, by address.
	 * @return array<string, string>
	 */
	private static function nigerian_texts( WC_Order $order, array $typed ): array {
		$texts = array();
		foreach ( self::GROUPS as $group ) {
			$text = $typed[ $group ] ?? '';
			if ( 'NG' === self::country( $order, $group ) && '' !== trim( $text ) ) {
				$texts[ $group ] = $text;
			}
		}
		return $texts;
	}

	/**
	 * Gives the country of one address of an order.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $group The address: billing or shipping.
	 */
	private static function country( WC_Order $order, string $group ): string {
		return 'billing' === $group
			? $order->get_billing_country()
			: $order->get_shipping_country();
	}

	/**
	 * Checks the postcodes and writes them to the order. The caller saves the order. Two
	 * addresses with the same postcode share one lookup, and an order that already holds the
	 * result of a lookup for the same postcodes does not look them up again.
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
		$texts = self::nigerian_texts( $order, $typed );
		$forms = array_map( array( $this->checker, 'stored_form' ), $texts );
		$held  = array();
		foreach ( self::GROUPS as $group ) {
			if ( '' !== (string) $order->get_meta( self::meta_key( $group ) ) ) {
				$held[] = $group;
			}
		}
		if ( self::already_checked( $order, $forms, $held ) ) {
			foreach ( $forms as $group => $form ) {
				self::set_address_postcode( $order, $group, $form );
			}
			return;
		}
		$checks = $this->look_up_all( $order, $texts, $forms );
		if ( array() === $checks ) {
			$order->delete_meta_data( self::CHECK_META );
			return;
		}
		$statuses = array_map( static fn( Check $check ) => $check->status, $checks );
		$status   = CheckStatus::for_order( array_values( $statuses ) );
		$order->update_meta_data( self::CHECK_META, $status->value );
	}

	/**
	 * Checks each Nigerian postcode once, writes the results, and forgets the stored postcode of
	 * an address that the plugin cannot check.
	 *
	 * @param WC_Order              $order The new order.
	 * @param array<string, string> $texts The text of each Nigerian postcode, by address.
	 * @param array<string, string> $forms The stored form of each text, by address.
	 * @return array<string, Check> The check of each distinct stored form.
	 */
	private function look_up_all( WC_Order $order, array $texts, array $forms ): array {
		$checks = array();
		foreach ( self::GROUPS as $group ) {
			$form  = $forms[ $group ] ?? null;
			$fresh = null !== $form && ! isset( $checks[ $form ] );
			$check = null;
			if ( null !== $form ) {
				$check = $checks[ $form ] ?? $this->checker->check( $texts[ $group ] );
			}
			if ( null === $check ) {
				// An address that is not checkable keeps no postcode from an earlier attempt.
				$order->delete_meta_data( self::meta_key( $group ) );
				continue;
			}
			$checks[ $form ] = $check;
			$this->write( $order, $group, $check, $fresh );
		}
		return $checks;
	}

	/**
	 * Tells whether the order already holds the result of a lookup for these postcodes: the same
	 * stored form for each address, and a status of valid or invalid. A failed or skipped check
	 * (error or unchecked) is tried again. A payment retry reuses the order of the failed payment,
	 * and without this memory each retry would use the store's rate limit on NIPOST's gateway.
	 *
	 * @param WC_Order              $order The order.
	 * @param array<string, string> $forms The stored form of each Nigerian postcode, by address.
	 * @param array<int, string>    $held  The addresses that hold a stored postcode now.
	 */
	private static function already_checked( WC_Order $order, array $forms, array $held ): bool {
		if ( array() === $forms ) {
			return false;
		}
		$groups = array_keys( $forms );
		sort( $groups );
		sort( $held );
		$status = CheckStatus::tryFrom( (string) $order->get_meta( self::CHECK_META ) );
		$known  = array( CheckStatus::Valid, CheckStatus::Invalid );
		if ( $groups !== $held || ! in_array( $status, $known, true ) ) {
			return false;
		}
		foreach ( $forms as $group => $form ) {
			if ( (string) $order->get_meta( self::meta_key( $group ) ) !== $form ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Copies a postcode into WooCommerce's own postcode of one address.
	 *
	 * @param WC_Order $order    The order.
	 * @param string   $group    The address: billing or shipping.
	 * @param string   $postcode The postcode to show.
	 */
	private static function set_address_postcode(
		WC_Order $order,
		string $group,
		string $postcode
	): void {
		if ( 'billing' === $group ) {
			$order->set_billing_postcode( $postcode );
		} else {
			$order->set_shipping_postcode( $postcode );
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
		self::set_address_postcode( $order, $group, $check->postcode );
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
		foreach ( self::GROUPS as $group ) {
			$text = trim( $typed[ $group ] ?? '' );
			if ( '' === $text ) {
				continue;
			}
			$native = 'billing' === $group
				? $order->get_billing_postcode()
				: $order->get_shipping_postcode();
			$stored = (string) $order->get_meta( self::meta_key( $group ) );
			$done   = '' !== $stored && '' !== $native;
			if ( 'NG' !== self::country( $order, $group ) || $done ) {
				continue;
			}
			self::set_address_postcode( $order, $group, $text );
			$kept = true;
		}
		if ( $kept ) {
			// A postcode that the plugin did check keeps its stronger status: invalid or error.
			$current  = CheckStatus::tryFrom( (string) $order->get_meta( self::CHECK_META ) );
			$statuses = array_filter( array( $current, CheckStatus::Unchecked ) );
			$status   = CheckStatus::for_order( array_values( $statuses ) );
			$order->update_meta_data( self::CHECK_META, $status->value );
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
		self::warn( $what . ' It failed with ' . self::failure_name( $failure ) . '.' );
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
