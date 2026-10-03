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
 * The gate of scripts/check-ci-runs.php, run on small files of check runs.
 */
final class CheckCiRunsTest extends WP_UnitTestCase {

	/**
	 * Runs the script on a fixture.
	 *
	 * @param string $fixture The file in tests/fixtures.
	 * @return array{int, string, string} The exit code, the error output and the output.
	 */
	private function run_script( string $fixture ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- the script runs outside WordPress.
		$process = proc_open(
			array(
				PHP_BINARY,
				dirname( __DIR__ ) . '/scripts/check-ci-runs.php',
				__DIR__ . '/fixtures/' . $fixture,
			),
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

	public function test_accepts_a_commit_whose_jobs_all_succeeded(): void {
		$run = $this->run_script( 'ci-passed.json' );
		$this->assertSame( 0, $run[0], $run[1] );
	}

	public function test_refuses_a_failed_job_of_the_matrix(): void {
		list( $code, $errors ) = $this->run_script( 'ci-failed.json' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'did not succeed', $errors );
		$this->assertStringContainsString( 'failure', $errors );
	}

	public function test_refuses_a_job_that_is_still_running(): void {
		list( $code, $errors ) = $this->run_script( 'ci-pending.json' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( '"e2e"', $errors );
		$this->assertStringContainsString( 'in_progress', $errors );
	}

	public function test_refuses_a_missing_job(): void {
		list( $code, $errors ) = $this->run_script( 'ci-missing-job.json' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( '"plugin-check" did not run', $errors );
	}

	public function test_refuses_a_missing_leg_of_the_test_matrix(): void {
		list( $code, $errors ) = $this->run_script( 'ci-missing-leg.json' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Only 2 of 3 test jobs', $errors );
	}

	public function test_counts_a_job_by_its_newest_run(): void {
		$this->assertSame( 0, $this->run_script( 'ci-old-failure.json' )[0] );
		$this->assertSame( 1, $this->run_script( 'ci-new-failure.json' )[0] );
	}

	public function test_ignores_a_check_run_of_another_app(): void {
		list( $code, $errors ) = $this->run_script( 'ci-foreign-app.json' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( '"e2e" did not run', $errors );
	}

	public function test_refuses_a_file_that_is_not_json_and_a_missing_file(): void {
		$this->assertSame( 1, $this->run_script( 'ci-not-json.json' )[0] );
		$this->assertSame( 1, $this->run_script( 'does-not-exist.json' )[0] );
	}
}
