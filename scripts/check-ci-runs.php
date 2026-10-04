<?php
/**
 * Fails unless the CI jobs of a commit all passed. The release workflow runs it before it builds
 * a zip, so a tag on a commit with a red or missing CI never reaches a release.
 *
 * The input is a file of check runs, one JSON value on each line: an object, or a list of
 * objects. Each object has the keys id, name, status, conclusion and app. A job that ran more
 * than once counts by its newest run, so a re-run that passed replaces a failed run. Only runs of
 * GitHub Actions count, because any other app can post a check run with any name.
 *
 * Usage: php scripts/check-ci-runs.php <file>
 *
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

'cli' === PHP_SAPI || exit( 1 );

// The jobs of ci.yml that gate a release. The tests job runs once for each leg of its matrix:
// the floors, the newest releases and MySQL. A leg that is missing fails the gate. A test of the
// plugin compares these names and the leg count with ci.yml, so a new leg cannot go unseen. The
// gate reads the runs by job name, and ci.yml is the only workflow that pushes to main.
const GATEPOST_REQUIRED_JOBS = array( 'check', 'e2e', 'plugin-check' );
const GATEPOST_TESTS_PREFIX  = 'tests (';
const GATEPOST_TESTS_LEGS    = 3;
const GATEPOST_ACTIONS_APP   = 'github-actions';

/**
 * Prints a message to the error output and stops.
 *
 * @param string $message What went wrong.
 */
function gatepost_fail( string $message ): never {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

$gatepost_file = $argv[1] ?? '';
if ( '' === $gatepost_file || ! is_readable( $gatepost_file ) ) {
	gatepost_fail( 'Give the file of check runs as the first argument.' );
}

$gatepost_newest = array();
foreach ( explode( "\n", (string) file_get_contents( $gatepost_file ) ) as $gatepost_line ) {
	if ( '' === trim( $gatepost_line ) ) {
		continue;
	}
	$gatepost_value = json_decode( $gatepost_line, true );
	if ( ! is_array( $gatepost_value ) ) {
		gatepost_fail( 'The file of check runs holds a line that is not JSON.' );
	}
	$gatepost_runs = array_is_list( $gatepost_value ) ? $gatepost_value : array( $gatepost_value );
	foreach ( $gatepost_runs as $gatepost_run ) {
		if ( ! is_array( $gatepost_run ) ) {
			continue;
		}
		if ( ! isset( $gatepost_run['name'], $gatepost_run['id'] ) ) {
			continue;
		}
		if ( GATEPOST_ACTIONS_APP !== ( $gatepost_run['app']['slug'] ?? '' ) ) {
			continue;
		}
		$gatepost_name = (string) $gatepost_run['name'];
		$gatepost_old  = $gatepost_newest[ $gatepost_name ]['id'] ?? -1;
		if ( (int) $gatepost_run['id'] > $gatepost_old ) {
			$gatepost_newest[ $gatepost_name ] = $gatepost_run;
		}
	}
}

$gatepost_problems = array();
$gatepost_tests    = 0;
foreach ( $gatepost_newest as $gatepost_name => $gatepost_run ) {
	$gatepost_is_tests = str_starts_with( (string) $gatepost_name, GATEPOST_TESTS_PREFIX );
	if ( ! $gatepost_is_tests && ! in_array( $gatepost_name, GATEPOST_REQUIRED_JOBS, true ) ) {
		continue;
	}
	$gatepost_tests += $gatepost_is_tests ? 1 : 0;
	if ( 'success' !== ( $gatepost_run['conclusion'] ?? null ) ) {
		$gatepost_state      = (string) ( $gatepost_run['conclusion'] ?? $gatepost_run['status'] );
		$gatepost_text       = "The job \"$gatepost_name\" did not succeed.";
		$gatepost_problems[] = "$gatepost_text Its state: $gatepost_state.";
	}
}
foreach ( GATEPOST_REQUIRED_JOBS as $gatepost_name ) {
	if ( ! isset( $gatepost_newest[ $gatepost_name ] ) ) {
		$gatepost_problems[] = "The job \"$gatepost_name\" did not run for this commit.";
	}
}
if ( GATEPOST_TESTS_LEGS > $gatepost_tests ) {
	$gatepost_problems[] = "Only $gatepost_tests of " . GATEPOST_TESTS_LEGS
		. ' test jobs ran for this commit.';
}

if ( array() !== $gatepost_problems ) {
	$gatepost_problems[] = 'Wait for CI to pass, then run the release again.';
	gatepost_fail( implode( "\n", $gatepost_problems ) );
}
echo "CI passed for this commit.\n";
