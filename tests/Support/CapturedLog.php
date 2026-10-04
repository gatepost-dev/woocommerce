<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests\Support;

use WC_Logger;

/**
 * A WooCommerce logger that keeps its entries in memory.
 */
final class CapturedLog extends WC_Logger {

	/**
	 * The messages, in order.
	 *
	 * @var array<int, string>
	 */
	public array $messages = array();

	/**
	 * Makes WooCommerce use a new capturing logger, and gives it. Call restore() after the test.
	 */
	public static function install(): self {
		$logger = new self();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		wc_get_logger();
		return $logger;
	}

	/**
	 * Gives WooCommerce its own logger back. wc_get_logger() keeps the logger in a static.
	 */
	public static function restore(): void {
		remove_all_filters( 'woocommerce_logging_class' );
		add_filter( 'woocommerce_logging_class', static fn() => new WC_Logger() );
		wc_get_logger();
	}

	/**
	 * Keeps one entry.
	 *
	 * @param string               $level   The level.
	 * @param string               $message The message.
	 * @param array<string, mixed> $context The context.
	 */
	public function log( $level, $message, $context = array() ) {
		$this->messages[] = $message;
		return true;
	}
}
