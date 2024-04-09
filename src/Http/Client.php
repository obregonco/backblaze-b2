<?php

namespace obregonco\B2\Http;

use GuzzleHttp\Client as GuzzleClient;
use obregonco\B2\ErrorHandler;
use Psr\Http\Message\ResponseInterface;

/**
 * Client wrapper around Guzzle.
 */
class Client extends GuzzleClient
{
    public $retryLimit = 10;
    public $retryWaitSec = 10;

    /**
     * Sends a response to the B2 API, automatically handling decoding JSON and errors.
     *
     * @param null $uri
     *
     * @return mixed|string
     */
    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        $response = parent::request($method, $uri, $options);

        // Support for 503 "too busy errors". Retry multiple times before failure
        $retries = 0;
        $wait = $this->retryWaitSec;
        while (503 === $response->getStatusCode() and $this->retryLimit > $retries) {
            ++$retries;
            sleep($wait);
            $response = parent::request($method, $uri, $options);
            // Wait 20% longer if it fails again
            $wait *= 1.2;
        }
        if (200 !== $response->getStatusCode()) {
            ErrorHandler::handleErrorResponse($response);
        }

        return $response;
    }
}
