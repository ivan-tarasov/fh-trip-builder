<?php

namespace TripBuilder;

use TripBuilder\Debug\dBug;

class AmazonS3
{
    /**
     * @param string|null $url
     * @return string
     */
    public static function getUrl(?string $url = null): string
    {
        return sprintf(
            '//%s%s',
            $_ENV['AWS_CLOUDFRONT'],
            !empty($url)
                ? '/' . $url
                : null
        );
    }

}
