<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Gatepost\WooCommerce\Notes;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\ErrorCode;
use WP_UnitTestCase;

/**
 * The order notes about a postcode that needs a person.
 */
final class NotesTest extends WP_UnitTestCase {

	public function test_asks_the_store_to_contact_the_customer_about_an_unknown_postcode(): void {
		$this->assertSame(
			'NIPOST has no record of the shipping postcode FC-01-Z99-ZZ-**. '
				. 'Ask the customer to check it.',
			Notes::not_found( 'shipping', 'FC-01-Z99-ZZ-**' )
		);
	}

	public function test_says_why_a_lookup_failed_and_that_the_order_went_ahead(): void {
		$this->assertSame(
			'The billing postcode FC-01-Z99-ZZ-** was not checked. The gateway did not answer. '
				. 'The order went ahead. Check the postcode by hand.',
			Notes::not_checked( 'billing', 'FC-01-Z99-ZZ-**', ErrorCode::Timeout )
		);
	}

	/**
	 * Each error code has its own reason, so the store knows what to fix.
	 *
	 * @dataProvider reasons
	 *
	 * @param ErrorCode $error  The error code.
	 * @param string    $reason The reason that the note gives.
	 */
	public function test_gives_a_reason_for_each_error_code(
		ErrorCode $error,
		string $reason
	): void {
		$note = Notes::not_checked( 'billing', 'FC-01-Z99-ZZ-**', $error );
		$this->assertStringContainsString( $reason, $note );
	}

	/**
	 * Error codes and their reasons.
	 *
	 * @return array<string, array{ErrorCode, string}>
	 */
	public static function reasons(): array {
		$key    = 'The gateway refused the secret key.';
		$reader = 'The gateway gave an answer that the plugin cannot read.';
		return array(
			'unauthorized'        => array( ErrorCode::Unauthorized, $key ),
			'forbidden'           => array( ErrorCode::Forbidden, $key ),
			'origin not allowed'  => array( ErrorCode::OriginNotAllowed, $key ),
			'no credits'          => array( ErrorCode::InsufficientCredits, 'no credits left' ),
			'rate limited'        => array( ErrorCode::RateLimited, 'send fewer requests' ),
			'timeout'             => array( ErrorCode::Timeout, 'did not answer' ),
			'network error'       => array( ErrorCode::NetworkError, 'did not answer' ),
			'server error'        => array( ErrorCode::ServerError, $reader ),
			'unexpected response' => array( ErrorCode::UnexpectedResponse, $reader ),
			'invalid input'       => array( ErrorCode::InvalidInput, $reader ),
		);
	}
}
