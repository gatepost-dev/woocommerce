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
use Gatepost\WooCommerce\AddressLocale;
use Gatepost\WooCommerce\BlockCheckout;
use Gatepost\WooCommerce\ClassicCheckout;
use Gatepost\WooCommerce\Plugin;
use Gatepost\WooCommerce\Settings;
use WP_Error;
use WP_UnitTestCase;

/**
 * What a request reads from the options table. A shop with no Nigerian order pays for every read
 * on every page.
 */
final class OptionReadsTest extends WP_UnitTestCase {

	/**
	 * The queries that mention one of the plugin's options.
	 *
	 * @var array<int, string>
	 */
	private array $queries = array();

	/**
	 * The reads of the secret key.
	 *
	 * @var int
	 */
	private int $key_reads = 0;

	public function tear_down(): void {
		delete_option( Settings::REQUIRED );
		$this->register_field_again();
		parent::tear_down();
	}

	/**
	 * Registers the block field again, as WooCommerce does on each request.
	 */
	private function register_field_again(): void {
		$fields = Package::container()->get( CheckoutFields::class );
		$fields->deregister_checkout_field( BlockCheckout::FIELD );
		BlockCheckout::register_field();
	}

	/**
	 * Counts the queries that name a plugin option, and the reads of the secret key.
	 */
	private function watch(): void {
		add_filter(
			'query',
			function ( $query ) {
				if ( false !== strpos( (string) $query, 'gatepost_wc_' ) ) {
					$this->queries[] = (string) $query;
				}
				return $query;
			}
		);
		add_filter(
			'pre_option_' . Settings::SECRET_KEY,
			function ( $value ) {
				++$this->key_reads;
				return $value;
			}
		);
	}

	public function test_activation_adds_the_three_small_options_and_never_the_key(): void {
		delete_option( Settings::REQUIRED );
		delete_option( Settings::LEGACY );
		delete_option( Settings::CONFIRM );
		delete_option( Settings::SECRET_KEY );
		Plugin::activate();
		wp_cache_flush();
		$autoloaded = wp_load_alloptions();
		$this->assertSame( 'no', $autoloaded[ Settings::REQUIRED ] );
		$this->assertSame( 'accept', $autoloaded[ Settings::LEGACY ] );
		$this->assertSame( 'level1', $autoloaded[ Settings::CONFIRM ] );
		$this->assertArrayNotHasKey( Settings::SECRET_KEY, $autoloaded );
		$this->assertFalse( get_option( Settings::SECRET_KEY ) );
	}

	public function test_activation_keeps_the_choices_of_the_store(): void {
		update_option( Settings::REQUIRED, 'yes' );
		update_option( Settings::LEGACY, 'reject' );
		Plugin::activate();
		$this->assertSame( 'yes', get_option( Settings::REQUIRED ) );
		$this->assertSame( 'reject', get_option( Settings::LEGACY ) );
	}

	public function test_the_plugin_file_hooks_the_activation(): void {
		$slug = 'gatepost-postcode-for-woocommerce';
		$this->assertNotFalse( has_action( "activate_{$slug}/{$slug}.php" ) );
	}

	public function test_a_request_with_no_checkout_never_reads_the_secret_key(): void {
		Plugin::activate();
		update_option( Settings::SECRET_KEY, 'nipost_live_example', false );
		wp_cache_flush();
		$this->watch();
		$this->register_field_again();
		AddressLocale::set_nigeria_rules( array() );
		ClassicCheckout::validate( array( 'billing_country' => 'NG' ), new WP_Error() );
		BlockCheckout::sanitize( 'fc 01 z99 zz 01' );
		BlockCheckout::validate( 'FC-01-Z99-ZZ-01' );
		$this->assertSame( 0, $this->key_reads );
		$this->assertSame( array(), $this->queries );
	}

	public function test_the_key_is_read_only_when_an_order_needs_a_lookup(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example', false );
		$this->watch();
		Plugin::checker();
		$this->assertSame( 1, $this->key_reads );
	}

	public function test_reads_the_two_settings_alone(): void {
		update_option( Settings::REQUIRED, 'yes' );
		update_option( Settings::LEGACY, 'reject' );
		$this->assertTrue( Settings::is_required() );
		$this->assertFalse( Settings::accepts_legacy() );
		delete_option( Settings::REQUIRED );
		delete_option( Settings::LEGACY );
		$this->assertFalse( Settings::is_required() );
		$this->assertTrue( Settings::accepts_legacy() );
	}
}
