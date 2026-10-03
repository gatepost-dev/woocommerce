<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\ErrorCode;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\PostcodeException;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\ParseError;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\ParseErrorCode;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Postcode;
use Throwable;

/**
 * Checks the postcode that a customer types: its format while the customer fills in the form,
 * and NIPOST's record of it after the customer places the order.
 */
final class Checker {

	/**
	 * Builds a checker.
	 *
	 * @param bool                $accept_legacy True when the store accepts an old 6-digit
	 *                                           postcode.
	 * @param PostcodeClient|null $client        The gateway client, or null when the store does
	 *                                           not look up postcodes.
	 */
	public function __construct(
		private readonly bool $accept_legacy,
		private readonly ?PostcodeClient $client = null,
	) {
	}

	/**
	 * Says what is wrong with the format of a postcode, in words for the customer. An empty text
	 * passes, because WooCommerce itself enforces a required field.
	 *
	 * @param string $typed The text in the field.
	 * @return string|null The message, or null when the format is right.
	 */
	public function format_problem( string $typed ): ?string {
		if ( '' === trim( $typed ) ) {
			return null;
		}
		$parsed = Postcode::parse( $typed );
		if ( null === $parsed->error ) {
			return null;
		}
		if ( ParseErrorCode::LegacyCode === $parsed->error->code && $this->accept_legacy ) {
			return null;
		}
		return $this->message( $parsed->error, $typed );
	}

	/**
	 * Gives the form of a postcode to store: the canonical form, or the six digits of an old
	 * postcode that the store accepts. Text that fails the format check stays as it is.
	 *
	 * @param string $typed The text in the field.
	 */
	public function stored_form( string $typed ): string {
		$parsed = Postcode::parse( $typed );
		if ( null !== $parsed->value ) {
			return $parsed->value->canonical;
		}
		if ( $this->accept_legacy && Postcode::isLegacy( $typed ) ) {
			return Postcode::normalize( $typed );
		}
		return $typed;
	}

	/**
	 * Checks a postcode that passed the format check. With a client, the checker asks NIPOST's
	 * gateway whether the postcode exists. A failed lookup of any kind gives the status error,
	 * so that the order still goes through.
	 *
	 * @param string $typed The text in the field.
	 * @return Check|null The outcome, or null for text that fails the format check.
	 */
	public function check( string $typed ): ?Check {
		$parsed = Postcode::parse( $typed );
		if ( null === $parsed->value ) {
			return $this->accept_legacy && Postcode::isLegacy( $typed )
				? new Check( Postcode::normalize( $typed ), CheckStatus::Unchecked )
				: null;
		}
		$postcode = $parsed->value;
		if ( null === $this->client ) {
			return new Check( $postcode->canonical, CheckStatus::Unchecked, $postcode );
		}
		try {
			$found = $this->client->lookup( $postcode, level: 1 )->valid;
		} catch ( PostcodeException $failure ) {
			$error = $failure->errorCode();
			return new Check( $postcode->canonical, CheckStatus::Error, $postcode, $error );
		} catch ( Throwable $failure ) {
			// Any other failure still lets the order go through. Only the class of the exception
			// is kept. Its text stays out, because it can quote the URL.
			return new Check(
				$postcode->canonical,
				CheckStatus::Error,
				$postcode,
				null,
				OrderPostcodes::failure_name( $failure )
			);
		}
		$status = $found ? CheckStatus::Valid : CheckStatus::Invalid;
		return new Check( $postcode->canonical, $status, $postcode );
	}

	/**
	 * Gives the message for a parse error. It says what is wrong and what to do (ERR-4).
	 *
	 * @param ParseError $error The parse error.
	 * @param string     $typed The text in the field.
	 */
	private function message( ParseError $error, string $typed ): string {
		return match ( $error->code ) {
			ParseErrorCode::LegacyCode => __(
				'This is an old 6-digit postcode. Enter the new 11-character postcode.',
				'gatepost-postcode-for-woocommerce'
			),
			ParseErrorCode::BadCharacter => __(
				'A postcode has only letters and digits. Remove the other characters.',
				'gatepost-postcode-for-woocommerce'
			),
			ParseErrorCode::UnknownState => sprintf(
				/* translators: %s: the first two characters of the postcode, such as XX. */
				__(
					'%s is not a Nigerian state code. Check the first two letters.',
					'gatepost-postcode-for-woocommerce'
				),
				substr( Postcode::normalize( $typed ), 0, 2 )
			),
			ParseErrorCode::BadSegment => $this->segment_message( $error->suggestion ),
			ParseErrorCode::Empty, ParseErrorCode::BadLength => __(
				'A postcode has 11 letters and digits. Check that none is missing or extra.',
				'gatepost-postcode-for-woocommerce'
			),
		};
	}

	/**
	 * Gives the message for a postcode with a wrong part. It names the corrected postcode when
	 * the parser found one.
	 *
	 * @param string|null $suggestion The corrected postcode in canonical form.
	 */
	private function segment_message( ?string $suggestion ): string {
		if ( null === $suggestion ) {
			return __(
				'Part of the postcode is not valid. Check each letter and digit.',
				'gatepost-postcode-for-woocommerce'
			);
		}
		return sprintf(
			/* translators: %s: a corrected postcode, such as FC-01-Z99-ZZ-01. */
			__(
				'Part of the postcode is not valid. Did you mean %s?',
				'gatepost-postcode-for-woocommerce'
			),
			$suggestion
		);
	}
}
