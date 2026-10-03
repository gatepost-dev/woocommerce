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
use Gatepost\WooCommerce\Tests\Support\FakeGateway;
use Gatepost\WooCommerce\Tests\Support\Shop;
use RuntimeException;
use WC_Order;
use WP_Error;
use WP_UnitTestCase;

/**
 * The classic checkout: its validation, and the order that WooCommerce creates from the form.
 */
final class ClassicCheckoutTest extends WP_UnitTestCase {

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

	public function test_places_the_order_when_the_settings_break_after_it(): void {
		$order = wc_create_order();
		$order->set_billing_country( 'NG' );
		$order->update_meta_data( '_billing_gatepost_postcode', 'FC-01-Z99-ZZ-01' );
		$order->save();
		add_filter(
			'pre_option_' . Settings::LEGACY,
			static function () {
				throw new RuntimeException( 'options broke' );
			}
		);
		ClassicCheckout::record( $order );
		$this->assertSame( '', $order->get_meta( '_gatepost_postcode_check' ) );
	}
}
