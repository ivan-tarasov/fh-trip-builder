<?php

namespace TripBuilder;

use TripBuilder\Debug\dBug;

class AmazonS3
{
    const PROTOCOL_HTTP  = 'http://',
          PROTOCOL_HTTPS = 'https://',
          PROTOCOL_VOID  = '//';


    public static function getUrl(?string $url = null): string
    {
        return sprintf(
            '%s%s.s3-website.%s.amazonaws.com%s',
            self::PROTOCOL_HTTP,
            $_ENV['AWS_BUCKET'],
            $_ENV['AWS_REGION'],
            !empty($url)
                ? '/' . $url
                : null
        );
    }

}
