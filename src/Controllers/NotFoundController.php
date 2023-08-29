<?php

namespace TripBuilder\Controllers;

use TripBuilder\Config;
use TripBuilder\Templater;

class NotFoundController
{
    public function index()
    {
        $templater = new Templater('error', '404-not-found');

        echo $templater
            ->setPlaceholder('app_css_folder', sprintf('%s/%s', Config::get('site.static.url'), Config::get('site.static.endpoint.css')))
            ->save()
            ->render();
    }
}
