<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Gatepost\WooCommerce\CheckStatus;
use WP_UnitTestCase;

/**
 * The status of an order with two postcodes.
 */
final class CheckStatusTest extends WP_UnitTestCase {

	public function test_gives_an_order_the_status_that_needs_the_most_attention(): void {
		$this->assertSame(
			CheckStatus::Invalid,
			CheckStatus::for_order( array( CheckStatus::Error, CheckStatus::Invalid ) )
		);
		$this->assertSame(
			CheckStatus::Error,
			CheckStatus::for_order( array( CheckStatus::Unchecked, CheckStatus::Error ) )
		);
		$this->assertSame(
			CheckStatus::Unchecked,
			CheckStatus::for_order( array( CheckStatus::Valid, CheckStatus::Unchecked ) )
		);
		$this->assertSame(
			CheckStatus::Valid,
			CheckStatus::for_order( array( CheckStatus::Valid, CheckStatus::Valid ) )
		);
	}
}
