<?php

declare(strict_types=1);

namespace TripBuilder\ApiClient;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use stdClass;

final readonly class Api
{
    private const int TIMEOUT = 10;

    private Client $client;

    public function __construct(string $baseUrl)
    {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout' => self::TIMEOUT,
        ]);
    }

    /**
     * @throws Exception|GuzzleException
     */
    public function get(string $endpoint, array $headers = [], array $params = []): array
    {
        try {
            $response = $this->client->get($endpoint, [
                'query' => $params,
                'headers' => $headers,
            ]);

            $decoded = json_decode((string) $response->getBody(), true);

            if (!is_array($decoded)) {
                throw new Exception('GET request returned malformed JSON');
            }

            return $decoded;
        } catch (TransferException $e) {
            throw new Exception('GET request failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @throws GuzzleException
     */
    public function post(string $endpoint, array $headers = [], array $params = []): stdClass
    {
        try {
            $response = $this->client->post($endpoint, [
                'json' => $params,
                'headers' => $headers,
            ]);

            $decoded = json_decode((string) $response->getBody(), false);

            if (!$decoded instanceof stdClass) {
                throw new Exception('POST request returned malformed JSON');
            }

            return $decoded;
        } catch (TransferException $e) {
            throw new Exception('POST request failed: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

}
