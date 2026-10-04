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
 * The plugin's options and their defaults.
 */
final class SettingsTest extends WP_UnitTestCase {

	public function test_reads_the_defaults_of_a_new_store(): void {
		$settings = Settings::load();
		$this->assertNull( $settings->secret_key );
		$this->assertFalse( $settings->required );
		$this->assertTrue( $settings->accept_legacy );
		$this->assertTrue( $settings->look_up );
	}

	public function test_reads_the_choices_of_the_store(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_example' );
		update_option( Settings::REQUIRED, 'yes' );
		update_option( Settings::LEGACY, 'reject' );
		update_option( Settings::CONFIRM, 'none' );
		$settings = Settings::load();
		$this->assertSame( 'nipost_live_example', $settings->secret_key );
		$this->assertTrue( $settings->required );
		$this->assertFalse( $settings->accept_legacy );
		$this->assertFalse( $settings->look_up );
	}
}
