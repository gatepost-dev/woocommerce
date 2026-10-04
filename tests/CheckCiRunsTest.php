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

	public function test_expects_the_jobs_and_the_legs_of_the_ci_workflow(): void {
		$root = dirname( __DIR__ );
		// phpcs:disable WordPress.WP.AlternativeFunctions -- these are local files.
		$workflow = (string) file_get_contents( $root . '/.github/workflows/ci.yml' );
		$script   = (string) file_get_contents( $root . '/scripts/check-ci-runs.php' );
		// phpcs:enable
		preg_match( '/GATEPOST_TESTS_LEGS\s*=\s*(\d+)/', $script, $legs );
		preg_match( '/GATEPOST_REQUIRED_JOBS\s*=\s*array\(([^)]*)\)/', $script, $jobs );
		preg_match_all( "/'([a-z-]+)'/", $jobs[1] ?? '', $names );
		$section = substr( $workflow, (int) strpos( $workflow, "\njobs:\n" ) );
		preg_match_all( '/^  ([a-z-]+):$/m', $section, $found );
		$expected = array_merge( $names[1], array( 'tests' ) );
		sort( $expected );
		$actual = $found[1];
		sort( $actual );
		$this->assertSame( $expected, $actual, 'ci.yml has a job that the gate does not know.' );
		preg_match( '/^  tests:\n.*?(?=^  [a-z-]+:\n)/ms', $section, $tests );
		$rows          = preg_match_all( '/^\s+- \{ php:/m', $tests[0] ?? '' );
		$expected_legs = (int) ( $legs[1] ?? 0 );
		$this->assertSame( $expected_legs, $rows, 'The matrix has another number of legs.' );
	}
}
