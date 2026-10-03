<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Gatepost\WooCommerce\AddressLocale;
use WP_UnitTestCase;

/**
 * The address rules for Nigeria in both checkouts.
 */
final class AddressLocaleTest extends WP_UnitTestCase {

	public function tear_down(): void {
		// WooCommerce keeps the locale for the rest of the request.
		WC()->countries->locale = null;
		parent::tear_down();
	}

	public function test_hides_the_native_postcode_and_shows_the_plugin_field_for_nigeria(): void {
		update_option( 'gatepost_wc_required', 'yes' );
		$locale = AddressLocale::set_nigeria_rules(
			array( 'NG' => array( 'postcode' => array( 'label' => 'Postcode' ) ) )
		);
		$this->assertTrue( $locale['NG']['postcode']['hidden'] );
		$this->assertFalse( $locale['NG']['postcode']['required'] );
		$this->assertSame( 'Postcode', $locale['NG']['postcode']['label'] );
		$this->assertSame(
			array(
				'required' => true,
				'hidden'   => false,
			),
			$locale['NG']['gatepost_postcode']
		);
	}

	public function test_gives_the_classic_checkout_a_required_field_for_nigeria_only(): void {
		update_option( 'gatepost_wc_required', 'yes' );
		WC()->countries->locale = null;
		$nigeria                = WC()->countries->get_address_fields( 'NG', 'billing_' );
		$britain                = WC()->countries->get_address_fields( 'GB', 'billing_' );
		$this->assertTrue( $nigeria['billing_gatepost_postcode']['required'] );
		$this->assertFalse( $britain['billing_gatepost_postcode']['required'] );
		$this->assertTrue( $britain['billing_gatepost_postcode']['hidden'] );
	}

	public function test_tells_the_address_script_where_both_fields_are(): void {
		$selectors = AddressLocale::add_selector( array() );
		$this->assertSame(
			'#billing_gatepost_postcode_field, #shipping_gatepost_postcode_field',
			$selectors['gatepost_postcode']
		);
	}
}
