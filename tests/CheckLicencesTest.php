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
	 * @return array{int, string, string} The exit code, the error output and the output.
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
		$output  = (string) stream_get_contents( $pipes[1] );
		$errors  = (string) stream_get_contents( $pipes[2] );
		return array( proc_close( $process ), $errors, $output );
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

	/**
	 * Gives each report that the script must refuse, by its fixture name.
	 *
	 * @return array<string, array{string}>
	 */
	public function unusable_reports(): array {
		return array(
			'a missing file'             => array( 'pnpm-missing.json' ),
			'an empty file'              => array( 'pnpm-empty.json' ),
			'a file that is not JSON'    => array( 'pnpm-invalid.json' ),
			'JSON that is not an object' => array( 'pnpm-list.json' ),
			'a report with no package'   => array( 'pnpm-no-packages.json' ),
		);
	}

	/**
	 * Refuses a report that the script cannot use, with the exit code 2.
	 *
	 * @dataProvider unusable_reports
	 *
	 * @param string $report The fixture of the report.
	 */
	public function test_refuses_a_report_it_cannot_use( string $report ): void {
		list( $code, $errors ) = $this->run_script(
			'lock-allowed.json',
			'notice-shipped.txt',
			$report
		);
		$this->assertSame( 2, $code );
		$this->assertStringContainsString( 'pnpm licence report', $errors );
	}

	public function test_counts_the_composer_and_the_npm_packages_apart(): void {
		list( $code, , $output ) = $this->run_script(
			'lock-allowed.json',
			'notice-shipped.txt',
			'pnpm-allowed.json'
		);
		$this->assertSame( 0, $code );
		$this->assertStringContainsString( '2 Composer packages and 2 npm packages', $output );
	}

	public function test_allows_an_npm_package_with_either_of_two_permissive_licences(): void {
		$this->assertSame(
			0,
			$this->run_script( 'lock-allowed.json', 'notice-shipped.txt', 'pnpm-either.json' )[0]
		);
	}
}
