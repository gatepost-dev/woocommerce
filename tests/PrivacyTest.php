<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Gatepost\WooCommerce\ClassicCheckout;
use Gatepost\WooCommerce\OrdersList;
use Gatepost\WooCommerce\Privacy;
use Gatepost\WooCommerce\Tests\Support\Shop;
use Gatepost\WooCommerce\Tests\Support\StoreApiCheckout;
use WC_Customer;
use WC_Order;
use WC_Privacy_Erasers;
use WC_Privacy_Exporters;
use WP_UnitTestCase;

/**
 * Export and erase requests, which a store answers with the tools of WordPress and WooCommerce.
 */
final class PrivacyTest extends WP_UnitTestCase {

	const EMAIL = 'ada@example.com';

	/**
	 * The meta keys that hold a full postcode, in both checkouts.
	 *
	 * @var array<int, string>
	 */
	const POSTCODE_KEYS = array(
		'_gatepost_billing_postcode',
		'_gatepost_shipping_postcode',
		'_billing_gatepost_postcode',
		'_shipping_gatepost_postcode',
		'_wc_billing/gatepost/postcode',
		'_wc_shipping/gatepost/postcode',
	);

	/**
	 * Places an order through the checkout block.
	 */
	private function block_order(): WC_Order {
		Shop::fill_cart();
		$response = StoreApiCheckout::place(
			array( 'gatepost/postcode' => 'FC-01-Z99-ZZ-01' ),
			array( 'gatepost/postcode' => 'FC-01-Z99-ZZ-02' )
		);
		$this->assertSame( 200, $response->get_status() );
		$order = wc_get_order( $response->get_data()['order_id'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	/**
	 * Places an order the way the classic checkout stores it.
	 */
	private function classic_order(): WC_Order {
		$order = wc_create_order();
		$order->set_billing_email( self::EMAIL );
		$order->set_billing_country( 'NG' );
		$order->set_shipping_country( 'NG' );
		$order->update_meta_data( '_billing_gatepost_postcode', 'FC-01-Z99-ZZ-01' );
		$order->update_meta_data( '_shipping_gatepost_postcode', 'FC-01-Z99-ZZ-02' );
		$order->save();
		ClassicCheckout::record( $order );
		// A shop that keeps the typed text in WooCommerce's own meta holds a second copy.
		$order->update_meta_data( '_billing_gatepost_postcode', 'FC-01-Z99-ZZ-01' );
		$order->update_meta_data( '_shipping_gatepost_postcode', 'FC-01-Z99-ZZ-02' );
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
	 * Gives the text of everything that an export holds, as one string.
	 *
	 * @param array<string, mixed> $export The result of the exporter.
	 */
	private static function exported_text( array $export ): string {
		return (string) wp_json_encode( $export['data'] );
	}

	/**
	 * Runs the eraser of WooCommerce, as WordPress does for an erase request.
	 */
	private static function erase_orders(): void {
		update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );
		$response = WC_Privacy_Erasers::order_data_eraser( self::EMAIL, 1 );
		self::assertTrue( $response['items_removed'] );
	}

	/**
	 * Asserts that an erased order holds no full postcode, and keeps the check status.
	 *
	 * @param WC_Order $order The order before the erase request.
	 */
	private function assert_erased( WC_Order $order ): void {
		$fresh = self::reloaded( $order );
		foreach ( self::POSTCODE_KEYS as $key ) {
			$this->assertSame( '', $fresh->get_meta( $key ), $key );
		}
		$keys = wp_list_pluck( $fresh->get_meta_data(), 'key' );
		foreach ( self::POSTCODE_KEYS as $key ) {
			$this->assertNotContains( $key, $keys, $key );
		}
		$data = (string) wp_json_encode( $fresh->get_data() );
		$this->assertStringNotContainsString( 'Z99', $data );
		$this->assertSame( 'unchecked', $fresh->get_meta( '_gatepost_postcode_check' ) );
		$this->assertSame( '', OrdersList::cell( $fresh ) );
	}

	public function test_erases_the_postcodes_of_a_block_order(): void {
		$order = $this->block_order();
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_meta( '_gatepost_billing_postcode' ) );
		self::erase_orders();
		$this->assert_erased( $order );
	}

	public function test_erases_the_postcodes_of_a_classic_order(): void {
		$order = $this->classic_order();
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_meta( '_gatepost_billing_postcode' ) );
		self::erase_orders();
		$this->assert_erased( $order );
	}

	public function test_keeps_the_postcodes_when_the_store_does_not_erase_orders(): void {
		$order = $this->classic_order();
		update_option( 'woocommerce_erasure_request_removes_order_data', 'no' );
		WC_Privacy_Erasers::order_data_eraser( self::EMAIL, 1 );
		$this->assertSame(
			'FC-01-Z99-ZZ-01',
			self::reloaded( $order )->get_meta( '_gatepost_billing_postcode' )
		);
	}

	public function test_exports_the_postcodes_of_a_block_order(): void {
		$this->block_order();
		$text = self::exported_text( WC_Privacy_Exporters::order_data_exporter( self::EMAIL, 1 ) );
		$this->assertStringContainsString( 'Billing Nigerian postcode', $text );
		$this->assertStringContainsString( 'Shipping Nigerian postcode', $text );
		$this->assertStringContainsString( 'FC-01-Z99-ZZ-02', $text );
	}

	public function test_exports_the_postcodes_of_a_classic_order(): void {
		$this->classic_order();
		$export = WC_Privacy_Exporters::order_data_exporter( self::EMAIL, 1 );
		$names  = wp_list_pluck( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $names['Billing Nigerian postcode'] );
		$this->assertSame( 'FC-01-Z99-ZZ-02', $names['Shipping Nigerian postcode'] );
	}

	public function test_exports_nothing_extra_for_an_order_with_no_postcode(): void {
		$order = wc_create_order();
		$order->set_billing_email( self::EMAIL );
		$order->save();
		$export = WC_Privacy_Exporters::order_data_exporter( self::EMAIL, 1 );
		$names  = wp_list_pluck( $export['data'][0]['data'], 'name' );
		$this->assertNotContains( 'Billing Nigerian postcode', $names );
	}

	/**
	 * Gives a customer with an account and a postcode in the address book.
	 */
	private function customer(): WC_Customer {
		$user_id  = self::factory()->user->create( array( 'user_email' => self::EMAIL ) );
		$customer = new WC_Customer( $user_id );
		$customer->update_meta_data( 'billing_gatepost_postcode', 'FC-01-Z99-ZZ-01' );
		$customer->update_meta_data( 'shipping_gatepost_postcode', 'FC-01-Z99-ZZ-02' );
		$customer->save();
		return $customer;
	}

	public function test_exports_the_postcodes_of_the_address_book(): void {
		$this->customer();
		$text = self::exported_text( WC_Privacy_Exporters::customer_data_exporter( self::EMAIL ) );
		$this->assertStringContainsString( 'FC-01-Z99-ZZ-01', $text );
		$this->assertStringContainsString( 'Shipping Nigerian postcode', $text );
	}

	public function test_erases_the_postcodes_of_the_address_book(): void {
		$customer = $this->customer();
		$response = WC_Privacy_Erasers::customer_data_eraser( self::EMAIL, 1 );
		$this->assertTrue( $response['items_removed'] );
		$id = $customer->get_id();
		$this->assertSame( '', get_user_meta( $id, 'billing_gatepost_postcode', true ) );
		$this->assertSame( '', get_user_meta( $id, 'shipping_gatepost_postcode', true ) );
		$this->assertContains(
			'Removed customer "Billing Nigerian postcode"',
			$response['messages']
		);
	}

	public function test_registers_a_privacy_policy_text_that_names_the_lookup(): void {
		$callback = array( Privacy::class, 'add_policy_text' );
		$this->assertNotFalse( has_action( 'admin_init', $callback ) );
		$text = Privacy::policy_text();
		$this->assertStringContainsString( 'NIPOST', $text );
		$this->assertStringContainsString( 'secret key', $text );
		$this->assertStringContainsString( 'api.postcode.gov.ng', $text );
		$this->assertStringNotContainsString( 'Z99', $text );
	}

	public function test_adds_the_policy_text_to_the_suggested_policy_of_wordpress(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );
		// The other callbacks of admin_init ask WordPress.org for updates. The test runs only ours.
		remove_all_actions( 'admin_init' );
		add_action( 'admin_init', array( Privacy::class, 'add_policy_text' ) );
		do_action( 'admin_init' );
		$policy    = \WP_Privacy_Policy_Content::get_suggested_policy_text();
		$suggested = (string) wp_json_encode( $policy );
		$this->assertStringContainsString( 'Gatepost Postcode for WooCommerce', $suggested );
	}
}
