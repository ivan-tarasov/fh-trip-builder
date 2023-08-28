<?php

namespace TripBuilder;

use TripBuilder\Debug\dBug;

class AmazonS3
{
    public static function getUrl(?string $url): string
    {
        return sprintf(
            '//%s.s3-website.%s.amazonaws.com/%s',
            $_ENV['AWS_BUCKET'],
            $_ENV['AWS_REGION'],
            $url
        );
    }

}
