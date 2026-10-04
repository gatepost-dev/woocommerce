<?php
/**
 * Fails when the tests cover less than 80 % of the lines in src/. T-7 sets 80 % for UI code.
 * The plugin measures lines, not branches: with branch coverage, Xdebug took more than ten
 * minutes for the WordPress test run, against about one minute for lines.
 *
 * Usage: php scripts/check-coverage.php build/coverage/cobertura.xml
 *
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

'cli' === PHP_SAPI || exit( 1 );

const GATEPOST_COVERAGE_FLOOR = 0.80;

$gatepost_report = $argv[1] ?? '';
if ( ! is_file( $gatepost_report ) ) {
	fwrite( STDERR, "Cannot read the coverage report {$gatepost_report}.\n" );
	exit( 2 );
}
$gatepost_xml = simplexml_load_file( $gatepost_report );
if ( false === $gatepost_xml || ! isset( $gatepost_xml['line-rate'] ) ) {
	fwrite( STDERR, "The coverage report {$gatepost_report} has no line rate.\n" );
	exit( 2 );
}
$gatepost_rate = (float) $gatepost_xml['line-rate'];
printf(
	"The tests cover %.2f %% of the lines. The floor is %d %%.\n",
	$gatepost_rate * 100,
	GATEPOST_COVERAGE_FLOOR * 100
);
exit( $gatepost_rate < GATEPOST_COVERAGE_FLOOR ? 1 : 0 );
