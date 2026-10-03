<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

/**
 * The store's choices, read from the plugin's options.
 */
final class Settings {

	const SECRET_KEY = 'gatepost_wc_secret_key';
	const REQUIRED   = 'gatepost_wc_required';
	const LEGACY     = 'gatepost_wc_legacy';
	const CONFIRM    = 'gatepost_wc_confirm';

	/**
	 * Builds the settings.
	 *
	 * @param string|null $secret_key    The store's own live secret key, or null.
	 * @param bool        $required      True when a customer in Nigeria must enter a postcode.
	 * @param bool        $accept_legacy True when the store accepts an old 6-digit postcode.
	 * @param bool        $look_up       True when the store asks NIPOST's gateway about each
	 *                                   postcode. It needs the secret key too.
	 */
	public function __construct(
		public readonly ?string $secret_key,
		public readonly bool $required,
		public readonly bool $accept_legacy,
		public readonly bool $look_up,
	) {
	}

	/**
	 * Reads the options. A missing option takes its default: not required, old postcodes
	 * accepted, and a level 1 lookup when the store has a key.
	 */
	public static function load(): self {
		$secret_key    = (string) get_option( self::SECRET_KEY, '' );
		$required      = 'yes' === get_option( self::REQUIRED, 'no' );
		$accept_legacy = 'reject' !== get_option( self::LEGACY, 'accept' );
		$look_up       = 'none' !== get_option( self::CONFIRM, 'level1' );
		$secret_key    = '' === $secret_key ? null : $secret_key;
		return new self( $secret_key, $required, $accept_legacy, $look_up );
	}
}
