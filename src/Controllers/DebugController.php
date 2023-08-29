<?php

namespace TripBuilder\Controllers;

use TripBuilder\Debug\dBug;
use TripBuilder\Config;
use TripBuilder\Routs;
use TripBuilder\AmazonS3;
use TripBuilder\Templater;

class DebugController
{
    /**
     * @return void
     */
    public function index(): void
    {
        echo AmazonS3::getUrl('images/suppliers/AC.png');
    }

}
