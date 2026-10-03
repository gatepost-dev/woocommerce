<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use Gatepost\WooCommerce\BlockCheckout;
use Gatepost\WooCommerce\Settings;
use Gatepost\WooCommerce\Tests\Support\FakeGateway;
use Gatepost\WooCommerce\Tests\Support\Shop;
use Gatepost\WooCommerce\Tests\Support\StoreApiCheckout;
use RuntimeException;
use WC_Order;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * The checkout block, through the Store API that it calls.
 */
final class BlockCheckoutTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Shop::fill_cart();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_option_' . Settings::LEGACY );
		delete_option( Settings::REQUIRED );
		self::register_field_again();
		parent::tear_down();
	}

	/**
	 * Registers the field again, so that it reads the required setting.
	 */
	private static function register_field_again(): void {
		$fields = Package::container()->get( CheckoutFields::class );
		$fields->deregister_checkout_field( BlockCheckout::FIELD );
		BlockCheckout::register_field();
	}

	/**
	 * Gives the order that a successful checkout created.
	 *
	 * @param WP_REST_Response $response The checkout response.
	 */
	private function order_of( WP_REST_Response $response ): WC_Order {
		$this->assertSame( 200, $response->get_status(), self::text_of( $response ) );
		$order = wc_get_order( $response->get_data()['order_id'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	/**
	 * Gives the text of each note on an order.
	 *
	 * @param WC_Order $order The order.
	 * @return array<int, string>
	 */
	private static function notes_of( WC_Order $order ): array {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		return wp_list_pluck( $notes, 'content' );
	}

	/**
	 * Gives the body of a response as JSON text.
	 *
	 * @param WP_REST_Response $response The checkout response.
	 */
	private static function text_of( WP_REST_Response $response ): string {
		return (string) wp_json_encode( $response->get_data() );
	}

	public function test_saves_the_postcode_of_a_nigerian_order_in_canonical_form(): void {
		$order = $this->order_of(
			StoreApiCheckout::place(
				array( 'gatepost/postcode' => 'fc 01 z99 zz 01' ),
				array( 'gatepost/postcode' => 'FC01Z99ZZ02' )
			)
		);
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-02', $order->get_meta( '_gatepost_shipping_postcode' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_billing_postcode() );
		$this->assertSame( 'FC-01-Z99-ZZ-02', $order->get_shipping_postcode() );
		$this->assertSame( 'unchecked', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_refuses_a_postcode_with_the_wrong_format_in_a_nigerian_address(): void {
		$response = StoreApiCheckout::place(
			array( 'gatepost/postcode' => 'FCO1Z99ZZ01' ),
			array( 'gatepost/postcode' => 'FC-01-Z99-ZZ-01' )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString(
			'Part of the postcode is not valid. Did you mean FC-01-Z99-ZZ-01?',
			self::text_of( $response )
		);
	}

	public function test_ignores_the_field_for_an_address_outside_nigeria(): void {
		$britain = array(
			'country'           => 'GB',
			'state'             => '',
			'city'              => 'London',
			'postcode'          => 'SW1A 1AA',
			'gatepost/postcode' => 'not a postcode!',
		);
		$order   = $this->order_of( StoreApiCheckout::place( $britain, $britain ) );
		$this->assertSame( 'SW1A 1AA', $order->get_billing_postcode() );
		$this->assertSame( '', $order->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( '', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_requires_a_postcode_in_nigeria_only_when_the_store_says_so(): void {
		update_option( Settings::REQUIRED, 'yes' );
		self::register_field_again();
		$response = StoreApiCheckout::place( array(), array() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'Postcode is required', self::text_of( $response ) );

		$britain = array(
			'country'  => 'GB',
			'state'    => '',
			'postcode' => 'SW1A 1AA',
		);
		$this->order_of( StoreApiCheckout::place( $britain, $britain ) );
	}

	public function test_takes_a_required_postcode_from_the_block_field(): void {
		update_option( Settings::REQUIRED, 'yes' );
		self::register_field_again();
		$postcode = array( 'gatepost/postcode' => 'FC-01-Z99-ZZ-01' );
		$order    = $this->order_of( StoreApiCheckout::place( $postcode, $postcode ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_shipping_postcode() );
	}

	public function test_accepts_an_old_postcode_by_default(): void {
		$old   = array( 'gatepost/postcode' => '900108' );
		$order = $this->order_of( StoreApiCheckout::place( $old, $old ) );
		$this->assertSame( '900108', $order->get_meta( '_gatepost_billing_postcode' ) );
		$this->assertSame( 'unchecked', $order->get_meta( '_gatepost_postcode_check' ) );
	}

	public function test_asks_for_the_new_postcode_when_the_store_rejects_old_ones(): void {
		update_option( Settings::LEGACY, 'reject' );
		$old      = array( 'gatepost/postcode' => '900108' );
		$response = StoreApiCheckout::place( $old, $old );
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'old 6-digit postcode', self::text_of( $response ) );
	}

	public function test_marks_the_order_valid_after_one_lookup_for_a_shared_postcode(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		$gateway  = ( new FakeGateway() )->start();
		$postcode = array( 'gatepost/postcode' => 'FC 01 Z99 ZZ 01' );
		$order    = $this->order_of( StoreApiCheckout::place( $postcode, $postcode ) );
		$this->assertSame( 'valid', $order->get_meta( '_gatepost_postcode_check' ) );
		$this->assertCount( 1, $gateway->requests );
	}

	public function test_places_the_order_when_the_gateway_does_not_answer(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		( new FakeGateway( 'no-response' ) )->start();
		$postcode = array( 'gatepost/postcode' => 'FC-01-Z99-ZZ-01' );
		$order    = $this->order_of( StoreApiCheckout::place( $postcode, $postcode ) );
		$this->assertSame( 'error', $order->get_meta( '_gatepost_postcode_check' ) );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_shipping_postcode() );
		$this->assertContains(
			'The billing postcode FC-01-Z99-ZZ-** was not checked. The gateway did not answer. '
				. 'The order went ahead. Check the postcode by hand.',
			self::notes_of( $order )
		);
	}

	public function test_marks_the_order_invalid_and_leaves_a_note_for_an_unknown_postcode(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		( new FakeGateway() )->start();
		$order = $this->order_of(
			StoreApiCheckout::place(
				array( 'gatepost/postcode' => 'FC-01-Z99-ZZ-01' ),
				array( 'gatepost/postcode' => 'FC-01-Z99-ZZ-02' )
			)
		);
		$this->assertSame( 'invalid', $order->get_meta( '_gatepost_postcode_check' ) );
		$this->assertContains(
			'NIPOST has no record of the shipping postcode FC-01-Z99-ZZ-**. '
				. 'Ask the customer to check it.',
			self::notes_of( $order )
		);
	}

	/**
	 * Makes every read of the old-postcode setting fail.
	 */
	private static function break_the_settings(): void {
		add_filter(
			'pre_option_' . Settings::LEGACY,
			static function () {
				throw new RuntimeException( 'options broke' );
			}
		);
	}

	public function test_accepts_the_text_when_the_settings_break_at_checkout(): void {
		self::break_the_settings();
		$this->assertSame( 'fc 01', BlockCheckout::sanitize( 'fc 01' ) );
		$this->assertTrue( BlockCheckout::validate( 'EKO1A03FK01' ) );
	}

	public function test_places_the_order_when_the_settings_break_after_it(): void {
		$order = wc_create_order();
		$order->set_billing_country( 'NG' );
		$order->save();
		self::break_the_settings();
		BlockCheckout::record( $order );
		$this->assertSame( '', $order->get_meta( '_gatepost_postcode_check' ) );
	}
}
