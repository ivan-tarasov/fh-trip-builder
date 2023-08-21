<?php

namespace TripBuilder\Api;

use TripBuilder\Controllers\AbstractController;
use TripBuilder\Debug\dBug;
use TripBuilder\Helper;
use TripBuilder\Routs;

class AbstractApi extends AbstractController
{
    const HEADER_AUTH_KEY = 'Authorization';

    const REQUEST_METHOD_GET     = 'GET',
          REQUEST_METHOD_POST    = 'POST',
          REQUEST_METHOD_PUT     = 'PUT',
          REQUEST_METHOD_PATCH   = 'PATCH',
          REQUEST_METHOD_DELETE  = 'DELETE',
          REQUEST_METHOD_HEAD    = 'HEAD',
          REQUEST_METHOD_OPTIONS = 'OPTIONS';

    const EXCLUDE_AUTH_CHECK_ENDPOINTS = [
        '/api/airports/autofill',
    ];

    const RAW_RESPONSE_ENDPOINTS = [
        '/api/airports/autofill',
    ];

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

    protected array $data = [];

    private string $allowedMethod;

    public function __construct($method = false)
    {
        parent::__construct();

        // By default we accept only POST request method if not provided another one
        $this->setAllowedMethod($method ?: self::REQUEST_METHOD_POST);

        $this->guardUnauthorizedAccess();
        $this->guardNotAllowedRequestMethod();

        $this->setRequestData();
    }

    /**
     * @return void
     */
    private function guardUnauthorizedAccess(): void
    {
        if (! in_array(Routs::getCurrentPage(), self::EXCLUDE_AUTH_CHECK_ENDPOINTS) &&
            ! in_array($this->getAuthToken(), $this->authorizedTokens)
        ) {
            HttpException::unauthorizedAccess();
        }
    }

    /**
     * @return void
     */
    private function guardNotAllowedRequestMethod(): void
    {
        if ($this->getRequestMethod() !== $this->getAllowedMethod()) {
            HttpException::methodNotAllowed([$this->getAllowedMethod()]);
        }
    }

    /**
     * @param int   $statusCode
     * @param array $data
     * @param array $headers
     * @return void
     */
    public function sendResponse(int $statusCode, array $data = [], array $headers = []): void
    {
        // Sending response code
        http_response_code($statusCode);

        // Cleaning the output
        ob_clean();
        header_remove();

        // For some endpoints we not using typical output and return raw data
        if (! in_array(Routs::getCurrentPage(), self::RAW_RESPONSE_ENDPOINTS)) {
            // Building response array
            $response = [
                'status'    => $statusCode,
                'endpoint'  => Helper::getUrlPath(),
                'method'    => $this->getRequestMethod(),
                'timestamp' => date('Y-m-d H:i:s'),
                'data'      => $data ?? [],
            ];

            $response = json_encode($response);
        } else {
            // Handle data
            // $data = str_replace("\n", '', $data);

            $response = json_encode($data);
        }

        // Setting up response headers
        self::addHeader('Content-type', 'application/json; charset=utf-8');
        self::addHeader('Access-Control-Max-Age', 3600);
        self::addHeader('Content-Length', strlen($response));

        if (!empty($headers)) {
            // array_walk($headers, [$this, 'addHeader']);
            array_map([$this, 'addHeader'], array_keys($headers), $headers);
        }

        echo $response;
    }

    /**
     * @param string     $key
     * @param string|int $value
     * @return void
     */
    private static function addHeader(string $key, string|int $value): void
    {
        header(sprintf('%s: %s', $key, $value));
    }

    /**
     * @return string
     */
    private function getRequestMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? '';
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

    /**
     * @param $headers
     * @return void
     */
    private function setHeaders($headers): void
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

    /**
     * @param $method
     * @return void
     */
    public function setAllowedMethod($method): void
    {
        $this->allowedMethod = $method;
    }

    /**
     * @return string
     */
    private function getAllowedMethod(): string
    {
        return $this->allowedMethod;
    }

}
