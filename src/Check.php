<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\ErrorCode;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Postcode;

/**
 * The outcome of one postcode check after the customer placed the order.
 */
final class Check {

	/**
	 * Builds the outcome.
	 *
	 * @param string         $postcode The text to store: a canonical postcode, or the six digits
	 *                                 of an old postcode that the store accepts.
	 * @param CheckStatus    $status   What the check found.
	 * @param Postcode|null  $parsed   The postcode, or null for an old 6-digit postcode.
	 * @param ErrorCode|null $error    Why the lookup failed, when the gateway client gave a code.
	 * @param string|null    $local_failure The class of an exception from the plugin's own side,
	 *                                 never its message, because a message can quote the URL
	 *                                 that holds the postcode.
	 */
	public function __construct(
		public readonly string $postcode,
		public readonly CheckStatus $status,
		public readonly ?Postcode $parsed = null,
		public readonly ?ErrorCode $error = null,
		public readonly ?string $local_failure = null,
	) {
	}
}
