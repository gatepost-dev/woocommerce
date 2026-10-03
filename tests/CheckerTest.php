<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Gatepost\WooCommerce\Checker;
use Gatepost\WooCommerce\CheckStatus;
use Gatepost\WooCommerce\Plugin;
use Gatepost\WooCommerce\Settings;
use Gatepost\WooCommerce\Tests\Support\FakeGateway;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\Internal\Timer;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\WooCommerce\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use Gatepost\WooCommerce\Vendor\Psr\Http\Client\ClientInterface;
use Gatepost\WooCommerce\Vendor\Psr\Http\Message\RequestInterface;
use Gatepost\WooCommerce\Vendor\Psr\Http\Message\ResponseInterface;
use Gatepost\WooCommerce\WpTransport;
use RuntimeException;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\ErrorCode;
use WP_UnitTestCase;

/**
 * The format check at checkout, and the lookup after the order.
 */
final class CheckerTest extends WP_UnitTestCase {

	/**
	 * Builds a checker that looks up postcodes with a live key, as the plugin does.
	 *
	 * @param bool $accept_legacy True when the store accepts old postcodes.
	 */
	private static function looking_up( bool $accept_legacy = true ): Checker {
		$settings = new Settings( 'nipost_live_example', false, $accept_legacy, true );
		return new Checker( $accept_legacy, Plugin::client( $settings ) );
	}

	/**
	 * Each spelling that the core accepts passes the format check.
	 *
	 * @dataProvider spellings
	 *
	 * @param string $typed A spelling of FC-01-Z99-ZZ-01.
	 */
	public function test_accepts_each_spelling_of_a_postcode( string $typed ): void {
		$checker = new Checker( true );
		$this->assertNull( $checker->format_problem( $typed ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $checker->stored_form( $typed ) );
	}

	/**
	 * Spellings of one postcode.
	 *
	 * @return array<string, array{string}>
	 */
	public static function spellings(): array {
		return array(
			'canonical'      => array( 'FC-01-Z99-ZZ-01' ),
			'compact'        => array( 'FC01Z99ZZ01' ),
			'display'        => array( 'FC 01 Z99 ZZ 01' ),
			'lower case'     => array( 'fc01z99zz01' ),
			'en dashes'      => array( str_replace( '-', "\u{2013}", 'FC-01-Z99-ZZ-01' ) ),
			'no-break space' => array( str_replace( ' ', "\u{00A0}", 'FC 01 Z99 ZZ 01' ) ),
		);
	}

	public function test_leaves_an_empty_field_to_woocommerce(): void {
		$this->assertNull( ( new Checker( false ) )->format_problem( '  ' ) );
	}

	public function test_accepts_an_old_postcode_when_the_store_accepts_them(): void {
		$checker = new Checker( true );
		$this->assertNull( $checker->format_problem( '900 108' ) );
		$this->assertSame( '900108', $checker->stored_form( '900 108' ) );
	}

	public function test_asks_for_the_new_postcode_when_the_store_rejects_old_ones(): void {
		$checker = new Checker( false );
		$this->assertSame(
			'This is an old 6-digit postcode. Enter the new 11-character postcode.',
			$checker->format_problem( '900108' )
		);
		$this->assertSame( '900108', $checker->stored_form( '900108' ) );
	}

	public function test_names_the_wrong_state_code(): void {
		$this->assertSame(
			'XX is not a Nigerian state code. Check the first two letters.',
			( new Checker( true ) )->format_problem( 'xx01z99zz01' )
		);
	}

	public function test_offers_the_corrected_postcode_for_a_letter_o_in_place_of_a_zero(): void {
		$this->assertSame(
			'Part of the postcode is not valid. Did you mean EK-01-A03-FK-01?',
			( new Checker( true ) )->format_problem( 'EKO1A03FK01' )
		);
	}

	public function test_asks_for_each_character_to_be_checked_when_no_correction_fits(): void {
		$this->assertSame(
			'Part of the postcode is not valid. Check each letter and digit.',
			( new Checker( true ) )->format_problem( 'EK00A03FK01' )
		);
	}

	public function test_says_how_long_a_postcode_is(): void {
		$this->assertSame(
			'A postcode has 11 letters and digits. Check that none is missing or extra.',
			( new Checker( true ) )->format_problem( 'EK-01-A03-FK-1' )
		);
	}

	public function test_asks_for_other_characters_to_be_removed(): void {
		$this->assertSame(
			'A postcode has only letters and digits. Remove the other characters.',
			( new Checker( true ) )->format_problem( 'EK01A03FK0!' )
		);
	}

	public function test_marks_a_postcode_unchecked_when_the_store_does_not_look_up(): void {
		$check = ( new Checker( true ) )->check( 'fc 01 z99 zz 01' );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $check->postcode );
		$this->assertSame( CheckStatus::Unchecked, $check->status );
	}

	public function test_marks_a_postcode_valid_when_nipost_knows_it(): void {
		( new FakeGateway() )->start();
		$check = self::looking_up()->check( 'FC01Z99ZZ01' );
		$this->assertSame( CheckStatus::Valid, $check->status );
		$this->assertNull( $check->error );
	}

	public function test_marks_a_postcode_invalid_when_nipost_does_not_know_it(): void {
		( new FakeGateway() )->start();
		$check = self::looking_up()->check( 'FC01Z99ZZ02' );
		$this->assertSame( CheckStatus::Invalid, $check->status );
	}

	public function test_sends_the_canonical_postcode_at_level_1_with_the_key(): void {
		$gateway = ( new FakeGateway() )->start();
		self::looking_up()->check( 'fc 01 z99 zz 01' );
		$this->assertCount( 1, $gateway->requests );
		$this->assertSame(
			'https://api.postcode.gov.ng/v1/lookup?code=FC-01-Z99-ZZ-01&level=1',
			$gateway->requests[0]['url']
		);
		$headers = $gateway->requests[0]['args']['headers'];
		$this->assertSame( 'nipost_live_example', $headers['X-API-Key'] );
	}

	public function test_sends_no_request_for_an_old_postcode(): void {
		$gateway = ( new FakeGateway() )->start();
		$check   = self::looking_up()->check( '900108' );
		$this->assertSame( CheckStatus::Unchecked, $check->status );
		$this->assertSame( array(), $gateway->requests );
	}

	public function test_sends_nothing_for_a_store_without_a_key(): void {
		$gateway = ( new FakeGateway() )->start();
		$check   = Plugin::checker()->check( 'FC-01-Z99-ZZ-01' );
		$this->assertSame( CheckStatus::Unchecked, $check->status );
		$this->assertSame( array(), $gateway->requests );
	}

	public function test_sends_nothing_for_a_store_that_checks_the_format_only(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		update_option( Settings::CONFIRM, 'none' );
		$gateway = ( new FakeGateway() )->start();
		$check   = Plugin::checker()->check( 'FC01Z99ZZ01' );
		$this->assertSame( CheckStatus::Unchecked, $check->status );
		$this->assertSame( array(), $gateway->requests );
	}

	public function test_looks_up_with_the_key_that_the_store_saved(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		$gateway = ( new FakeGateway() )->start();
		$this->assertSame( CheckStatus::Valid, Plugin::checker()->check( 'FC01Z99ZZ01' )->status );
		$this->assertCount( 1, $gateway->requests );
	}

	public function test_gives_nothing_for_text_that_fails_the_format_check(): void {
		$this->assertNull( self::looking_up( false )->check( '900108' ) );
		$this->assertNull( self::looking_up()->check( 'EK01A03FK0!' ) );
	}

	/**
	 * A failed lookup gives the status error and the client's error code, and sends one
	 * request only.
	 *
	 * @dataProvider failures
	 *
	 * @param string    $answer The FakeGateway answer.
	 * @param ErrorCode $error  The expected error code.
	 */
	public function test_marks_a_failed_lookup_as_an_error_and_does_not_retry(
		string $answer,
		ErrorCode $error
	): void {
		$gateway = ( new FakeGateway( $answer ) )->start();
		$check   = self::looking_up()->check( 'FC-01-Z99-ZZ-01' );
		$this->assertSame( CheckStatus::Error, $check->status );
		$this->assertSame( $error, $check->error );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $check->postcode );
		$this->assertCount( 1, $gateway->requests );
	}

	/**
	 * Gateway failures and the error code that each one gives.
	 *
	 * @return array<string, array{string, ErrorCode}>
	 */
	public static function failures(): array {
		return array(
			'no response' => array( 'no-response', ErrorCode::NetworkError ),
			'refused key' => array( 'invalid-api-key', ErrorCode::Unauthorized ),
			'no credits'  => array( 'insufficient-credits', ErrorCode::InsufficientCredits ),
			'too many'    => array( 'rate-limited', ErrorCode::RateLimited ),
			'scope'       => array( 'scope-not-granted', ErrorCode::Forbidden ),
			'server down' => array( 'server-error', ErrorCode::ServerError ),
			'unreadable'  => array( 'unreadable', ErrorCode::UnexpectedResponse ),
			'bad header'  => array( 'bad-header', ErrorCode::NetworkError ),
		);
	}

	public function test_marks_a_lookup_that_reaches_the_time_limit_as_a_timeout(): void {
		$gateway = ( new FakeGateway( 'no-response' ) )->start();
		$factory = new Psr17Factory();
		$clock   = new class() implements Timer {
			/**
			 * The time in milliseconds. Each reading adds the whole limit of a lookup.
			 *
			 * @var float
			 */
			private float $now = 0.0;

			public function nowMs(): float {
				$this->now += Plugin::LOOKUP_TIMEOUT_MS;
				return $this->now;
			}

			public function wait( int $ms ): void {
			}

			public function jitterMs( int $max_ms ): int {
				return 0;
			}
		};
		$client  = new PostcodeClient(
			new WpTransport( Plugin::LOOKUP_TIMEOUT_MS, $factory ),
			$factory,
			apiKey: 'nipost_live_example',
			timeoutMs: Plugin::LOOKUP_TIMEOUT_MS,
			maxRetries: 0,
			timer: $clock,
		);
		$check   = ( new Checker( true, $client ) )->check( 'FC-01-Z99-ZZ-01' );
		$this->assertSame( CheckStatus::Error, $check->status );
		$this->assertSame( ErrorCode::Timeout, $check->error );
		$this->assertCount( 1, $gateway->requests );
	}

	public function test_marks_any_failure_of_the_client_as_an_error(): void {
		$transport = new class() implements ClientInterface {
			public function sendRequest( RequestInterface $request ): ResponseInterface {
				throw new RuntimeException( 'Something broke for FC-01-Z99-ZZ-01.' );
			}
		};
		$factory   = new Psr17Factory();
		$client    = new PostcodeClient( $transport, $factory, apiKey: 'nipost_live_example' );
		$check     = ( new Checker( true, $client ) )->check( 'FC-01-Z99-ZZ-01' );
		$this->assertSame( CheckStatus::Error, $check->status );
		$this->assertSame( ErrorCode::UnexpectedResponse, $check->error );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $check->postcode );
	}
}
