<?php

namespace TripBuilder\Api;

class AbstractApi
{
    const HEADER_AUTH_KEY = 'Authorization';

    const HEADER_ALLOWED_METHODS = ['POST','GET'];

    /**
     * Minimum security at this time...
     *
     * @var array $authorizedTokens
     */
    private array $authorizedTokens = [
        'SomeAPItoken_$ecretWORD---orHASH',
        'AnotherAPIt0ken-$ecretHash',
        'And@nothEr_Auth0riz@tionKey',
    ];

    private array $headers = [];

    public function __construct()
    {
        $this->guardUnauthorizedAccess();
        $this->guardNotAllowedRequestMethod();

        $this->sendResponse(200, [$_SERVER['REQUEST_METHOD']]);
    }

    /**
     * @return string
     */
    private function getAuthToken(): string
    {
        return preg_match('/Bearer\s+(\S+)\b/i', getallheaders()[self::HEADER_AUTH_KEY] ?? '', $matches)
            ? $matches[1]
            : '';
    }

    /**
     * @return void
     */
    private function guardUnauthorizedAccess(): void
    {
        if (! in_array($this->getAuthToken(), $this->authorizedTokens)) {
            HttpException::unauthorizedAccess();
        }
    }

    /**
     * @return void
     */
    private function guardNotAllowedRequestMethod(): void
    {
        if (! in_array($this->getRequestMethod(), self::HEADER_ALLOWED_METHODS)) {
            HttpException::methodNotAllowed(self::HEADER_ALLOWED_METHODS);
        }
    }

    /**
     * @return string
     */
    private function getRequestMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? '';
    }

    /**
     * @param $statusCode
     * @param $data
     * @param $headers
     * @return void
     */
    public function sendResponse($statusCode, $data = null, $headers = null)
    {
        $response = json_encode([
            'status' => $statusCode,
            'data'   => $data ?? [],
        ]);

        // Sending response code
        http_response_code($statusCode);

        // Cleaning the output
        ob_clean();
        header_remove();

        // Setting up response headers
        self::addHeader('Content-type', 'application/json; charset=utf-8');
        // self::addHeader('Access-Control-Allow-Methods', 'OPTIONS,GET,POST,PUT,DELETE');
        // self::addHeader('Access-Control-Max-Age', 3600);
        // self::addHeader('Access-Control-Allow-Headers', 'Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
        self::addHeader('Content-Length', strlen($response));

        if ($headers) {
            // array_walk($headers, [$this, 'addHeader']);
            array_map([$this, 'addHeader'], array_keys($headers), $headers);
        }

        echo $response;

        die();
    }

    /**
     * @param string     $key
     * @param string|int $value
     * @return void
     */
    private static function addHeader(string $key, string|int $value)
    {
        header(sprintf('%s: %s', $key, $value));
    }

    private function getRequestHeaders()
    {
        $this->setHeaders(getallheaders() ?? []);
    }

    private function setHeaders($headers)
    {
        $this->headers = $headers;
    }

    /**
     * @return array
     */
    private function getHeaders(): array
    {
        return $this->headers;
    }

}
