<?php

declare(strict_types=1);

namespace TripBuilder;

class Cdn
{
    /**
     * Whether there is a distribution to point at.
     *
     * `getUrl()` builds `//` + the host + the path, so with no host it returns
     * `///images/...` -- a protocol-relative URL to an empty authority, which a
     * browser reads as a path on the current host and which therefore fails
     * quietly rather than loudly. Callers that have a local copy to fall back
     * on should ask this first.
     */
    public static function isConfigured(): bool
    {
        return ($_ENV['AWS_CLOUDFRONT'] ?? '') !== '';
    }

    public static function getUrl(?string $url = null): string
    {
        return sprintf(
            '//%s%s',
            $_ENV['AWS_CLOUDFRONT'] ?? '',
            !empty($url)
                ? '/' . ltrim($url, '/')
                : '',
        );
    }
}
