<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests\Support;

use WP_Error;

/**
 * Answers the requests to NIPOST's gateway with the spec's synthetic fixtures, through the
 * pre_http_request filter of WordPress. No test reaches the real gateway. The browser tests load
 * this file from a must-use plugin.
 */
final class FakeGateway {

	const HOST = 'api.postcode.gov.ng';

	/**
	 * The URL and the arguments of each request that reached the fake, in order.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	public array $requests = array();

	/**
	 * Builds a fake. Call start() to make WordPress use it.
	 *
	 * @param string $answer How the fake answers: "fixtures" for the lookup fixtures, the name of
	 *                       a file in spec/fixtures/errors such as "rate-limited", or
	 *                       "no-response" for a network failure.
	 */
	public function __construct( private readonly string $answer = 'fixtures' ) {
	}

	/**
	 * Makes WordPress send each gateway request to this fake.
	 */
	public function start(): self {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
		return $this;
	}

	/**
	 * Answers one request to the gateway. Requests to other hosts pass on.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt The answer of an earlier filter.
	 * @param array<string, mixed>                $args    The request arguments.
	 * @param string                              $url     The request URL.
	 * @return false|array<string, mixed>|WP_Error
	 */
	public function answer( $preempt, array $args, string $url ) {
		if ( self::HOST !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $preempt;
		}
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( 'no-response' === $this->answer ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: no connection to ' . $url );
		}
		$fixture = 'fixtures' === $this->answer
			? self::lookup_fixture( $url )
			: 'errors/' . $this->answer . '.json';
		return self::response( $fixture );
	}

	/**
	 * Picks the fixture that the mock server gives for a lookup: the synthetic unit
	 * FC-01-Z99-ZZ-01 exists, and every other postcode does not.
	 *
	 * @param string $url The request URL.
	 */
	private static function lookup_fixture( string $url ): string {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$code = $query['code'] ?? '';
		return 'FC-01-Z99-ZZ-01' === $code ? 'lookup/valid-level-1.json' : 'lookup/not-found.json';
	}

	/**
	 * Builds the response that the WordPress HTTP API gives for a fixture.
	 *
	 * @param string $fixture The path of the fixture in spec/fixtures.
	 * @return array<string, mixed>
	 */
	private static function response( string $fixture ): array {
		$path = dirname( __DIR__, 2 ) . '/spec/fixtures/' . $fixture;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data    = json_decode( (string) file_get_contents( $path ), true );
		$headers = array( 'content-type' => 'application/json' );
		if ( 429 === $data['status'] ) {
			$headers['retry-after'] = '1';
		}
		return array(
			'headers'  => $headers,
			'body'     => (string) wp_json_encode( $data['body'] ),
			'response' => array(
				'code'    => $data['status'],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
