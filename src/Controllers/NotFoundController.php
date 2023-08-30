<?php

namespace TripBuilder\Controllers;

use TripBuilder\AmazonS3;
use TripBuilder\Config;
use TripBuilder\Templater;

class NotFoundController
{
    public function index()
    {
        $templater = new Templater('error', '404-not-found');

        echo $templater
            ->setPlaceholder('app_css_folder', sprintf('%s/%s', AmazonS3::getUrl(), Config::get('site.static.endpoint.css')))
            ->save()
            ->render();
    }
}
