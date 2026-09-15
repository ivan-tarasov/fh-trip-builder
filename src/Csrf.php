<?php

declare(strict_types=1);

namespace TripBuilder;

class Csrf
{
    private const string SESSION_KEY = 'csrf_token';
    public const string HEADER = 'X-CSRF-Token';

    // The same token, for a form that posts rather than fetches.
    public const string FIELD = '_csrf';

    /**
     * Return the current session CSRF token, creating one if needed.
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        /** @var string $token */
        $token = $_SESSION[self::SESSION_KEY];

        return $token;
    }

    /**
     * Constant-time comparison of a submitted token against the session token.
     */
    public static function isValid(?string $token): bool
    {
        if ($token === null || empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        /** @var string $stored */
        $stored = $_SESSION[self::SESSION_KEY];

        return hash_equals($stored, $token);
    }
}
