<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

use Gatepost\WooCommerce\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use Gatepost\WooCommerce\Vendor\Psr\Http\Client\ClientInterface;
use Gatepost\WooCommerce\Vendor\Psr\Http\Message\RequestInterface;
use Gatepost\WooCommerce\Vendor\Psr\Http\Message\ResponseInterface;
use InvalidArgumentException;

/**
 * Sends the gateway client's requests with the WordPress HTTP API. The site's proxy settings and
 * the pre_http_request filter then apply to them, as to every other request of WordPress.
 */
final class WpTransport implements ClientInterface {

	/**
	 * Builds the transport.
	 *
	 * @param int          $timeout_ms The time limit of one request. Give the client the same
	 *                                 limit.
	 * @param Psr17Factory $factory    Builds the responses.
	 */
	public function __construct(
		private readonly int $timeout_ms,
		private readonly Psr17Factory $factory,
	) {
	}

	/**
	 * Sends one request.
	 *
	 * @param RequestInterface $request The request that the client built.
	 * @throws TransportFailure When no response arrived, or the response cannot be read.
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$headers = array();
		foreach ( array_keys( $request->getHeaders() ) as $name ) {
			// WordPress adds the Host header itself, and a redirect would need another value.
			if ( 'host' !== strtolower( (string) $name ) ) {
				$headers[ $name ] = $request->getHeaderLine( $name );
			}
		}
		$reply = wp_safe_remote_request(
			(string) $request->getUri(),
			array(
				'method'      => $request->getMethod(),
				'headers'     => $headers,
				'body'        => (string) $request->getBody(),
				'timeout'     => $this->timeout_ms / 1000,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $reply ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output.
			throw TransportFailure::for_request( $request );
		}
		try {
			return $this->response_from( $reply );
		} catch ( InvalidArgumentException ) {
			// A header or a status that the PSR-7 messages refuse is a reply that cannot be read.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output.
			throw TransportFailure::for_request( $request );
		}
	}

	/**
	 * Builds the PSR-7 response for a WordPress reply.
	 *
	 * @param array<string, mixed> $reply The reply of the WordPress HTTP API.
	 * @throws InvalidArgumentException When the status or a header breaks RFC 7230.
	 */
	private function response_from( array $reply ): ResponseInterface {
		$status   = (int) wp_remote_retrieve_response_code( $reply );
		$response = $this->factory->createResponse( $status );
		foreach ( wp_remote_retrieve_headers( $reply ) as $name => $value ) {
			$response = $response->withHeader( (string) $name, $value );
		}
		$body = $this->factory->createStream( wp_remote_retrieve_body( $reply ) );
		return $response->withBody( $body );
	}
}
