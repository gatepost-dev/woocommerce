<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Throwable;

/**
 * Changes WooCommerce's address rules for Nigeria. WooCommerce has no rule for a Nigerian
 * postcode and hides the native field. The native field stays hidden, because the plugin's own
 * field replaces it, and the plugin then fills the native postcode of the order. The classic
 * checkout gets the plugin's own postcode field, which shows only for Nigeria.
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
	 * required when the store says so, but not in a Store API request: the Store API checks
	 * every field of the locale, and the checkout block has its own field with another key.
	 * A classic request can look like a Store API request, so ClassicCheckout::validate() checks
	 * the required setting itself. If the settings cannot be read, the field stays optional and
	 * the plugin logs one warning.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $locale The rules, by country code.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function set_nigeria_rules( array $locale ): array {
		$locale['NG']['postcode']['required'] = false;
		$locale['NG']['postcode']['hidden']   = true;
		try {
			$required = Settings::is_required() && ! WC()->is_store_api_request();
		} catch ( Throwable $failure ) {
			OrderPostcodes::warn_of(
				'The plugin could not read its settings for the address rules.',
				$failure
			);
			$required = false;
		}
		$locale['NG'][ self::FIELD ] = array(
			'required' => $required,
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
