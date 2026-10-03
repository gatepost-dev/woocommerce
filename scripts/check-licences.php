<?php
/**
 * Checks each package of composer.lock against DEP-4: a permissive licence. The packages that the
 * zip ships must have one. A dev package may instead be one of the exceptions below, each with
 * its reason. NOTICE must also name each package that the zip ships. The browser tests add npm
 * packages. None of them is in the zip. The optional third argument names a file with the output
 * of `pnpm licenses list --json`.
 *
 * Usage: php scripts/check-licences.php [composer.lock [NOTICE [pnpm-licences.json]]]
 *
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

'cli' === PHP_SAPI || exit( 1 );

const GATEPOST_PERMISSIVE = array( 'MIT', 'ISC', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause' );

// Dev tools only. None of them is in the zip.
const GATEPOST_DEV_EXCEPTIONS = array(
	// The PHP version and WordPress sniffs that php.md Part B names, and their PHPCS helpers.
	'phpcompatibility/php-compatibility'          => 'LGPL-3.0-or-later',
	'phpcompatibility/phpcompatibility-paragonie' => 'LGPL-3.0-or-later',
	'phpcompatibility/phpcompatibility-wp'        => 'LGPL-3.0-or-later',
	'phpcsstandards/phpcsextra'                   => 'LGPL-3.0-or-later',
	'phpcsstandards/phpcsutils'                   => 'LGPL-3.0-or-later',
	// WordPress's own test suite, which the integration tests run on.
	'wp-phpunit/wp-phpunit'                       => 'GPL-2.0-or-later',
	// The accessibility check of the browser tests.
	'@axe-core/playwright'                        => 'MPL-2.0',
	'axe-core'                                    => 'MPL-2.0',
);

/**
 * Reads a JSON file into an array.
 *
 * @param string $path The file.
 * @return array<mixed>
 */
function gatepost_read_json( string $path ): array {
	$decoded = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $decoded ) ? $decoded : array();
}

/**
 * Gives each package of composer.lock: its name, its licences and whether the zip ships it.
 *
 * @param string $lock_file The path of composer.lock.
 * @return array<int, array{name: string, licences: array<int, string>, shipped: bool}>
 */
function gatepost_composer_packages( string $lock_file ): array {
	$lock     = gatepost_read_json( $lock_file );
	$packages = array();
	foreach ( array( 'packages', 'packages-dev' ) as $group ) {
		foreach ( $lock[ $group ] ?? array() as $package ) {
			$packages[] = array(
				'name'     => (string) $package['name'],
				'licences' => (array) ( $package['license'] ?? array() ),
				'shipped'  => 'packages' === $group,
			);
		}
	}
	return $packages;
}

/**
 * Gives each npm package of the pnpm report. The report groups the packages by licence. All of
 * them are dev packages.
 *
 * @param string $report_file The path of the file that holds `pnpm licenses list --json`.
 * @return array<int, array{name: string, licences: array<int, string>, shipped: bool}>
 */
function gatepost_npm_packages( string $report_file ): array {
	$packages = array();
	foreach ( gatepost_read_json( $report_file ) as $licence => $group ) {
		foreach ( (array) $group as $package ) {
			$packages[] = array(
				'name'     => (string) $package['name'],
				'licences' => array( (string) $licence ),
				'shipped'  => false,
			);
		}
	}
	return $packages;
}

/**
 * Tells whether DEP-4 allows a package.
 *
 * @param array{name: string, licences: array<int, string>, shipped: bool} $package The package.
 */
function gatepost_is_allowed( array $package ): bool {
	if ( array() !== array_intersect( $package['licences'], GATEPOST_PERMISSIVE ) ) {
		return true;
	}
	$exception = GATEPOST_DEV_EXCEPTIONS[ $package['name'] ] ?? null;
	return ! $package['shipped'] && in_array( $exception, $package['licences'], true );
}

$gatepost_lock     = $argv[1] ?? dirname( __DIR__ ) . '/composer.lock';
$gatepost_notice   = (string) file_get_contents( $argv[2] ?? dirname( __DIR__ ) . '/NOTICE' );
$gatepost_packages = gatepost_composer_packages( $gatepost_lock );
if ( isset( $argv[3] ) ) {
	$gatepost_packages = array_merge( $gatepost_packages, gatepost_npm_packages( $argv[3] ) );
}
$gatepost_failures = array();
foreach ( $gatepost_packages as $gatepost_package ) {
	if ( ! gatepost_is_allowed( $gatepost_package ) ) {
		$gatepost_failures[] = $gatepost_package['name'] . ' has '
			. implode( ' or ', $gatepost_package['licences'] ) . '.';
	}
	$gatepost_named = str_contains( $gatepost_notice, $gatepost_package['name'] );
	if ( $gatepost_package['shipped'] && ! $gatepost_named ) {
		$gatepost_failures[] = 'NOTICE does not name ' . $gatepost_package['name'] . '.';
	}
}
if ( array() !== $gatepost_failures ) {
	$gatepost_failures[] = 'DEP-4 allows only permissive licences. NOTICE names each shipped one.';
	fwrite( STDERR, implode( "\n", $gatepost_failures ) . "\n" );
	exit( 1 );
}
printf( "%d packages, each with an allowed licence.\n", count( $gatepost_packages ) );
