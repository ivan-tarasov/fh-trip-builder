<?php

namespace TripBuilder\Controllers;

use ipinfo\ipinfo\IPinfoException;
use TripBuilder\Debug\dBug;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Routs;
use TripBuilder\AmazonS3;
use TripBuilder\Templater;
use ipinfo\ipinfo\IPinfo;


class DebugController
{
    /**
     * @return void
     * @throws IPinfoException
     */
    public function index(): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] == '127.0.0.1'
                ? sprintf('%d.%d.%d.%d', rand(1, 254), rand(1, 254), rand(1, 254), rand(1, 254))
                : $_SERVER['REMOTE_ADDR'];
            $ip = '8.29.230.186';

            $client = new IPinfo($_ENV['IPINFO_TOKEN'], [
                'cache_disabled' => true
            ]);
            $details = $client->getDetails($ip);

            new dBug([
                $ip,
                $_ENV['IPINFO_TOKEN'],
                $details,
                //$_SERVER
            ]);
        } catch (\Exception $e) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
        }
    }

}
