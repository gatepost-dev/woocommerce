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
 * The licence check of scripts/check-licences.php (DEP-4), run on small fake lock files.
 */
final class CheckLicencesTest extends WP_UnitTestCase {

	/**
	 * Runs the script on two fixtures.
	 *
	 * @param string $lock   The lock fixture.
	 * @param string $notice The NOTICE fixture.
	 * @param string $npm    The fixture that holds `pnpm licenses list --json`, or empty.
	 * @return array{int, string} The exit code and the error output.
	 */
	private function run_script( string $lock, string $notice, string $npm = '' ): array {
		$root    = dirname( __DIR__ );
		$command = array(
			PHP_BINARY,
			$root . '/scripts/check-licences.php',
			__DIR__ . '/fixtures/' . $lock,
			__DIR__ . '/fixtures/' . $notice,
		);
		if ( '' !== $npm ) {
			$command[] = __DIR__ . '/fixtures/' . $npm;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- the script runs outside WordPress.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		return array( proc_close( $process ), $errors );
	}

	public function test_allows_a_permissive_shipped_package_and_a_listed_dev_exception(): void {
		$this->assertSame( 0, $this->run_script( 'lock-allowed.json', 'notice-shipped.txt' )[0] );
	}

	public function test_refuses_a_shipped_copyleft_package(): void {
		list( $code, $errors ) = $this->run_script( 'lock-shipped-copyleft.json', 'notice-wp.txt' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'wp-phpunit/wp-phpunit has GPL', $errors );
	}

	public function test_refuses_a_dev_package_with_no_exception(): void {
		list( $code, $errors ) = $this->run_script( 'lock-dev-copyleft.json', 'notice-empty.txt' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'acme/tool has GPL-3.0-or-later.', $errors );
	}

	public function test_refuses_a_shipped_package_that_notice_does_not_name(): void {
		list( $code, $errors ) = $this->run_script( 'lock-allowed.json', 'notice-empty.txt' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'NOTICE does not name acme/shipped.', $errors );
	}

	public function test_allows_an_npm_dev_package_with_a_listed_exception(): void {
		$this->assertSame(
			0,
			$this->run_script( 'lock-allowed.json', 'notice-shipped.txt', 'pnpm-allowed.json' )[0]
		);
	}

	public function test_refuses_an_npm_package_with_a_copyleft_licence(): void {
		list( $code, $errors ) = $this->run_script(
			'lock-allowed.json',
			'notice-shipped.txt',
			'pnpm-copyleft.json'
		);
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'acme-browser-tool has GPL-3.0-or-later.', $errors );
	}
}
