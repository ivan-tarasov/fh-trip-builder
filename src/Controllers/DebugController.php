<?php

namespace TripBuilder\Controllers;

use TripBuilder\Debug\dBug;
use TripBuilder\Config;
use TripBuilder\Helper;
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
        new dBug(Helper::getGitInfo());
    }

}
