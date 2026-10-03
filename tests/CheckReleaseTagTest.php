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
 * The tag check of scripts/check-release-tag.php, run on small fake plugin files.
 */
final class CheckReleaseTagTest extends WP_UnitTestCase {

	/**
	 * Runs the script on a plugin header fixture and a readme fixture.
	 *
	 * @param string $tag    The tag to check.
	 * @param string $header The fixture that holds the plugin header.
	 * @param string $readme The fixture that holds the readme.
	 * @return array{int, string, string} The exit code, the error output and the output.
	 */
	private function run_script( string $tag, string $header, string $readme ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- the script runs outside WordPress.
		$process = proc_open(
			array(
				PHP_BINARY,
				dirname( __DIR__ ) . '/scripts/check-release-tag.php',
				$tag,
				__DIR__ . '/fixtures/' . $header,
				__DIR__ . '/fixtures/' . $readme,
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

	public function test_accepts_a_tag_that_equals_both_versions(): void {
		$run = $this->run_script( 'v0.1.0', 'header-0.1.0.txt', 'stable-0.1.0.txt' );
		$this->assertSame( 0, $run[0] );
	}

	public function test_accepts_a_pre_release_version(): void {
		$run = $this->run_script( 'v0.2.0-rc.1', 'header-rc.txt', 'stable-rc.txt' );
		$this->assertSame( 0, $run[0] );
	}

	public function test_refuses_a_tag_that_differs_from_the_plugin_header(): void {
		list( $code, $errors ) = $this->run_script(
			'v9.9.9',
			'header-0.1.0.txt',
			'stable-0.1.0.txt'
		);
		$this->assertSame( 1, $code );
		$this->assertStringContainsString(
			'The tag v9.9.9 does not match the plugin header version 0.1.0.',
			$errors
		);
	}

	public function test_refuses_a_tag_that_differs_from_the_stable_tag(): void {
		list( $code, $errors ) = $this->run_script(
			'v0.1.0',
			'header-0.1.0.txt',
			'stable-0.0.9.txt'
		);
		$this->assertSame( 1, $code );
		$this->assertStringContainsString(
			'The tag v0.1.0 does not match the stable tag 0.0.9.',
			$errors
		);
	}

	public function test_refuses_a_file_with_no_version(): void {
		list( $code, $errors ) = $this->run_script(
			'v0.1.0',
			'header-none.txt',
			'stable-0.1.0.txt'
		);
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'The plugin header has no Version line.', $errors );
	}

	/**
	 * Each value that is not a plain version tag.
	 *
	 * @return array<string, array{string}>
	 */
	public static function bad_tags(): array {
		return array(
			'no v'            => array( '0.1.0' ),
			'a flag'          => array( '--draft' ),
			'two parts'       => array( 'v0.1' ),
			'a leading zero'  => array( 'v01.1.0' ),
			'a space'         => array( 'v0.1.0 x' ),
			'a shell command' => array( 'v0.1.0;id' ),
			'a newline'       => array( "v0.1.0\nx" ),
			'empty'           => array( '' ),
		);
	}

	/**
	 * Refuses a tag that is not a plain version.
	 *
	 * @dataProvider bad_tags
	 *
	 * @param string $tag The tag that the script must refuse.
	 */
	public function test_refuses_a_tag_that_is_not_a_version( string $tag ): void {
		list( $code, $errors ) = $this->run_script( $tag, 'header-0.1.0.txt', 'stable-0.1.0.txt' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'is not a version tag', $errors );
	}
}
