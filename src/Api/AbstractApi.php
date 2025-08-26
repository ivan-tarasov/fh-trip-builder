<?php

namespace TripBuilder\Api;

use TripBuilder\Controllers\AbstractController;
use TripBuilder\Helper;
use TripBuilder\Routs;

class AbstractApi extends AbstractController
{
    const HEADER_AUTH_KEY = 'Authorization';

    const REQUEST_METHOD_GET = 'GET';
    const REQUEST_METHOD_POST = 'POST';
    const REQUEST_METHOD_PUT = 'PUT';
    const REQUEST_METHOD_PATCH = 'PATCH';
    const REQUEST_METHOD_DELETE = 'DELETE';
    const REQUEST_METHOD_HEAD = 'HEAD';
    const REQUEST_METHOD_OPTIONS = 'OPTIONS';

    const EXCLUDE_AUTH_CHECK_ENDPOINTS = [
        '/api/airports/autofill',
    ];

    const RAW_RESPONSE_ENDPOINTS = [
        '/api/airports/autofill',
    ];

    const DB_TABLE_AIRLINES = 'airlines';
    const DB_TABLE_AIRPORTS = 'airports';
    const DB_TABLE_BOOKINGS = 'bookings';
    const DB_TABLE_COUNTRIES = 'countries';
    const DB_TABLE_FLIGHTS = 'flights';

    /**
     * Minimum security at this time...
     */
    private array $authorizedTokens = [
        'SomeAPItoken_$ecretWORD---orHASH',
        'AnotherAPIt0ken-$ecretHash',
        'And@nothEr_Auth0riz@tionKey',
    ];

    private array $headers = [];
    protected array $data = [];
    private string $allowedMethod;

    public function __construct($method = false)
    {
        parent::__construct();

        // By default, we accept only the POST request method if not provided another one
        $this->setAllowedMethod($method ?: self::REQUEST_METHOD_POST);

        $this->guardUnauthorizedAccess();
        $this->guardNotAllowedRequestMethod();

        $this->setRequestData();
    }

    private function guardUnauthorizedAccess(): void
    {
        if (!in_array(Routs::getCurrentPage(), self::EXCLUDE_AUTH_CHECK_ENDPOINTS)
            && !in_array($this->getAuthToken(), $this->authorizedTokens)
        ) {
            HttpException::unauthorizedAccess();
        }
    }

    private function guardNotAllowedRequestMethod(): void
    {
        if ($this->getRequestMethod() !== $this->getAllowedMethod()) {
            HttpException::methodNotAllowed([$this->getAllowedMethod()]);
        }
    }

    public function sendResponse(int $statusCode, array $data = [], array $headers = []): void
    {
        // Sending response code
        http_response_code($statusCode);

        // Cleaning the output
        ob_clean();
        header_remove();

        // For some endpoints we not using typical output and returning raw data
        if (!in_array(Routs::getCurrentPage(), self::RAW_RESPONSE_ENDPOINTS)) {
            // Building response array
            $response = [
                'status' => $statusCode,
                'endpoint' => Helper::getUrlPath(),
                'method' => $this->getRequestMethod(),
                'timestamp' => date('Y-m-d H:i:s'),
                'data' => $data ?? [],
            ];

            $response = json_encode($response);
        } else {
            $response = json_encode($data);
        }

        // Setting up response headers
        self::addHeader('Content-type', 'application/json; charset=utf-8');
        self::addHeader('Access-Control-Max-Age', 3600);
        self::addHeader('Content-Length', strlen($response));

        if (!empty($headers)) {
            array_map([$this, 'addHeader'], array_keys($headers), $headers);
        }

        echo $response;
    }

    private static function addHeader(string $key, string|int $value): void
    {
        header(sprintf('%s: %s', $key, $value));
    }

    private function getRequestMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? '';
    }

    private function getAuthToken(): string
    {
        return preg_match('/Bearer\s+(\S+)\b/i', getallheaders()[self::HEADER_AUTH_KEY] ?? '', $matches)
            ? $matches[1]
            : '';
    }

    private function getRequestHeaders(): void
    {
        $this->setHeaders(getallheaders() ?? []);
    }

    private function setRequestData(): void
    {
        $data = file_get_contents('php://input');

        if (empty($data)) {
            return;
        }

        $this->data = json_decode($data, true);
    }

    protected function updateSearchStats(string $table, array $conditions): void
    {
        $this->db->where('code', $conditions, 'IN');

        if ($table == self::DB_TABLE_AIRPORTS) {
            $this->db->orWhere('city_code', $conditions, 'IN');

            $this->db->update($table, [
                'search_count' => $this->db->inc(1),
                'last_search'  => $this->db->now(),
            ]);
        } elseif ($table == self::DB_TABLE_AIRLINES) {
            $this->db->update($table, [
                'book_count'  => $this->db->inc(1),
                'last_search' => $this->db->now(),
            ]);
        }
    }

    private function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    private function getHeaders(): array
    {
        return $this->headers;
    }

    public function setAllowedMethod(string $method): void
    {
        $this->allowedMethod = $method;
    }

    private function getAllowedMethod(): string
    {
        return $this->allowedMethod;
    }
}
