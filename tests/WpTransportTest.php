<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Gatepost\WooCommerce\TransportFailure;
use Gatepost\WooCommerce\WpTransport;
use Gatepost\WooCommerce\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use Gatepost\WooCommerce\Vendor\Psr\Http\Message\ResponseInterface;
use WP_Error;
use WP_UnitTestCase;

/**
 * The PSR-18 transport over the WordPress HTTP API.
 */
final class WpTransportTest extends WP_UnitTestCase {

	/**
	 * The arguments of the last request that reached WordPress.
	 *
	 * @var array<string, mixed>
	 */
	private array $sent = array();

	/**
	 * Sends one GET request through a transport with a limit of 3 seconds.
	 *
	 * @param mixed $reply What WordPress gives back for the request.
	 */
	private function send( $reply ): ResponseInterface {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( $reply ) {
				$this->sent = $args;
				return $reply;
			},
			10,
			2
		);
		$factory = new Psr17Factory();
		$url     = 'https://api.postcode.gov.ng/v1/lookup?code=FC-01-Z99-ZZ-01';
		$request = $factory->createRequest( 'GET', $url )
			->withHeader( 'X-API-Key', 'nipost_live_example' );
		return ( new WpTransport( 3000, $factory ) )->sendRequest( $request );
	}

	public function test_sends_the_method_the_headers_and_the_time_limit(): void {
		$this->send(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => '',
			)
		);
		$this->assertSame( 'GET', $this->sent['method'] );
		$this->assertSame( 'nipost_live_example', $this->sent['headers']['X-API-Key'] );
		$this->assertEquals( 3, $this->sent['timeout'] );
		$this->assertSame( 0, $this->sent['redirection'] );
	}

	public function test_sends_a_fixed_user_agent_with_no_address_of_the_store(): void {
		$this->send(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => '',
			)
		);
		$this->assertSame( 'gatepost-postcode-for-woocommerce', $this->sent['user-agent'] );
		$this->assertStringNotContainsString( home_url(), $this->sent['user-agent'] );
		$agent = $this->sent['user-agent'];
		$this->assertStringNotContainsString( get_bloginfo( 'version' ), $agent );
	}

	public function test_gives_the_status_the_headers_and_the_body(): void {
		$response = $this->send(
			array(
				'response' => array( 'code' => 429 ),
				'headers'  => array( 'retry-after' => '1' ),
				'body'     => '{"error":{"code":"rate_limited"}}',
			)
		);
		$this->assertSame( 429, $response->getStatusCode() );
		$this->assertSame( '1', $response->getHeaderLine( 'Retry-After' ) );
		$this->assertSame( '{"error":{"code":"rate_limited"}}', (string) $response->getBody() );
	}

	public function test_leaves_the_host_header_to_wordpress(): void {
		$this->send(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => '',
			)
		);
		$this->assertArrayNotHasKey( 'Host', $this->sent['headers'] );
		$this->assertArrayHasKey( 'X-API-Key', $this->sent['headers'] );
	}

	public function test_gives_a_failure_for_a_header_that_breaks_the_standard(): void {
		try {
			$this->send(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( "bad header\n" => 'x' ),
					'body'     => '',
				)
			);
			$this->fail( 'The transport gave a response.' );
		} catch ( TransportFailure $failure ) {
			$message = 'No response arrived from the postcode gateway.';
			$this->assertSame( $message, $failure->getMessage() );
		}
	}

	public function test_keeps_the_postcode_out_of_the_message_when_no_response_arrives(): void {
		$error = new WP_Error( 'http_request_failed', 'cURL error 28 for FC-01-Z99-ZZ-01' );
		try {
			$this->send( $error );
			$this->fail( 'The transport gave a response.' );
		} catch ( TransportFailure $failure ) {
			$message = 'No response arrived from the postcode gateway.';
			$this->assertSame( $message, $failure->getMessage() );
			$query = $failure->getRequest()->getUri()->getQuery();
			$this->assertSame( 'code=FC-01-Z99-ZZ-01', $query );
		}
	}
}
