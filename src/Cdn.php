<?php

namespace TripBuilder;

class Cdn
{
    public static function getUrl(?string $url = null): string
    {
        return sprintf(
            '//%s%s',
            $_ENV['AWS_CLOUDFRONT'] ?? '',
            !empty($url)
                ? '/' . ltrim($url, '/')
                : ''
        );
    }
}
