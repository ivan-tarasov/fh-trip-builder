<?php

namespace TripBuilder\Controllers;

use TripBuilder\Debug\dBug;
use TripBuilder\Config;
use TripBuilder\Routs;
use TripBuilder\Templater;

class HomeController
{
    public function index()
    {
        $templater = new Templater('index', 'view');

        echo $templater
            ->setPlaceholder('PAGE_TITLE', 'Home')
            ->setPlaceholder('MAIN_BG_IMAGE', rand(1,10))
            ->setPlaceholder('API_PATH_AIRPORTS', Config::get('FlightAPI', 'url') . '/airports/autofill')
            ->save()->render();
    }

}
