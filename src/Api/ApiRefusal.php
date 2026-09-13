<?php

declare(strict_types=1);

namespace TripBuilder\Api;

use Exception;
use TripBuilder\Http\HttpStatus;

/**
 * An endpoint refusing a request.
 *
 * Thrown where `die()` used to be called (E10.5, #198). `die()` is a fine
 * answer for a web request and a bad one for everything else: it skips
 * `Log::finish()`, the shutdown function that writes this application's one
 * line per request, so an unauthorised or malformed API call was the request
 * least likely to be logged. And it ends a test *run* rather than a test, which
 * is why `ApiController` was the one controller E10.2 (#149) could not ask for
 * a page.
 *
 * Carries what the answer needs rather than writing it: the status, the
 * message, and the headers this particular refusal adds -- only
 * `methodNotAllowed` has any, and `Allow` is meaningless on the others.
 */
final class ApiRefusal extends Exception
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly HttpStatus $status,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
