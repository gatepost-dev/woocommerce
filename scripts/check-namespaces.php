<?php
/**
 * Fails when plugin code names a bundled package without the prefix. The zip holds no vendor/
 * folder, so only the names under Gatepost\WooCommerce\Vendor\ exist on a real site (ADR 0001).
 * The tests load vendor/ too, so they cannot find this mistake.
 *
 * Usage: php scripts/check-namespaces.php [file or folder ...]
 *
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

'cli' === PHP_SAPI || exit( 1 );

// The SDK, nyholm/psr7 and each PSR package that Strauss copies.
const GATEPOST_UNPREFIXED = '/(?<![\w\\\\])\\\\?(?:Gatepost\\\\Postcode|Nyholm|Psr)\\\\/';

$gatepost_root  = dirname( __DIR__ );
$gatepost_paths = array_slice( $argv, 1 );
if ( array() === $gatepost_paths ) {
	$gatepost_paths = array(
		$gatepost_root . '/gatepost-postcode-for-woocommerce.php',
		$gatepost_root . '/uninstall.php',
		$gatepost_root . '/src',
	);
}

$gatepost_files = array();
foreach ( $gatepost_paths as $gatepost_path ) {
	if ( is_dir( $gatepost_path ) ) {
		$gatepost_walk = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $gatepost_path, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $gatepost_walk as $gatepost_file ) {
			if ( 'php' === $gatepost_file->getExtension() ) {
				$gatepost_files[] = $gatepost_file->getPathname();
			}
		}
	} elseif ( is_file( $gatepost_path ) ) {
		$gatepost_files[] = $gatepost_path;
	} else {
		fwrite( STDERR, "Cannot read {$gatepost_path}.\n" );
		exit( 2 );
	}
}

$gatepost_failures = array();
foreach ( $gatepost_files as $gatepost_file ) {
	$gatepost_lines = file( $gatepost_file, FILE_IGNORE_NEW_LINES );
	$gatepost_lines = false === $gatepost_lines ? array() : $gatepost_lines;
	foreach ( $gatepost_lines as $gatepost_number => $gatepost_line ) {
		if ( 1 === preg_match( GATEPOST_UNPREFIXED, $gatepost_line ) ) {
			$gatepost_failures[] = sprintf(
				'%s:%d uses a bundled name with no prefix.',
				$gatepost_file,
				$gatepost_number + 1
			);
		}
	}
}
if ( array() !== $gatepost_failures ) {
	$gatepost_failures[] = 'Use the names under Gatepost\WooCommerce\Vendor\ only (ADR 0001).';
	fwrite( STDERR, implode( "\n", $gatepost_failures ) . "\n" );
	exit( 1 );
}
printf( "%d files, each with prefixed names only.\n", count( $gatepost_files ) );
