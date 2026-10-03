<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\WooCommerce\Vendor\Nyholm\Psr7\Factory\Psr17Factory;

/**
 * The plugin's entry point: its hooks, and a checker built from the store's settings.
 */
final class Plugin {

	/**
	 * The time limit of one lookup. A customer waits for it after placing the order, so the plugin
	 * waits 3 seconds for each postcode and does not retry. A lookup that fails marks the order
	 * "error".
	 */
	const LOOKUP_TIMEOUT_MS = 3000;

	/**
	 * The User-Agent header of a lookup. WordPress would send its version and the address of the
	 * store, and NIPOST has no use for either.
	 */
	const USER_AGENT = 'gatepost-postcode-for-woocommerce';

	/**
	 * Adds every hook.
	 */
	public static function boot(): void {
		AddressLocale::register();
		ClassicCheckout::register();
		BlockCheckout::register();
		OrdersList::register();
		SettingsPage::register();
	}

	/**
	 * Adds the three small options with their defaults, so that a request which reads one of
	 * them costs no query for a missing option. The secret key is not added: it is not
	 * autoloaded, and a request reads it only when an order needs a lookup. An option that
	 * exists keeps its value.
	 */
	public static function activate(): void {
		add_option( Settings::REQUIRED, 'no', '', true );
		add_option( Settings::LEGACY, 'accept', '', true );
		add_option( Settings::CONFIRM, 'level1', '', true );
	}

	/**
	 * Builds a checker from the store's current settings. It sends nothing until an order needs
	 * a lookup.
	 */
	public static function checker(): Checker {
		$settings = Settings::load();
		return new Checker( $settings->accept_legacy, self::client( $settings ) );
	}

	/**
	 * Builds the gateway client, or gives null when the store does not look up postcodes.
	 *
	 * @param Settings $settings The store's choices.
	 */
	public static function client( Settings $settings ): ?PostcodeClient {
		if ( null === $settings->secret_key || ! $settings->look_up ) {
			return null;
		}
		$factory = new Psr17Factory();
		return new PostcodeClient(
			new WpTransport( self::LOOKUP_TIMEOUT_MS, $factory ),
			$factory,
			apiKey: $settings->secret_key,
			timeoutMs: self::LOOKUP_TIMEOUT_MS,
			maxRetries: 0,
		);
	}
}
