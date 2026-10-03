<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use WP_UnitTestCase;

/**
 * The plugin file, loaded from a copy of the GitHub source archive, which has no bundled SDK.
 */
final class PluginFileTest extends WP_UnitTestCase {

	/**
	 * The folder that holds the copy of the plugin file.
	 *
	 * @var string
	 */
	private string $folder = '';

	public function set_up(): void {
		parent::set_up();
		$this->folder = sys_get_temp_dir() . '/gatepost-source-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->folder );
		copy(
			dirname( __DIR__ ) . '/gatepost-postcode-for-woocommerce.php',
			$this->folder . '/gatepost-postcode-for-woocommerce.php'
		);
	}

	public function test_shows_the_notice_to_an_administrator_only(): void {
		wp_set_current_user( 0 );
		remove_all_actions( 'admin_notices' );
		include $this->folder . '/gatepost-postcode-for-woocommerce.php';
		ob_start();
		do_action( 'admin_notices' );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function tear_down(): void {
		wp_delete_file( $this->folder . '/gatepost-postcode-for-woocommerce.php' );
		rmdir( $this->folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test folder.
		remove_all_actions( 'admin_notices' );
		parent::tear_down();
	}

	public function test_shows_a_notice_that_names_the_release_zip_when_the_sdk_is_missing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		remove_all_actions( 'admin_notices' );
		include $this->folder . '/gatepost-postcode-for-woocommerce.php';
		$this->assertNotFalse( has_action( 'admin_notices' ) );
		ob_start();
		do_action( 'admin_notices' );
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'release zip', $notice );
		$this->assertStringContainsString(
			'https://github.com/gatepost-dev/woocommerce/releases',
			$notice
		);
	}
}
