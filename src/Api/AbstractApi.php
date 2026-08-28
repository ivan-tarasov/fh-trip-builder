<?php

declare(strict_types=1);

namespace TripBuilder\Api;

use TripBuilder\Database\Connection;
use TripBuilder\Database\Table;
use TripBuilder\Helper;
use TripBuilder\Routes;

abstract class AbstractApi
{
    private const string HEADER_AUTH_KEY = 'Authorization';

    private const array EXCLUDE_AUTH_CHECK_ENDPOINTS = [
        '/api/airports/autofill',
    ];

    private const array RAW_RESPONSE_ENDPOINTS = [
        '/api/airports/autofill',
    ];

    protected const string DB_TABLE_AIRLINES = Table::Airlines->value;
    protected const string DB_TABLE_AIRPORTS = Table::Airports->value;
    protected const string DB_TABLE_BOOKINGS = Table::Bookings->value;
    protected const string DB_TABLE_COUNTRIES = Table::Countries->value;
    protected const string DB_TABLE_FLIGHTS = Table::Flights->value;

    // Parsed request payload: readable by the endpoint subclasses, but only
    // this base class may populate it (from setRequestData()).
    protected private(set) array $data = [];
    private HttpMethod $allowedMethod;

    private ?Connection $connection = null;

    public function __construct(?HttpMethod $method = null)
    {
        // By default, we accept only the POST request method if not provided another one
        $this->setAllowedMethod($method ?? HttpMethod::Post);

        $this->guardUnauthorizedAccess();
        $this->guardNotAllowedRequestMethod();

        $this->setRequestData();
    }

    protected function connection(): Connection
    {
        return $this->connection ??= Connection::fromEnv();
    }

    private function guardUnauthorizedAccess(): void
    {
        if (!in_array(Routes::getCurrentPage(), self::EXCLUDE_AUTH_CHECK_ENDPOINTS, true)
            && !$this->isAuthorizedToken($this->getAuthToken())
        ) {
            ApiResponder::unauthorizedAccess();
        }
    }

    private function isAuthorizedToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        foreach (explode(',', $_ENV['API_ACCEPTED_TOKENS'] ?? '') as $authorized) {
            if ($authorized !== '' && hash_equals($authorized, $token)) {
                return true;
            }
        }

        return false;
    }

    private function guardNotAllowedRequestMethod(): void
    {
        if ($this->getRequestMethod() !== $this->getAllowedMethod()->value) {
            ApiResponder::methodNotAllowed([$this->getAllowedMethod()]);
        }
    }

    public function sendResponse(HttpStatus $status, array $data = [], array $headers = []): void
    {
        // Sending response code
        http_response_code($status->value);

        // Cleaning the output
        if (ob_get_level() > 0) {
            ob_clean();
        }

        header_remove();

        // For some endpoints we not using typical output and returning raw data
        if (!in_array(Routes::getCurrentPage(), self::RAW_RESPONSE_ENDPOINTS)) {
            // Building response array
            $response = [
                'status' => $status->value,
                'endpoint' => Helper::getUrlPath(),
                'method' => $this->getRequestMethod(),
                'timestamp' => date('Y-m-d H:i:s'),
                'data' => $data,
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
            array_map(self::addHeader(...), array_keys($headers), $headers);
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
        // Header names are case-insensitive, so normalize before the lookup
        $headers = array_change_key_case(getallheaders());

        return preg_match('/Bearer\s+(\S+)\b/i', $headers[strtolower(self::HEADER_AUTH_KEY)] ?? '', $matches)
            ? $matches[1]
            : '';
    }

    private function setRequestData(): void
    {
        $data = file_get_contents('php://input');

        if (empty($data)) {
            return;
        }

        $decoded = json_decode($data, true);

        if (! is_array($decoded)) {
            ApiResponder::badRequest('Malformed JSON body');
        }

        $this->data = $decoded;
    }

    /**
     * @param list<string> $conditions
     */
    protected function updateSearchStats(string $table, array $conditions): void
    {
        $in = implode(', ', array_fill(0, count($conditions), '?'));

        if ($table === self::DB_TABLE_AIRPORTS) {
            $this->connection()->execute(
                "UPDATE `$table` SET search_count = search_count + 1, last_search = NOW()"
                . " WHERE code IN ($in) OR city_code IN ($in)",
                [...$conditions, ...$conditions],
            );
        } elseif ($table === self::DB_TABLE_AIRLINES) {
            $this->connection()->execute(
                "UPDATE `$table` SET book_count = book_count + 1, last_search = NOW() WHERE code IN ($in)",
                array_values($conditions),
            );
        }
    }

    public function setAllowedMethod(HttpMethod $method): void
    {
        $this->allowedMethod = $method;
    }

    private function getAllowedMethod(): HttpMethod
    {
        return $this->allowedMethod;
    }
}
