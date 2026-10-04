<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Gatepost\WooCommerce\ClassicCheckout;
use Gatepost\WooCommerce\Settings;
use Gatepost\WooCommerce\Tests\Support\CapturedLog;
use Gatepost\WooCommerce\Tests\Support\FakeGateway;
use Gatepost\WooCommerce\Tests\Support\Shop;
use RuntimeException;
use WC_Customer;
use WC_Order;
use WP_Error;
use WP_UnitTestCase;

/**
 * The classic checkout: its validation, and the order that WooCommerce creates from the form.
 */
final class ClassicCheckoutTest extends WP_UnitTestCase {

	public function tear_down(): void {
		CapturedLog::restore();
		parent::tear_down();
	}

	/**
	 * Gives a saved Nigerian order whose typed postcode is in WooCommerce's classic meta.
	 *
	 * @param string   $typed The text of the billing field.
	 * @param WC_Order $order The order to fill, or a new one.
	 */
	private static function typed_order( string $typed, ?WC_Order $order = null ): WC_Order {
		$order = $order ?? wc_create_order();
		$order->set_billing_country( 'NG' );
		$order->update_meta_data( '_billing_gatepost_postcode', $typed );
		$order->save();
		return $order;
	}

	/**
	 * Gives the order again as the database holds it.
	 *
	 * @param WC_Order $order The order.
	 */
	private static function reloaded( WC_Order $order ): WC_Order {
		$fresh = wc_get_order( $order->get_id() );
		self::assertInstanceOf( WC_Order::class, $fresh );
		return $fresh;
	}

	/**
	 * Gives the posted form of a Nigerian customer, as WooCommerce cleans it.
	 *
	 * @param array<string, string> $fields The fields to change.
	 * @return array<string, mixed>
	 */
	private static function form( array $fields ): array {
		return array_merge(
			array(
				'billing_first_name'         => 'Ada',
				'billing_last_name'          => 'Obi',
				'billing_country'            => 'NG',
				'billing_state'              => 'FC',
				'billing_email'              => 'ada@example.com',
				'billing_gatepost_postcode'  => '',
				'shipping_country'           => 'NG',
				'shipping_gatepost_postcode' => '',
				'ship_to_different_address'  => false,
				'payment_method'             => 'cod',
			),
			$fields
		);
	}

	/**
	 * Gives the messages of the checkout errors, by error code.
	 *
	 * @param array<string, mixed> $form The posted form.
	 * @return array<string, string>
	 */
	private static function errors_of( array $form ): array {
		$errors = new WP_Error();
		ClassicCheckout::validate( $form, $errors );
		$messages = array();
		foreach ( $errors->get_error_codes() as $code ) {
			$messages[ (string) $code ] = $errors->get_error_message( $code );
		}
		return $messages;
	}

	/**
	 * Creates the order with WooCommerce's own checkout, from a cart with one book.
	 *
	 * @param array<string, mixed> $form The posted form.
	 */
	private static function order_from( array $form ): WC_Order {
		Shop::fill_cart();
		$order_id = WC()->checkout()->create_order( $form );
		$order    = wc_get_order( is_int( $order_id ) ? $order_id : 0 );
		self::assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	/**
	 * Gives the meta keys that a saved order holds.
	 *
	 * @param WC_Order $order The order.
	 * @return array<int, string>
	 */
	private static function meta_keys( WC_Order $order ): array {
		$keys = array();
		foreach ( self::reloaded( $order )->get_meta_data() as $meta ) {
			$keys[] = (string) $meta->key;
		}
		return $keys;
	}

	public function test_refuses_a_postcode_with_the_wrong_format_in_a_nigerian_address(): void {
		$this->assertSame(
			array(
				'billing_gatepost_postcode_validation' =>
					'Part of the postcode is not valid. Did you mean EK-01-A03-FK-01?',
			),
			self::errors_of( self::form( array( 'billing_gatepost_postcode' => 'EKO1A03FK01' ) ) )
		);
	}

	public function test_ignores_the_field_for_an_address_outside_nigeria(): void {
		$form = self::form(
			array(
				'billing_country'           => 'GB',
				'billing_gatepost_postcode' => 'not a postcode!',
			)
		);
		$this->assertSame( array(), self::errors_of( $form ) );
	}

	public function test_checks_the_shipping_postcode_only_when_it_has_its_own_address(): void {
		$form = self::form( array( 'shipping_gatepost_postcode' => 'EK01A03FK0!' ) );
		$this->assertSame( array(), self::errors_of( $form ) );
		$form['ship_to_different_address'] = true;
		$errors                            = self::errors_of( $form );
		$this->assertArrayHasKey( 'shipping_gatepost_postcode_validation', $errors );
	}

	public function test_asks_for_the_new_postcode_when_the_store_rejects_old_ones(): void {
		update_option( Settings::LEGACY, 'reject' );
		$errors = self::errors_of( self::form( array( 'billing_gatepost_postcode' => '900108' ) ) );
		$this->assertSame(
			'This is an old 6-digit postcode. Enter the new 11-character postcode.',
			$errors['billing_gatepost_postcode_validation']
		);
	}

	public function test_saves_the_postcode_under_the_plugin_keys_and_in_the_address(): void {
		$form  = self::form( array( 'billing_gatepost_postcode' => 'fc01z99zz01' ) );
		$order = self::order_from( $form );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_billing_postcode() );
		$this->assertSame( 'unchecked', $order->get_meta( '_gatepost_postcode_check' ) );
		$this->assertSame( '', $order->get_meta( '_billing_gatepost_postcode' ), 'One key each.' );
	}

	public function test_places_the_order_when_the_gateway_refuses_the_key(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		( new FakeGateway( 'invalid-api-key' ) )->start();
		$form  = self::form( array( 'billing_gatepost_postcode' => 'FC-01-Z99-ZZ-01' ) );
		$order = self::order_from( $form );
		$this->assertSame( 'error', $order->get_meta( '_gatepost_postcode_check' ) );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$text  = implode( ' ', wp_list_pluck( $notes, 'content' ) );
		$this->assertStringContainsString( 'The gateway refused the secret key.', $text );
	}

	public function test_lets_the_customer_through_when_the_settings_break_at_validation(): void {
		add_filter(
			'pre_option_' . Settings::LEGACY,
			static function () {
				throw new RuntimeException( 'options broke' );
			}
		);
		$form = self::form( array( 'billing_gatepost_postcode' => 'EKO1A03FK01' ) );
		$this->assertSame( array(), self::errors_of( $form ) );
	}

	public function test_keeps_the_typed_postcode_when_the_checker_cannot_be_built(): void {
		$log   = CapturedLog::install();
		$order = self::typed_order( 'FC-01-Z99-ZZ-01' );
		add_filter(
			'pre_option_' . Settings::LEGACY,
			static function () {
				throw new RuntimeException( 'options broke' );
			}
		);
		ClassicCheckout::record( $order );
		$saved = self::reloaded( $order );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $saved->get_billing_postcode() );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $saved->get_meta( '_billing_gatepost_postcode' ) );
		$this->assertSame( 'unchecked', $saved->get_meta( '_gatepost_postcode_check' ) );
		$this->assertSame(
			array(
				'Order ' . $order->get_id() . ': the plugin could not check the postcodes.'
					. ' It failed with RuntimeException.',
			),
			$log->messages
		);
	}

	public function test_keeps_the_typed_postcode_when_storing_the_first_address_fails(): void {
		$log   = CapturedLog::install();
		$order = self::typed_order(
			'FC-01-Z99-ZZ-01',
			new class() extends WC_Order {
				/**
				 * Fails for the plugin's own postcode key.
				 *
				 * @param string $key   The meta key.
				 * @param mixed  $value The value.
				 * @param int    $id    The meta id.
				 * @throws RuntimeException For the billing key of the plugin.
				 */
				public function update_meta_data( $key, $value, $id = 0 ) {
					if ( '_gatepost_billing_postcode' === $key ) {
						throw new RuntimeException( 'meta broke' );
					}
					parent::update_meta_data( $key, $value, $id );
				}
			}
		);
		ClassicCheckout::record( $order );
		$saved = self::reloaded( $order );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $saved->get_billing_postcode() );
		$this->assertSame( 'unchecked', $saved->get_meta( '_gatepost_postcode_check' ) );
		$this->assertCount( 1, $log->messages );
		$this->assertStringContainsString( 'RuntimeException', $log->messages[0] );
	}

	public function test_keeps_a_typed_postcode_that_has_the_wrong_format(): void {
		$order = self::typed_order( 'EKO1A03FK01' );
		ClassicCheckout::record( $order );
		$saved = self::reloaded( $order );
		$this->assertSame( 'EKO1A03FK01', $saved->get_billing_postcode() );
		$this->assertSame( '', $saved->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( 'unchecked', $saved->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_removes_the_woocommerce_meta_after_the_plugin_stored_the_postcode(): void {
		$order = self::typed_order( 'fc01z99zz01' );
		ClassicCheckout::record( $order );
		$saved = self::reloaded( $order );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $saved->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( '', $saved->get_meta( '_billing_gatepost_postcode' ) );
	}

	public function test_accepts_and_stores_an_old_postcode_by_default(): void {
		$this->assertSame(
			array(),
			self::errors_of( self::form( array( 'billing_gatepost_postcode' => '900 108' ) ) )
		);
		$order = self::typed_order( '900 108' );
		ClassicCheckout::record( $order );
		$saved = self::reloaded( $order );
		$this->assertSame( '900108', $saved->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( 'unchecked', $saved->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_logs_the_class_when_the_settings_break_at_validation(): void {
		$log = CapturedLog::install();
		add_filter(
			'pre_option_' . Settings::LEGACY,
			static function () {
				throw new RuntimeException( 'options broke' );
			}
		);
		self::errors_of( self::form( array( 'billing_gatepost_postcode' => 'EKO1A03FK01' ) ) );
		$this->assertSame(
			array(
				'The plugin could not check the format of a postcode.'
					. ' It failed with RuntimeException.',
			),
			$log->messages
		);
	}

	public function test_requires_a_nigerian_postcode_when_the_store_says_so(): void {
		update_option( Settings::REQUIRED, 'yes' );
		$this->assertSame(
			array(
				'billing_gatepost_postcode_required' => 'Billing postcode is required.',
			),
			self::errors_of( self::form( array() ) )
		);
		$britain = self::form(
			array(
				'billing_country' => 'GB',
				'billing_state'   => '',
			)
		);
		$this->assertSame( array(), self::errors_of( $britain ) );
		delete_option( Settings::REQUIRED );
		$this->assertSame( array(), self::errors_of( self::form( array() ) ) );
	}

	public function test_requires_the_postcode_when_a_classic_request_fakes_the_store_api(): void {
		update_option( Settings::REQUIRED, 'yes' );
		$page   = $_SERVER['REQUEST_URI'] ?? '';
		$forged = false;
		// Each WooCommerce version tells a Store API request in its own way, and a classic
		// request can fake each one.
		$uris = array(
			'/checkout/?x=/wp-json/wc/store/v1/checkout',
			'/checkout/?rest_route=/wc/store/v1/checkout',
		);
		try {
			foreach ( $uris as $uri ) {
				$_SERVER['REQUEST_URI'] = $uri;
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fake request.
				parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $_GET );
				WC()->countries->locale = null;
				$forged                 = $forged || WC()->is_store_api_request();
				$this->assertSame(
					array(
						'billing_gatepost_postcode_required' => 'Billing postcode is required.',
					),
					self::errors_of( self::form( array() ) )
				);
			}
		} finally {
			$_SERVER['REQUEST_URI'] = $page;
			$_GET                   = array();
			WC()->countries->locale = null;
		}
		$this->assertTrue( $forged, 'WooCommerce took neither request for a Store API request.' );
	}

	public function test_adds_no_second_error_when_woocommerce_requires_the_field(): void {
		update_option( Settings::REQUIRED, 'yes' );
		$errors = new WP_Error();
		$errors->add( 'billing_gatepost_postcode_required', 'WooCommerce says so.' );
		ClassicCheckout::validate( self::form( array() ), $errors );
		$this->assertSame(
			array( 'WooCommerce says so.' ),
			$errors->get_error_messages( 'billing_gatepost_postcode_required' )
		);
	}

	public function test_leaves_no_empty_postcode_rows_on_an_order_outside_nigeria(): void {
		$order = self::order_from(
			self::form(
				array(
					'billing_country'  => 'GB',
					'billing_state'    => '',
					'shipping_country' => 'GB',
				)
			)
		);
		$keys  = self::meta_keys( $order );
		$this->assertNotContains( '_billing_gatepost_postcode', $keys );
		$this->assertNotContains( '_shipping_gatepost_postcode', $keys );
		$this->assertNotContains( '_gatepost_billing_postcode', $keys );
	}

	public function test_leaves_no_empty_postcode_rows_when_a_nigerian_customer_types_none(): void {
		$order = self::order_from( self::form( array() ) );
		$keys  = self::meta_keys( $order );
		$this->assertNotContains( '_billing_gatepost_postcode', $keys );
		$this->assertNotContains( '_shipping_gatepost_postcode', $keys );
		$this->assertSame( '', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_drops_a_nigerian_postcode_typed_for_a_foreign_address(): void {
		$order = self::order_from(
			self::form(
				array(
					'billing_country'           => 'GB',
					'billing_state'             => '',
					'billing_gatepost_postcode' => 'FC-01-Z99-ZZ-01',
					'shipping_country'          => 'GB',
				)
			)
		);
		$keys  = self::meta_keys( $order );
		$this->assertNotContains( '_billing_gatepost_postcode', $keys );
		$this->assertNotContains( '_gatepost_billing_postcode', $keys );
	}

	public function test_keeps_the_typed_text_that_the_plugin_did_not_store(): void {
		$order = self::typed_order( 'EKO1A03FK01' );
		ClassicCheckout::record( $order );
		$this->assertContains( '_billing_gatepost_postcode', self::meta_keys( $order ) );
	}

	public function test_clears_a_stale_postcode_when_a_retry_changes_the_country(): void {
		$order = self::typed_order( 'FC-01-Z99-ZZ-01' );
		ClassicCheckout::record( $order );
		$this->assertSame(
			'FC-01-Z99-ZZ-01',
			self::reloaded( $order )->get_meta( '_gatepost_billing_postcode' )
		);
		$order->set_billing_country( 'GB' );
		$order->update_meta_data( '_billing_gatepost_postcode', 'FC-01-Z99-ZZ-01' );
		$order->save();
		ClassicCheckout::record( $order );
		$keys = self::meta_keys( $order );
		$this->assertNotContains( '_gatepost_billing_postcode', $keys );
		$this->assertNotContains( '_billing_gatepost_postcode', $keys );
		$this->assertNotContains( '_gatepost_postcode_check', $keys );
	}

	public function test_does_not_look_up_again_when_a_payment_retry_reuses_the_order(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		$gateway = ( new FakeGateway() )->start();
		$order   = self::typed_order( 'FC-01-Z99-ZZ-01' );
		ClassicCheckout::record( $order );
		// WooCommerce saves the form fields again on the reused order, native postcode empty.
		$order->update_meta_data( '_billing_gatepost_postcode', 'FC-01-Z99-ZZ-01' );
		$order->set_billing_postcode( '' );
		$order->save();
		ClassicCheckout::record( $order );
		$saved = self::reloaded( $order );
		$this->assertCount( 1, $gateway->requests );
		$this->assertSame( 'valid', $saved->get_meta( '_gatepost_postcode_check' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $saved->get_billing_postcode() );
		$this->assertNotContains( '_billing_gatepost_postcode', self::meta_keys( $order ) );
	}

	public function test_does_not_read_the_secret_key_for_an_order_outside_nigeria(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		$reads = 0;
		add_filter(
			'pre_option_' . Settings::SECRET_KEY,
			static function ( $value ) use ( &$reads ) {
				++$reads;
				return $value;
			}
		);
		$order = self::typed_order( '' );
		$order->set_billing_country( 'GB' );
		ClassicCheckout::record( $order );
		$this->assertSame( 0, $reads );
	}

	public function test_ignores_a_hook_call_that_does_not_hold_an_order(): void {
		ClassicCheckout::record( 12 );
		$this->assertTrue( true );
	}

	public function test_names_the_address_in_a_required_error(): void {
		update_option( Settings::REQUIRED, 'yes' );
		$form = self::form( array( 'ship_to_different_address' => true ) );
		$this->assertSame(
			array(
				'billing_gatepost_postcode_required'  => 'Billing postcode is required.',
				'shipping_gatepost_postcode_required' => 'Shipping postcode is required.',
			),
			self::errors_of( $form )
		);
	}

	/**
	 * Runs the check that WooCommerce allows when a customer saves an address of the account.
	 *
	 * @param string $country  The country of the address.
	 * @param string $postcode The text of the postcode field.
	 * @return array<int, string> The error notices.
	 */
	private static function saved_address_errors( string $country, string $postcode ): array {
		wc_clear_notices();
		$customer = new WC_Customer( 0 );
		$customer->set_billing_country( $country );
		$customer->update_meta_data( 'billing_gatepost_postcode', $postcode );
		do_action( 'woocommerce_after_save_address_validation', 1, 'billing', array(), $customer );
		$notices = array();
		foreach ( wc_get_notices( 'error' ) as $notice ) {
			$notices[] = (string) $notice['notice'];
		}
		wc_clear_notices();
		return $notices;
	}

	public function test_checks_the_format_of_a_postcode_saved_in_the_account(): void {
		$this->assertSame(
			array( 'Part of the postcode is not valid. Did you mean EK-01-A03-FK-01?' ),
			self::saved_address_errors( 'NG', 'EKO1A03FK01' )
		);
		$this->assertSame( array(), self::saved_address_errors( 'NG', 'FC-01-Z99-ZZ-01' ) );
		$this->assertSame( array(), self::saved_address_errors( 'NG', '' ) );
		$this->assertSame( array(), self::saved_address_errors( 'GB', 'not a postcode!' ) );
	}
}
