<?php

namespace TripBuilder\Controllers;

use TripBuilder\Debug\dBug;
use TripBuilder\Config;

class HomeController
{
    public function index()
    {
        new dBug(Config::get('site', 'pagination'));
    }
}