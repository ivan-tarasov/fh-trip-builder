<?php

namespace TripBuilder;

class Csrf
{
    private const SESSION_KEY = 'csrf_token';
    public const HEADER = 'X-CSRF-Token';

    /**
     * Return the current session CSRF token, creating one if needed.
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Constant-time comparison of a submitted token against the session token.
     */
    public static function isValid(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION[self::SESSION_KEY])
            && hash_equals($_SESSION[self::SESSION_KEY], $token);
    }
}
