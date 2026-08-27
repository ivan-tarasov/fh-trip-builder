<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use TripBuilder\Cdn;
use TripBuilder\Config;
use TripBuilder\Templater;

class NotFoundController
{
    public function index(): void
    {
        $templater = new Templater('error', '404-not-found');

        echo $templater
            ->setPlaceholder('app_css_folder', sprintf('%s/%s', Cdn::getUrl(), Config::get('site.static.endpoint.css')))
            ->save()
            ->render();
    }
}
