<?php

namespace obregonco\B2\Http;

use obregonco\B2\ErrorHandler;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface;

/**
 * Client wrapper around Guzzle.
 *
 * @package obregonco\B2\Http
 */
class Client extends GuzzleClient
{
    public $retryLimit   = 10;
    public $retryWaitSec = 10;

    /**
     * Sends a response to the B2 API, automatically handling decoding JSON and errors.
     *
     * @param string $method
     * @param null $uri
     * @param array $options
     * @param bool $asJson
     * @return mixed|string
     */
    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        $response = parent::request($method, $uri, $options);

        // Support for 503 "too busy errors". Retry multiple times before failure
        $retries = 0;
        $wait    = $this->retryWaitSec;
        while ($response->getStatusCode() === 503 and $this->retryLimit > $retries) {
            $retries++;
            sleep($wait);
            $response = parent::request($method, $uri, $options);
            // Wait 20% longer if it fails again
            $wait *= 1.2;
        }
        if ($response->getStatusCode() !== 200) {
            ErrorHandler::handleErrorResponse($response);
        }

        return $response->getBody();
    }
}
