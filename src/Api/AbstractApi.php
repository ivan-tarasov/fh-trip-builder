<?php

declare(strict_types=1);

namespace TripBuilder\Api;

use TripBuilder\Database\Connection;
use TripBuilder\Http\Request;

abstract class AbstractApi
{
    private const string HEADER_AUTH_KEY = 'Authorization';

    // Parsed request payload: readable by the endpoint subclasses, but only
    // this base class may populate it (from setRequestData()).
    protected private(set) array $data = [];
    private HttpMethod $allowedMethod;

    private ?Connection $connection = null;

    public function __construct(
        protected readonly Request $request,
        ?HttpMethod $method = null,
    ) {
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
        // Every endpoint, with no exceptions list. The airport autofill was the
        // only entry it ever held, and the search form no longer asks the server
        // for places at all -- it ships them with the page.
        if (!$this->isAuthorizedToken($this->getAuthToken())) {
            ApiResponder::unauthorizedAccess();
        }
    }

    private function isAuthorizedToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return array_any(
            explode(',', $_ENV['API_ACCEPTED_TOKENS'] ?? ''),
            static fn(string $authorized): bool => $authorized !== '' && hash_equals($authorized, $token),
        );
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

        // Every endpoint, in the same envelope. One used to be exempt -- the
        // airport autofill, which returned a bare array of pre-rendered HTML --
        // and the search form no longer asks the server for places at all.
        $response = (string) json_encode([
            'status' => $status->value,
            'endpoint' => $this->request->path(),
            'method' => $this->getRequestMethod(),
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data,
        ]);

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
        return $this->request->method();
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
        // The body comes from the request object like everything else; what to
        // make of a malformed one is this class's business, not its.
        $data = $this->request->rawBody();

        if ($data === '') {
            return;
        }

        $decoded = json_decode($data, true);

        if (!is_array($decoded)) {
            ApiResponder::badRequest('Malformed JSON body');
        }

        $this->data = $decoded;
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
