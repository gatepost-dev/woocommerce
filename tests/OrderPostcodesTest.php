<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Gatepost\WooCommerce\Checker;
use Gatepost\WooCommerce\Notes;
use Gatepost\WooCommerce\OrderPostcodes;
use Gatepost\WooCommerce\Plugin;
use Gatepost\WooCommerce\Settings;
use Gatepost\WooCommerce\Tests\Support\FakeGateway;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\ErrorCode;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\WooCommerce\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use Gatepost\WooCommerce\Vendor\Psr\Http\Client\ClientInterface;
use Gatepost\WooCommerce\Vendor\Psr\Http\Message\RequestInterface;
use Gatepost\WooCommerce\Vendor\Psr\Http\Message\ResponseInterface;
use RuntimeException;
use WC_Logger;
use WC_Order;
use WP_UnitTestCase;

/**
 * What the plugin writes to a new order in both checkouts.
 */
final class OrderPostcodesTest extends WP_UnitTestCase {

	const REDACTED = 'FC-01-Z99-ZZ-**';

	/**
	 * Gives a new Nigerian order with a Nigerian or a British shipping address.
	 *
	 * @param string $shipping_country The country of the shipping address.
	 */
	private static function order( string $shipping_country = 'NG' ): WC_Order {
		$order = wc_create_order();
		$order->set_billing_country( 'NG' );
		$order->set_shipping_country( $shipping_country );
		$order->save();
		return $order;
	}

	/**
	 * Gives a recorder whose checker looks up postcodes with a live key.
	 */
	private static function looking_up(): OrderPostcodes {
		$settings = new Settings( 'nipost_live_example', false, true, true );
		return new OrderPostcodes( new Checker( true, Plugin::client( $settings ) ) );
	}

	/**
	 * Gives a recorder that checks the format only and never looks a postcode up.
	 */
	private static function format_only(): OrderPostcodes {
		return new OrderPostcodes( new Checker( true ) );
	}

	/**
	 * Gives the text of each note on an order.
	 *
	 * @param WC_Order $order The order.
	 * @return array<int, string>
	 */
	private static function notes( WC_Order $order ): array {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		return wp_list_pluck( $notes, 'content' );
	}

	public function test_skips_an_address_outside_nigeria(): void {
		$order = self::order( 'GB' );
		$order->set_shipping_postcode( 'SW1A 1AA' );
		( new OrderPostcodes( new Checker( true ) ) )->record(
			$order,
			array(
				'billing'  => 'FC-01-Z99-ZZ-01',
				'shipping' => 'FC-01-Z99-ZZ-02',
			)
		);
		$this->assertSame( 'SW1A 1AA', $order->get_shipping_postcode() );
		$this->assertSame( '', $order->get_meta( '_gatepost_shipping_postcode' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_meta( '_gatepost_billing_postcode' ) );
	}

	public function test_writes_nothing_for_an_order_without_a_postcode(): void {
		$order = self::order();
		( new OrderPostcodes( new Checker( true ) ) )->record(
			$order,
			array(
				'billing'  => '',
				'shipping' => ' ',
			)
		);
		$this->assertSame( '', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_looks_up_a_postcode_once_when_both_addresses_share_it(): void {
		$gateway = ( new FakeGateway() )->start();
		self::looking_up()->record(
			self::order(),
			array(
				'billing'  => 'FC 01 Z99 ZZ 01',
				'shipping' => 'fc-01-z99-zz-01',
			)
		);
		$this->assertCount( 1, $gateway->requests );
	}

	/**
	 * Gives a logger that keeps its entries, and makes WooCommerce use it. A throw count above
	 * zero makes the first calls of log() throw.
	 *
	 * @param int $throws How many calls of log() throw before the logger keeps entries.
	 */
	private function capture_log( int $throws = 0 ): WC_Logger {
		$logger         = new class() extends WC_Logger {
			/**
			 * The entries, in order.
			 *
			 * @var array<int, array{level: string, message: string, source: mixed}>
			 */
			public array $entries = array();

			/**
			 * How many calls of log() still throw.
			 *
			 * @var int
			 */
			public int $throws = 0;

			/**
			 * Keeps one entry.
			 *
			 * @param string               $level   The level.
			 * @param string               $message The message.
			 * @param array<string, mixed> $context The context.
			 * @throws RuntimeException While the throw count is above zero.
			 */
			public function log( $level, $message, $context = array() ) {
				if ( $this->throws > 0 ) {
					--$this->throws;
					throw new RuntimeException( 'log broke' );
				}
				$this->entries[] = array(
					'level'   => $level,
					'message' => $message,
					'source'  => $context['source'] ?? null,
				);
			}
		};
		$logger->throws = $throws;
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		return $logger;
	}

	/**
	 * Gives WooCommerce its own logger back. wc_get_logger() keeps the logger in a static.
	 */
	public function tear_down(): void {
		remove_all_filters( 'woocommerce_logging_class' );
		add_filter( 'woocommerce_logging_class', static fn() => new WC_Logger() );
		wc_get_logger();
		parent::tear_down();
	}

	/**
	 * Gives a recorder whose client fails with an exception from the plugin's own side.
	 */
	private static function breaking(): OrderPostcodes {
		$transport = new class() implements ClientInterface {
			/**
			 * Fails with a message that quotes the postcode.
			 *
			 * @param RequestInterface $request The request.
			 * @throws RuntimeException Always.
			 */
			public function sendRequest( RequestInterface $request ): ResponseInterface {
				throw new RuntimeException( 'Broke for FC-01-Z99-ZZ-01 with nipost_live_example.' );
			}
		};
		$factory   = new Psr17Factory();
		$client    = new PostcodeClient( $transport, $factory, apiKey: 'nipost_live_example' );
		return new OrderPostcodes( new Checker( true, $client ) );
	}

	public function test_logs_a_failed_lookup_with_the_postcode_redacted(): void {
		$logger = $this->capture_log();
		( new FakeGateway( 'rate-limited' ) )->start();
		$order = self::order();
		self::looking_up()->record( $order, array( 'billing' => 'FC-01-Z99-ZZ-01' ) );
		$this->assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Order ' . $order->get_id()
						. ': the billing postcode FC-01-Z99-ZZ-** was not checked.'
						. ' The gateway client gave rate_limited.',
					'source'  => 'gatepost',
				),
			),
			$logger->entries
		);
	}

	public function test_logs_one_line_when_two_addresses_share_a_failed_lookup(): void {
		$logger = $this->capture_log();
		( new FakeGateway( 'rate-limited' ) )->start();
		$order = self::order();
		self::looking_up()->record(
			$order,
			array(
				'billing'  => 'FC-01-Z99-ZZ-01',
				'shipping' => 'FC 01 Z99 ZZ 01',
			)
		);
		$this->assertCount( 1, $logger->entries );
		$this->assertCount( 2, self::notes( $order ) );
	}

	public function test_blames_the_plugin_for_a_failure_on_its_own_side(): void {
		$logger = $this->capture_log();
		$order  = self::order();
		self::breaking()->record( $order, array( 'billing' => 'FC-01-Z99-ZZ-01' ) );
		$this->assertSame( 'error', $order->get_meta( '_gatepost_postcode_check' ) );
		$this->assertSame(
			array( Notes::not_checked_by_plugin( 'billing', self::REDACTED ) ),
			self::notes( $order )
		);
		$this->assertSame(
			'Order ' . $order->get_id() . ': the billing postcode FC-01-Z99-ZZ-** was not checked.'
				. ' The plugin failed with RuntimeException.',
			$logger->entries[0]['message']
		);
		$line = $logger->entries[0]['message'];
		$this->assertStringNotContainsString( 'nipost_live_example', $line );
	}

	public function test_never_throws_when_the_log_breaks(): void {
		$this->capture_log( 1 );
		( new FakeGateway( 'rate-limited' ) )->start();
		$order = self::order();
		self::looking_up()->record( $order, array( 'billing' => 'FC-01-Z99-ZZ-01' ) );
		$this->assertSame( 'error', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_reports_a_failure_while_it_writes_and_never_throws(): void {
		$logger = $this->capture_log();
		( new FakeGateway( 'rate-limited' ) )->start();
		add_filter(
			'woocommerce_new_order_note_data',
			static function () {
				throw new RuntimeException( 'note broke' );
			}
		);
		$order = self::order();
		self::looking_up()->record( $order, array( 'billing' => 'FC-01-Z99-ZZ-01' ) );
		$this->assertSame(
			array(
				'Order ' . $order->get_id() . ': the plugin could not store the postcodes.'
					. ' It failed with RuntimeException.',
			),
			wp_list_pluck( $logger->entries, 'message' )
		);
	}

	public function test_never_throws_when_the_report_of_a_failure_breaks_too(): void {
		$this->capture_log( 1 );
		add_filter(
			'woocommerce_new_order_note_data',
			static function () {
				throw new RuntimeException( 'note broke' );
			}
		);
		( new FakeGateway( 'rate-limited' ) )->start();
		self::looking_up()->record( self::order(), array( 'billing' => 'FC-01-Z99-ZZ-01' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_ignores_a_group_that_is_not_billing_or_shipping(): void {
		$order = self::order();
		self::format_only()->record( $order, array( 'other' => 'FC-01-Z99-ZZ-01' ) );
		$this->assertSame( '', $order->get_meta( '_gatepost_other_postcode' ) );
		$this->assertSame( '', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_stores_the_canonical_postcode_and_marks_a_known_postcode_valid(): void {
		( new FakeGateway() )->start();
		$order = self::order();
		self::looking_up()->record( $order, array( 'billing' => 'fc 01 z99 zz 01' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_billing_postcode() );
		$this->assertSame( 'valid', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_notes_a_postcode_that_nipost_does_not_know_and_goes_on(): void {
		( new FakeGateway() )->start();
		$order = self::order();
		self::looking_up()->record( $order, array( 'shipping' => 'FC-01-Z99-ZZ-02' ) );
		$this->assertSame( 'invalid', $order->get_meta( '_gatepost_postcode_check' ) );
		$this->assertSame(
			array( Notes::not_found( 'shipping', self::REDACTED ) ),
			self::notes( $order )
		);
	}

	public function test_notes_the_cause_of_a_reply_that_the_plugin_cannot_read(): void {
		( new FakeGateway( 'unreadable' ) )->start();
		$order = self::order();
		self::looking_up()->record( $order, array( 'billing' => 'FC-01-Z99-ZZ-01' ) );
		$this->assertSame( 'error', $order->get_meta( '_gatepost_postcode_check' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_meta( '_gatepost_billing_postcode' ) );
		$notes = self::notes( $order );
		$this->assertSame(
			array( Notes::not_checked( 'billing', self::REDACTED, ErrorCode::UnexpectedResponse ) ),
			$notes
		);
		$this->assertStringNotContainsString( 'FC-01-Z99-ZZ-01', $notes[0] );
		$this->assertStringNotContainsString( 'nipost_live_example', $notes[0] );
	}

	public function test_keeps_an_old_six_digit_postcode_unchecked(): void {
		$order = self::order();
		self::format_only()->record( $order, array( 'billing' => '900 001' ) );
		$this->assertSame( '900001', $order->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( 'unchecked', $order->get_meta( '_gatepost_postcode_check' ) );
		$this->assertSame( array(), self::notes( $order ) );
	}

	public function test_leaves_text_that_fails_the_format_check_alone(): void {
		$order = self::order();
		self::format_only()->record( $order, array( 'billing' => 'nonsense' ) );
		$this->assertSame( '', $order->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( '', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_does_not_look_up_a_known_postcode_again_for_the_same_order(): void {
		$gateway = ( new FakeGateway() )->start();
		$order   = self::order();
		$typed   = array(
			'billing'  => 'FC-01-Z99-ZZ-01',
			'shipping' => 'FC-01-Z99-ZZ-02',
		);
		self::looking_up()->record( $order, $typed );
		$order->save();
		self::looking_up()->record( $order, $typed );
		$order->save();
		$this->assertCount( 2, $gateway->requests );
		$this->assertSame( 'invalid', $order->get_meta( '_gatepost_postcode_check' ) );
		$this->assertCount( 1, self::notes( $order ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_billing_postcode() );
		$this->assertSame( 'FC-01-Z99-ZZ-02', $order->get_shipping_postcode() );
	}

	public function test_restores_the_address_postcode_when_a_retry_blanks_it(): void {
		$gateway = ( new FakeGateway() )->start();
		$order   = self::order( 'GB' );
		$typed   = array( 'billing' => 'FC-01-Z99-ZZ-01' );
		self::looking_up()->record( $order, $typed );
		$order->set_billing_postcode( '' );
		self::looking_up()->record( $order, $typed );
		OrderPostcodes::keep_typed( $order, $typed );
		$this->assertCount( 1, $gateway->requests );
		$this->assertSame( 'valid', $order->get_meta( '_gatepost_postcode_check' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_billing_postcode() );
	}

	public function test_looks_up_again_after_a_failed_lookup(): void {
		$down  = ( new FakeGateway( 'no-response' ) )->start();
		$order = self::order( 'GB' );
		$typed = array( 'billing' => 'FC-01-Z99-ZZ-01' );
		self::looking_up()->record( $order, $typed );
		$this->assertSame( 'error', $order->get_meta( '_gatepost_postcode_check' ) );
		remove_filter( 'pre_http_request', array( $down, 'answer' ), 10 );
		$up = ( new FakeGateway() )->start();
		self::looking_up()->record( $order, $typed );
		$this->assertCount( 1, $down->requests );
		$this->assertCount( 1, $up->requests );
		$this->assertSame( 'valid', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_looks_up_again_when_the_postcode_of_the_order_changed(): void {
		$gateway = ( new FakeGateway() )->start();
		$order   = self::order( 'GB' );
		self::looking_up()->record( $order, array( 'billing' => 'FC-01-Z99-ZZ-01' ) );
		self::looking_up()->record( $order, array( 'billing' => 'FC-01-Z99-ZZ-02' ) );
		$this->assertCount( 2, $gateway->requests );
		$this->assertSame( 'invalid', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_forgets_the_postcode_of_an_address_that_is_no_longer_in_nigeria(): void {
		( new FakeGateway() )->start();
		$order = self::order( 'GB' );
		self::looking_up()->record( $order, array( 'billing' => 'FC-01-Z99-ZZ-01' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_meta( '_gatepost_billing_postcode' ) );
		$order->set_billing_country( 'GB' );
		self::looking_up()->record( $order, array( 'billing' => 'FC-01-Z99-ZZ-01' ) );
		$this->assertSame( '', $order->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( '', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_keeps_a_stronger_status_when_it_keeps_a_typed_postcode(): void {
		$order = self::order( 'GB' );
		$order->update_meta_data( '_gatepost_billing_postcode', 'FC-01-Z99-ZZ-02' );
		$order->set_billing_postcode( 'FC-01-Z99-ZZ-02' );
		$order->update_meta_data( '_gatepost_postcode_check', 'invalid' );
		$order->set_shipping_country( 'NG' );
		OrderPostcodes::keep_typed(
			$order,
			array(
				'billing'  => 'FC-01-Z99-ZZ-02',
				'shipping' => 'EKO1A03FK01',
			)
		);
		$this->assertSame( 'EKO1A03FK01', $order->get_shipping_postcode() );
		$this->assertSame( 'invalid', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_names_the_class_of_an_anonymous_failure_without_its_file(): void {
		$failure = new class() extends RuntimeException {
		};
		$this->assertSame( 'RuntimeException@anonymous', OrderPostcodes::failure_name( $failure ) );
		$this->assertSame(
			'RuntimeException',
			OrderPostcodes::failure_name( new RuntimeException( 'x' ) )
		);
	}

	/**
	 * Answers the gateway with the fixtures, but fails the lookup of FC-01-Z99-ZZ-02.
	 *
	 * @param array<int, string> $urls The URL of each request, filled in as requests arrive.
	 */
	private static function flaky_gateway( array &$urls ): void {
		$fake = new FakeGateway();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$urls, $fake ) {
				if ( FakeGateway::HOST !== wp_parse_url( $url, PHP_URL_HOST ) ) {
					return $preempt;
				}
				$urls[] = $url;
				if ( false !== strpos( $url, 'FC-01-Z99-ZZ-02' ) ) {
					return new \WP_Error( 'http_request_failed', 'no connection' );
				}
				return $fake->answer( $preempt, $args, $url );
			},
			9,
			3
		);
	}

	public function test_a_retry_sends_only_the_postcode_that_has_no_answer(): void {
		$urls  = array();
		$order = self::order();
		$typed = array(
			'billing'  => 'FC-01-Z99-ZZ-01',
			'shipping' => 'FC-01-Z99-ZZ-02',
		);
		self::flaky_gateway( $urls );
		self::looking_up()->record( $order, $typed );
		$this->assertCount( 2, $urls );
		$this->assertSame( 'error', $order->get_meta( '_gatepost_postcode_check' ) );
		self::looking_up()->record( $order, $typed );
		$this->assertCount( 3, $urls, 'Only the failed shipping postcode goes out again.' );
		$this->assertStringContainsString( 'ZZ-02', $urls[2] );
	}

	public function test_a_retry_sends_a_failed_postcode_next_to_an_unknown_one(): void {
		$urls  = array();
		$order = self::order();
		$typed = array(
			'billing'  => 'FC-01-Z99-ZZ-03',
			'shipping' => 'FC-01-Z99-ZZ-02',
		);
		self::flaky_gateway( $urls );
		self::looking_up()->record( $order, $typed );
		$this->assertSame( 'invalid', $order->get_meta( '_gatepost_postcode_check' ) );
		self::looking_up()->record( $order, $typed );
		$this->assertCount( 3, $urls );
		$this->assertStringContainsString( 'ZZ-02', $urls[2] );
		$found = array_filter(
			self::notes( $order ),
			static fn( $note ) => false !== strpos( $note, 'no record of the billing' )
		);
		$this->assertCount( 1, $found, 'The unknown postcode gets one note.' );
	}

	public function test_a_country_change_away_and_back_sends_nothing_again(): void {
		$gateway = ( new FakeGateway() )->start();
		$order   = self::order();
		$typed   = array(
			'billing'  => 'FC-01-Z99-ZZ-03',
			'shipping' => 'FC-01-Z99-ZZ-04',
		);
		self::looking_up()->record( $order, $typed );
		$this->assertCount( 2, $gateway->requests );
		$order->set_billing_country( 'GB' );
		self::looking_up()->record( $order, $typed );
		$this->assertSame( '', $order->get_meta( '_gatepost_billing_postcode' ) );
		$order->set_billing_country( 'NG' );
		self::looking_up()->record( $order, $typed );
		$this->assertCount( 2, $gateway->requests, 'No postcode goes out again.' );
		$this->assertCount( 2, self::notes( $order ), 'No note is added again.' );
		$this->assertSame( 'FC-01-Z99-ZZ-03', $order->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-03', $order->get_billing_postcode() );
		$this->assertSame( 'invalid', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_a_remembered_retry_reads_no_secret_key(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		( new FakeGateway() )->start();
		$order = self::order( 'GB' );
		$typed = array( 'billing' => 'FC-01-Z99-ZZ-01' );
		OrderPostcodes::record_new_order( $order, $typed );
		$reads = 0;
		add_filter(
			'pre_option_' . Settings::SECRET_KEY,
			static function ( $value ) use ( &$reads ) {
				++$reads;
				return $value;
			}
		);
		OrderPostcodes::record_new_order( $order, $typed );
		$this->assertSame( 0, $reads );
		$this->assertSame( 'valid', $order->get_meta( '_gatepost_postcode_check' ) );
	}
}
