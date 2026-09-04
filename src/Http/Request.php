<?php

declare(strict_types=1);

namespace TripBuilder\Http;

/**
 * The incoming request, captured once.
 *
 * `capture()` is the only place in the application that reads a superglobal.
 * Everything else receives this object, which means a controller's inputs are
 * visible in its signature and a test builds one from arrays.
 *
 * Deliberately not reachable statically. A globally available request would be
 * a global variable with extra steps: it hides what each class depends on, and
 * it makes tests depend on process state. Config already demonstrates the cost
 * of that shape here -- it is a static singleton constructed only by index.php,
 * so tests silently receive the in-code defaults rather than the config files,
 * which is subtle enough to have made a test pass that should have failed.
 */
final readonly class Request
{
    private const string FRAGMENT_KEY = 'fragment';
    private const string FRAGMENT_VALUE = '1';

    public function __construct(
        public Input $query,
        public Input $body,
        public Input $cookies,
        private string $method = 'GET',
        private string $uri = '/',
        private bool $secure = false,
        private string $rawBody = '',
        /** @var array<string, string> */
        private array $headers = [],
    ) {}

    /**
     * Read the real request. Called once, by the front controller.
     */
    public static function capture(): self
    {
        return new self(
            query: new Input($_GET),
            body: new Input($_POST),
            cookies: new Input($_COOKIE),
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            uri: (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            secure: !empty($_SERVER['HTTPS']),
            // Read here rather than where it is parsed, so the API endpoints
            // take their JSON body from the same object as everything else.
            // Empty for a form post, which PHP has already parsed into $_POST.
            rawBody: (string) file_get_contents('php://input'),
            headers: self::headersFromServer($_SERVER),
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * The path, without the query string and without a trailing slash -- `/`
     * for the root. This is what the router matches and what a form posts back
     * to, so both read the same value.
     */
    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);

        return rtrim(is_string($path) ? $path : '/', '/') ?: '/';
    }

    /**
     * Whether this asks for a piece of a page rather than a page.
     *
     * The search list's "load more" is one: its output is appended to a
     * document that already exists, so it must not be wrapped in a second
     * header and footer. The front controller and the search controller both
     * need the answer, and before this they each decided it for themselves.
     */
    public function isFragment(): bool
    {
        return $this->query->is(self::FRAGMENT_KEY, self::FRAGMENT_VALUE);
    }

    /**
     * The raw request body, for a payload PHP does not parse into $_POST --
     * a JSON API call. What to make of it is the caller's business.
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * A header by its ordinary name: `header('X-CSRF-Token')`, not
     * `$_SERVER['HTTP_X_CSRF_TOKEN']`.
     */
    public function header(string $name): ?string
    {
        return $this->headers[self::normaliseHeader($name)] ?? null;
    }

    /**
     * `HTTP_X_CSRF_TOKEN` back to `x-csrf-token`, plus the two headers PHP
     * reports without the prefix.
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[self::normaliseHeader(substr($key, 5))] = (string) $value;
                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[self::normaliseHeader($key)] = (string) $value;
            }
        }

        return $headers;
    }

    private static function normaliseHeader(string $name): string
    {
        return strtolower(str_replace('_', '-', $name));
    }
}
