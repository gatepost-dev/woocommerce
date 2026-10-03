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
	 * The time limit of a lookup. A customer waits for it after placing the order, so the plugin
	 * waits 3 seconds and does not retry. A lookup that fails marks the order "error".
	 */
	const LOOKUP_TIMEOUT_MS = 3000;

	/**
	 * Adds every hook.
	 */
	public static function boot(): void {
		SettingsPage::register();
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
