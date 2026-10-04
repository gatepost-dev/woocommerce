<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Gatepost\WooCommerce\Vendor\Gatepost\Postcode\Postcode;
use ReflectionClass;
use WP_UnitTestCase;

/**
 * The plugin as WordPress and WooCommerce load it.
 */
final class PluginTest extends WP_UnitTestCase {

	public function test_reads_postcodes_with_the_sdk_under_its_own_namespace(): void {
		$parsed = Postcode::parse( 'fc 01 z99 zz 01' );
		$this->assertSame( 'FC-01-Z99-ZZ-01', $parsed->value?->canonical );
		$file = ( new ReflectionClass( Postcode::class ) )->getFileName();
		$this->assertStringContainsString( '/vendor-prefixed/gatepost/postcode/', (string) $file );
	}

	/**
	 * WP-6: the plugin declares compatibility with HPOS and with the cart and checkout blocks.
	 *
	 * @dataProvider features
	 *
	 * @param string $feature A WooCommerce feature id.
	 */
	public function test_declares_compatibility_with_each_feature( string $feature ): void {
		$features = wc_get_container()->get( FeaturesController::class );
		$plugins  = $features->get_compatible_plugins_for_feature( $feature );
		$this->assertContains(
			'gatepost-postcode-for-woocommerce/gatepost-postcode-for-woocommerce.php',
			$plugins['compatible']
		);
	}

	/**
	 * The features.
	 *
	 * @return array<string, array{string}>
	 */
	public static function features(): array {
		return array(
			'order tables'    => array( 'custom_order_tables' ),
			'checkout blocks' => array( 'cart_checkout_blocks' ),
		);
	}
}
