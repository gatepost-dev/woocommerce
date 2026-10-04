<?php
/**
 * Fails unless the tag is a plain version tag, such as v0.1.0, and equals both the Version line
 * of the plugin header and the Stable tag line of readme.txt. A release of the wrong tree would
 * otherwise ship under the wrong name. The tag arrives as an argument, never in a shell line.
 *
 * Usage: php scripts/check-release-tag.php <tag> [plugin file [readme.txt]]
 *
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

'cli' === PHP_SAPI || exit( 1 );

const GATEPOST_NUMBER = '(?:0|[1-9]\d*)';
const GATEPOST_TAG    = '/\Av' . GATEPOST_NUMBER . '\.' . GATEPOST_NUMBER . '\.' . GATEPOST_NUMBER
	. '(?:-[0-9A-Za-z.]+)?\z/';

/**
 * Prints a message to the error output and stops.
 *
 * @param string $message What went wrong.
 */
function gatepost_fail( string $message ): never {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Reads the value of one labelled line.
 *
 * @param string $file    The file to read.
 * @param string $pattern A pattern with one group for the value.
 * @param string $what    The name of the file, for the error message.
 * @param string $label   The name of the line, for the error message.
 * @return string The value.
 */
function gatepost_value( string $file, string $pattern, string $what, string $label ): string {
	$text = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	if ( 1 !== preg_match( $pattern, $text, $found ) ) {
		gatepost_fail( "$what has no $label line." );
	}
	return trim( $found[1] );
}

$gatepost_root   = dirname( __DIR__ );
$gatepost_tag    = $argv[1] ?? '';
$gatepost_plugin = $argv[2] ?? $gatepost_root . '/gatepost-postcode-for-woocommerce.php';
$gatepost_readme = $argv[3] ?? $gatepost_root . '/readme.txt';

if ( 1 !== preg_match( GATEPOST_TAG, $gatepost_tag ) ) {
	gatepost_fail(
		'The value "' . addcslashes( $gatepost_tag, "\0..\37" ) . '" is not a version tag. '
		. 'Use v and three numbers, such as v0.1.0.'
	);
}

$gatepost_header = gatepost_value(
	$gatepost_plugin,
	'/^[ \t\/*#@]*Version:[ \t]*(\S+)[ \t]*$/m',
	'The plugin header',
	'Version'
);
$gatepost_stable = gatepost_value(
	$gatepost_readme,
	'/^Stable tag:[ \t]*(\S+)[ \t]*$/mi',
	'The readme',
	'Stable tag'
);

if ( 'v' . $gatepost_header !== $gatepost_tag ) {
	gatepost_fail(
		"The tag $gatepost_tag does not match the plugin header version $gatepost_header."
	);
}
if ( 'v' . $gatepost_stable !== $gatepost_tag ) {
	gatepost_fail( "The tag $gatepost_tag does not match the stable tag $gatepost_stable." );
}
echo "The tag $gatepost_tag matches the plugin header and the stable tag.\n";
