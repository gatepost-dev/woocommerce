<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

/**
 * Changes WooCommerce's address rules for Nigeria. The native postcode field stays hidden for
 * Nigeria, because WooCommerce checks it with the old 6-digit rule. The classic checkout gets the
 * plugin's own postcode field, which shows only for Nigeria.
 */
final class AddressLocale {

	const FIELD = 'gatepost_postcode';

	/**
	 * Adds the filters.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_default_address_fields', array( self::class, 'add_field' ) );
		add_filter( 'woocommerce_get_country_locale', array( self::class, 'set_nigeria_rules' ) );
		add_filter(
			'woocommerce_country_locale_field_selectors',
			array( self::class, 'add_selector' )
		);
	}

	/**
	 * Adds the postcode field to every address form, hidden until the customer picks Nigeria.
	 *
	 * @param array<string, array<string, mixed>> $fields The address fields, by key.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_field( array $fields ): array {
		$fields[ self::FIELD ] = array(
			'label'        => __( 'Postcode', 'gatepost-postcode-for-woocommerce' ),
			'required'     => false,
			'hidden'       => true,
			'class'        => array( 'form-row-wide' ),
			'autocomplete' => 'postal-code',
			'priority'     => 95,
		);
		return $fields;
	}

	/**
	 * Hides the native postcode field for Nigeria, and shows the plugin's field. The field is
	 * required when the store says so.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $locale The rules, by country code.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function set_nigeria_rules( array $locale ): array {
		$locale['NG']['postcode']['required'] = false;
		$locale['NG']['postcode']['hidden']   = true;
		$locale['NG'][ self::FIELD ]          = array(
			'required' => Settings::load()->required,
			'hidden'   => false,
		);
		return $locale;
	}

	/**
	 * Tells WooCommerce's address script where the field is, so that it applies the rules above
	 * when the customer changes the country.
	 *
	 * @param array<string, string> $selectors CSS selectors, by field key.
	 * @return array<string, string>
	 */
	public static function add_selector( array $selectors ): array {
		$selectors[ self::FIELD ] = '#billing_gatepost_postcode_field,'
			. ' #shipping_gatepost_postcode_field';
		return $selectors;
	}
}
