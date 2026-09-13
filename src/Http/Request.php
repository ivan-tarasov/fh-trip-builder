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
        private string $remoteAddress = '',
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
            headers: self::captureHeaders(),
            remoteAddress: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
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
     * Who is asking, as well as it can be known.
     *
     * `REMOTE_ADDR` is the edge and not the visitor. Every request to this app
     * arrives from Cloudflare, so a rate limit keyed on it would hold the whole
     * internet to one allowance and the first script to find the subscribe form
     * would lock everybody else out of it. `CF-Connecting-IP` is the address
     * the edge saw, and is what the counting has to key on.
     *
     * Validated rather than trusted. On a request that reaches the origin
     * directly the header is just something the client typed, so it is used
     * only when it parses as an address -- which keeps a forged value out of a
     * database key, though not out of the counting. Refusing traffic that did
     * not come from the edge is the fix for that, and is not this.
     */
    public function clientIp(): string
    {
        $stated = $this->header('cf-connecting-ip');

        if ($stated !== null && filter_var($stated, FILTER_VALIDATE_IP) !== false) {
            return $stated;
        }

        return $this->remoteAddress;
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
     * Every header this request arrived with.
     *
     * `$_SERVER` first, and `getallheaders()` only for what is missing from
     * it. `Authorization` is the reason both are read: Apache does not put it
     * in `$_SERVER` when a request arrives through a front-controller rewrite
     * unless it is told to, and `getallheaders()` reads the SAPI's own table,
     * which still has it. Added to rather than swapped for, because
     * `getallheaders()` does not exist on CLI at all -- so a server keeps
     * exactly what it had, and a test gets an object it can build.
     *
     * @return array<string, string>
     */
    private static function captureHeaders(): array
    {
        $headers = self::headersFromServer($_SERVER);

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[self::normaliseHeader((string) $name)] ??= (string) $value;
            }
        }

        return $headers;
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
        $redirected = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            // An internal redirect -- which the front-controller rewrite is --
            // copies everything under a REDIRECT_ prefix. It is the same
            // header, and for `Authorization` it is the only spelling that
            // survives the rewrite that puts it there (E28, #207).
            //
            // Stripped in a loop, not once: two rewrites means two prefixes,
            // and this deployment already produces both
            // REDIRECT_HTTP_AUTHORIZATION and
            // REDIRECT_REDIRECT_HTTP_AUTHORIZATION. A third rule anywhere would
            // add a third.
            $name = $key;

            while (str_starts_with($name, 'REDIRECT_')) {
                $name = substr($name, 9);
            }

            $prefixed = $name !== $key;

            $header = match (true) {
                str_starts_with($name, 'HTTP_') => self::normaliseHeader(substr($name, 5)),
                $name === 'CONTENT_TYPE', $name === 'CONTENT_LENGTH' => self::normaliseHeader($name),
                default => null,
            };

            if ($header === null) {
                continue;
            }

            if ($prefixed) {
                $redirected[$header] = (string) $value;
            } else {
                $headers[$header] = (string) $value;
            }
        }

        // The unprefixed spelling wins where both are present, whatever order
        // they came in.
        return $headers + $redirected;
    }

    private static function normaliseHeader(string $name): string
    {
        return strtolower(str_replace('_', '-', $name));
    }
}
