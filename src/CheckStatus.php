<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

/**
 * What the plugin learned about a postcode. The value is the one that the order meta
 * `_gatepost_postcode_check` holds.
 */
enum CheckStatus: string {
	case Valid     = 'valid';
	case Invalid   = 'invalid';
	case Unchecked = 'unchecked';
	case Error     = 'error';

	/**
	 * The status of an order with two postcodes is the status that needs the most attention.
	 * A postcode that NIPOST does not know comes first, because only the customer can fix it.
	 *
	 * An order with no postcode status is unchecked.
	 *
	 * @param array<int, CheckStatus> $statuses The status of each postcode of the order.
	 */
	public static function for_order( array $statuses ): self {
		if ( array() === $statuses ) {
			return self::Unchecked;
		}
		foreach ( array( self::Invalid, self::Error, self::Unchecked ) as $status ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $status;
			}
		}
		return self::Valid;
	}
}
