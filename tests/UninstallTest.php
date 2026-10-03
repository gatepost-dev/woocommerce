<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Gatepost\WooCommerce\Settings;
use WP_UnitTestCase;

/**
 * Deleting the plugin (WP-9).
 */
final class UninstallTest extends WP_UnitTestCase {

	public function test_deletes_only_its_options_and_keeps_the_order_meta(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		update_option( Settings::REQUIRED, 'yes' );
		update_option( Settings::LEGACY, 'reject' );
		update_option( Settings::CONFIRM, 'none' );
		update_option( 'gatepost_wc_other', 'stays' );
		$order = wc_create_order();
		$order->update_meta_data( '_gatepost_billing_postcode', 'FC-01-Z99-ZZ-01' );
		$order->save();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'gatepost-postcode-for-woocommerce' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( Settings::SECRET_KEY ) );
		$this->assertFalse( get_option( Settings::REQUIRED ) );
		$this->assertFalse( get_option( Settings::LEGACY ) );
		$this->assertFalse( get_option( Settings::CONFIRM ) );
		$this->assertSame( 'stays', get_option( 'gatepost_wc_other' ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $order->get_meta( '_gatepost_billing_postcode' ) );
	}

	/**
	 * Needs a network of sites.
	 *
	 * @group ms-required
	 */
	public function test_deletes_the_options_of_every_site_of_a_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'It needs a network. Run composer test:multisite.' );
		}
		$sites   = array( get_current_blog_id(), self::factory()->blog->create() );
		$sites[] = self::factory()->blog->create();
		foreach ( $sites as $site ) {
			switch_to_blog( $site );
			update_option( Settings::SECRET_KEY, 'nipost_live_example' );
			update_option( Settings::REQUIRED, 'yes' );
			update_option( Settings::LEGACY, 'reject' );
			update_option( Settings::CONFIRM, 'none' );
			update_option( 'gatepost_wc_other', 'stays' );
			restore_current_blog();
		}
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'gatepost-postcode-for-woocommerce' );
		}
		$main = get_current_blog_id();
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertSame( $main, get_current_blog_id(), 'The uninstall returns to its own site.' );
		foreach ( $sites as $site ) {
			switch_to_blog( $site );
			$this->assertFalse( get_option( Settings::SECRET_KEY ), "site $site" );
			$this->assertFalse( get_option( Settings::REQUIRED ), "site $site" );
			$this->assertFalse( get_option( Settings::LEGACY ), "site $site" );
			$this->assertFalse( get_option( Settings::CONFIRM ), "site $site" );
			$this->assertSame( 'stays', get_option( 'gatepost_wc_other' ), "site $site" );
			restore_current_blog();
		}
	}
}
