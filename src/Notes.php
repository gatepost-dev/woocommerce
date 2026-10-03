<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\ErrorCode;

/**
 * The order notes that tell the store about a postcode that needs a person. A note shows the
 * postcode redacted, because the order holds personal data. The address on the order
 * shows the full postcode.
 */
final class Notes {

	/**
	 * Says that NIPOST has no record of the postcode.
	 *
	 * @param string $group    The address: billing or shipping.
	 * @param string $redacted The redacted postcode, such as FC-01-Z99-ZZ-**.
	 */
	public static function not_found( string $group, string $redacted ): string {
		if ( 'billing' === $group ) {
			/* translators: %s: a postcode with its last part hidden, such as FC-01-Z99-ZZ-**. */
			$note = __(
				'NIPOST has no record of the billing postcode %s. Ask the customer to check it.',
				'gatepost-postcode-for-woocommerce'
			);
		} else {
			/* translators: %s: a postcode with its last part hidden, such as FC-01-Z99-ZZ-**. */
			$note = __(
				'NIPOST has no record of the shipping postcode %s. Ask the customer to check it.',
				'gatepost-postcode-for-woocommerce'
			);
		}
		return sprintf( $note, $redacted );
	}

	/**
	 * Says that the lookup failed, why, and that the order went ahead.
	 *
	 * @param string    $group    The address: billing or shipping.
	 * @param string    $redacted The redacted postcode, such as FC-01-Z99-ZZ-**.
	 * @param ErrorCode $error    Why the lookup failed.
	 */
	public static function not_checked(
		string $group,
		string $redacted,
		ErrorCode $error
	): string {
		if ( 'billing' === $group ) {
			/* translators: %s: a postcode with its last part hidden, such as FC-01-Z99-ZZ-**. */
			$note = __(
				'The billing postcode %s was not checked.',
				'gatepost-postcode-for-woocommerce'
			);
		} else {
			/* translators: %s: a postcode with its last part hidden, such as FC-01-Z99-ZZ-**. */
			$note = __(
				'The shipping postcode %s was not checked.',
				'gatepost-postcode-for-woocommerce'
			);
		}
		$outcome = __(
			'The order went ahead. Check the postcode by hand.',
			'gatepost-postcode-for-woocommerce'
		);
		// The note is three whole sentences. A translation may join them in another way.
		return sprintf( $note, $redacted ) . ' ' . self::reason( $error ) . ' ' . $outcome;
	}

	/**
	 * Says that the plugin itself failed during the lookup, and where to find the details.
	 *
	 * @param string $group    The address: billing or shipping.
	 * @param string $redacted The redacted postcode, such as FC-01-Z99-ZZ-**.
	 */
	public static function not_checked_by_plugin( string $group, string $redacted ): string {
		if ( 'billing' === $group ) {
			/* translators: %s: a postcode with its last part hidden, such as FC-01-Z99-ZZ-**. */
			$note = __(
				'The billing postcode %s was not checked.',
				'gatepost-postcode-for-woocommerce'
			);
		} else {
			/* translators: %s: a postcode with its last part hidden, such as FC-01-Z99-ZZ-**. */
			$note = __(
				'The shipping postcode %s was not checked.',
				'gatepost-postcode-for-woocommerce'
			);
		}
		$cause   = __(
			'The plugin had an error while it checked the postcode.',
			'gatepost-postcode-for-woocommerce'
		);
		$outcome = __(
			'The order went ahead. Check the postcode by hand.',
			'gatepost-postcode-for-woocommerce'
		);
		$where   = __( 'The WooCommerce log has details.', 'gatepost-postcode-for-woocommerce' );
		// The note is four whole sentences. A translation may join them in another way.
		return sprintf( $note, $redacted ) . ' ' . $cause . ' ' . $outcome . ' ' . $where;
	}

	/**
	 * Explains a failed lookup, and what the store can do about it.
	 *
	 * @param ErrorCode $error Why the lookup failed.
	 */
	private static function reason( ErrorCode $error ): string {
		return match ( $error ) {
			ErrorCode::Unauthorized, ErrorCode::Forbidden, ErrorCode::OriginNotAllowed => __(
				'The gateway refused the secret key. Check it in the Nigerian postcodes settings.',
				'gatepost-postcode-for-woocommerce'
			),
			ErrorCode::InsufficientCredits => __(
				'The NIPOST account has no credits left.',
				'gatepost-postcode-for-woocommerce'
			),
			ErrorCode::RateLimited => __(
				'The gateway asked the store to send fewer requests.',
				'gatepost-postcode-for-woocommerce'
			),
			ErrorCode::Timeout, ErrorCode::NetworkError => __(
				'The gateway did not answer.',
				'gatepost-postcode-for-woocommerce'
			),
			ErrorCode::ServerError => __(
				'The gateway had an error. Try again later.',
				'gatepost-postcode-for-woocommerce'
			),
			ErrorCode::InvalidInput => __(
				'The gateway rejected the request. Update the plugin.',
				'gatepost-postcode-for-woocommerce'
			),
			ErrorCode::UnexpectedResponse => __(
				'The gateway gave an answer that the plugin cannot read.',
				'gatepost-postcode-for-woocommerce'
			),
		};
	}
}
