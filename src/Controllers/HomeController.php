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
            ->setPlaceholder('index-background-image', rand(1,10))
            ->setPlaceholder('search-page-url', '/search/')
            ->setPlaceholder('api-airports-autofill', Config::get('api.fake.url') . '/airports/autofill')
            ->save()->render();
    }

    public function test($params)
    {
        new dBug($params);
    }

}
