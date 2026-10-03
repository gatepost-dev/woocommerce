<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

use Gatepost\WooCommerce\Vendor\Psr\Http\Client\NetworkExceptionInterface;
use Gatepost\WooCommerce\Vendor\Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * No response arrived. The message leaves out the reason from WordPress, because that reason can
 * quote the request URL, and the URL holds the postcode (ERR-3).
 */
final class TransportFailure extends RuntimeException implements NetworkExceptionInterface {

	/**
	 * The request that got no response.
	 *
	 * @var RequestInterface
	 */
	private RequestInterface $request;

	/**
	 * Only for_request() builds a failure, because a failure needs its request.
	 */
	private function __construct() {
		parent::__construct( 'No response arrived from the postcode gateway.' );
	}

	/**
	 * Builds the failure.
	 *
	 * @param RequestInterface $request The request that got no response.
	 */
	public static function for_request( RequestInterface $request ): self {
		$failure          = new self();
		$failure->request = $request;
		return $failure;
	}

	/**
	 * Gives the request that got no response.
	 */
	public function getRequest(): RequestInterface {
		return $this->request;
	}
}
