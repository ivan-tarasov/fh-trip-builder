<?php

declare(strict_types=1);

namespace TripBuilder\Api;

use MysqliDb;
use TripBuilder\Database\Connection;
use TripBuilder\Database\MySql;
use TripBuilder\Database\Table;
use TripBuilder\Helper;
use TripBuilder\Routes;

abstract class AbstractApi
{
    private const HEADER_AUTH_KEY = 'Authorization';

    private const EXCLUDE_AUTH_CHECK_ENDPOINTS = [
        '/api/airports/autofill',
    ];

    private const RAW_RESPONSE_ENDPOINTS = [
        '/api/airports/autofill',
    ];

    protected const DB_TABLE_AIRLINES = Table::Airlines->value;
    protected const DB_TABLE_AIRPORTS = Table::Airports->value;
    protected const DB_TABLE_BOOKINGS = Table::Bookings->value;
    protected const DB_TABLE_COUNTRIES = Table::Countries->value;
    protected const DB_TABLE_FLIGHTS = Table::Flights->value;

    protected MysqliDb $db;
    protected array $data = [];
    private HttpMethod $allowedMethod;

    // PDO connection for endpoints migrated off the legacy MysqliDb query builder.
    // Lazily created so only migrated endpoints open it during the transition.
    private ?Connection $connection = null;

    public function __construct(?HttpMethod $method = null)
    {
        // API endpoints only need a database handle — not the page layout,
        // git shell-outs, or CDN wiring that AbstractController pulls in.
        $this->db = MySql::connect();
        $this->db->setTrace(true);

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

    public function setAllowedMethod(HttpMethod $method): void
    {
        $this->allowedMethod = $method;
    }

    private function getAllowedMethod(): HttpMethod
    {
        return $this->allowedMethod;
    }
}
