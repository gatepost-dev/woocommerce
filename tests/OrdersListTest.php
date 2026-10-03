<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Gatepost\WooCommerce\OrdersList;
use Gatepost\WooCommerce\Tests\Support\CapturedLog;
use WC_Order;
use WC_Order_Data_Store_CPT;
use RuntimeException;
use WP_UnitTestCase;

/**
 * The Postcode column of the orders list, with order tables (HPOS) on and off.
 */
final class OrdersListTest extends WP_UnitTestCase {

	public function tear_down(): void {
		CapturedLog::restore();
		self::use_order_tables( false );
		parent::tear_down();
	}

	/**
	 * Turns WooCommerce's order tables on or off for the orders that the test creates.
	 *
	 * @param bool $on True for order tables (HPOS), false for orders stored as posts.
	 */
	private static function use_order_tables( bool $on ): void {
		$option = CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION;
		// WooCommerce refuses the switch while other orders wait for a sync. The test does not
		// sync: it reads only the orders that it creates after the switch.
		$controller = wc_get_container()->get( CustomOrdersTableController::class );
		$guard      = array( $controller, 'process_pre_update_option' );
		remove_filter( 'pre_update_option', $guard, 999 );
		update_option( $option, wc_bool_to_string( $on ) );
	}

	/**
	 * Gives a new order with a checked shipping postcode.
	 *
	 * @param string $status The check status.
	 */
	private static function checked_order( string $status ): WC_Order {
		$order = wc_create_order();
		$order->update_meta_data( '_gatepost_billing_postcode', 'FC-01-Z99-ZZ-02' );
		$order->update_meta_data( '_gatepost_shipping_postcode', 'FC-01-Z99-ZZ-01' );
		$order->update_meta_data( '_gatepost_postcode_check', $status );
		$order->save();
		return $order;
	}

	/**
	 * Gives what a list hook prints.
	 *
	 * @param callable $show Prints the cell.
	 */
	private static function printed( callable $show ): string {
		ob_start();
		$show();
		return (string) ob_get_clean();
	}

	public function test_puts_the_column_after_the_shipping_address(): void {
		$columns = OrdersList::add_column(
			array(
				'order_number'     => 'Order',
				'shipping_address' => 'Ship to',
				'order_total'      => 'Total',
			)
		);
		$this->assertSame(
			array( 'order_number', 'shipping_address', 'gatepost_postcode', 'order_total' ),
			array_keys( $columns )
		);
		$without_address = OrdersList::add_column( array( 'order_number' => 'Order' ) );
		$this->assertSame(
			array( 'order_number', 'gatepost_postcode' ),
			array_keys( $without_address )
		);
	}

	public function test_shows_the_shipping_postcode_and_its_status_with_order_tables(): void {
		self::use_order_tables( true );
		$order = self::checked_order( 'valid' );
		$store = $order->get_data_store()->get_current_class_name();
		$this->assertSame( OrdersTableDataStore::class, $store );
		$cell = self::printed(
			static function () use ( $order ) {
				$hook = 'manage_woocommerce_page_wc-orders_custom_column';
				do_action( $hook, 'gatepost_postcode', $order );
			}
		);
		$this->assertSame( 'FC-01-Z99-ZZ-01<br>Checked', $cell );
	}

	public function test_shows_the_shipping_postcode_and_its_status_with_posts_storage(): void {
		self::use_order_tables( false );
		$order = self::checked_order( 'error' );
		$store = $order->get_data_store()->get_current_class_name();
		$this->assertSame( WC_Order_Data_Store_CPT::class, $store );
		$cell = self::printed(
			static function () use ( $order ) {
				$hook = 'manage_shop_order_posts_custom_column';
				do_action( $hook, 'gatepost_postcode', $order->get_id() );
			}
		);
		$this->assertSame( 'FC-01-Z99-ZZ-01<br>Check failed', $cell );
	}

	public function test_prints_nothing_in_other_columns_or_for_unchecked_orders(): void {
		$order = wc_create_order();
		$this->assertSame( '', OrdersList::cell( $order ) );
		$this->assertSame(
			'',
			self::printed(
				static function () use ( $order ) {
					OrdersList::show_order( 'order_total', $order );
				}
			)
		);
	}

	public function test_falls_back_to_the_billing_postcode_and_words_every_status(): void {
		$order = self::checked_order( 'invalid' );
		$order->delete_meta_data( '_gatepost_shipping_postcode' );
		$order->save();
		$this->assertSame( 'FC-01-Z99-ZZ-02<br>Not found', OrdersList::cell( $order ) );
		$order->update_meta_data( '_gatepost_postcode_check', 'unchecked' );
		$this->assertSame( 'FC-01-Z99-ZZ-02<br>Not checked', OrdersList::cell( $order ) );
	}

	public function test_hooks_the_column_into_both_lists(): void {
		$this->assertNotFalse( has_filter( 'manage_woocommerce_page_wc-orders_columns' ) );
		$this->assertNotFalse( has_filter( 'manage_edit-shop_order_columns' ) );
		$this->assertNotFalse( has_action( 'manage_woocommerce_page_wc-orders_custom_column' ) );
		$this->assertNotFalse( has_action( 'manage_shop_order_posts_custom_column' ) );
	}

	public function test_an_order_that_fails_gives_an_empty_cell_and_one_log_line(): void {
		$log   = CapturedLog::install();
		$order = new class() extends WC_Order {
			/**
			 * Always fails.
			 *
			 * @param string $key     The key.
			 * @param bool   $single  Single value.
			 * @param string $context The context.
			 * @throws RuntimeException Always.
			 */
			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				throw new RuntimeException( 'FC-01-Z99-ZZ-01 and a secret' );
			}
		};
		$cell  = self::printed(
			static function () use ( $order ) {
				OrdersList::show_order( OrdersList::COLUMN, $order );
			}
		);
		$this->assertSame( '', $cell );
		$this->assertCount( 1, $log->messages );
		$this->assertStringContainsString( RuntimeException::class, $log->messages[0] );
		$this->assertStringNotContainsString( 'Z99', $log->messages[0] );
		$this->assertStringNotContainsString( 'secret', $log->messages[0] );
	}

	public function test_a_missing_post_gives_an_empty_cell(): void {
		$cell = self::printed(
			static function () {
				OrdersList::show_post( OrdersList::COLUMN, 0 );
			}
		);
		$this->assertSame( '', $cell );
	}
}
