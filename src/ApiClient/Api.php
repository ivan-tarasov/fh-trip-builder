<?php

namespace TripBuilder\ApiClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use TripBuilder\Debug\dBug;

class Api
{
    const TIMEOUT = 10;

    protected $client;

    public function __construct($baseUrl)
    {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout'  => self::TIMEOUT
        ]);
    }

    /**
     * @param string $endpoint
     * @param array  $headers
     * @param array  $params
     * @return array
     * @throws \Exception|GuzzleException
     */
    public function get(string $endpoint, array $headers = [], array $params = []): array
    {
        try {
            $response = $this->client->get($endpoint, [
                'query'   => $params,
                'headers' => $headers,
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new \Exception('GET request failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param string $endpoint
     * @param array  $headers
     * @param array  $params
     * @return \stdClass
     * @throws GuzzleException
     */
    public function post(string $endpoint, array $headers = [], array $params = []): \stdClass
    {
        try {
            $response = $this->client->post($endpoint, [
                'json'    => $params,
                'headers' => $headers,
            ]);

            return json_decode($response->getBody(), false);
        } catch (RequestException $e) {
            throw new \Exception('POST request failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

}
