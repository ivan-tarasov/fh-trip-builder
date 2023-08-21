<?php

namespace TripBuilder\Controllers;

use TripBuilder\Debug\dBug;
use TripBuilder\Config;
use TripBuilder\Routs;
use TripBuilder\Templater;

class HomeController
{
    /**
     * @return void
     */
    public function index(): void
    {
        $templater = new Templater('index', 'view');

        echo $templater
            ->setPlaceholder('index-background-image', rand(1,10))
            ->setPlaceholder('search-page-url', '/search/')
            ->setPlaceholder('api-airports-autofill', Config::get('api.fake.url') . '/airports/autofill/?query=')
            ->setPlaceholder('form-input-triptype', Config::get('search.form.input.triptype'))
            ->setPlaceholder('form-input-triptype-roundtrip', Config::get('search.triptype.roundtrip'))
            ->setPlaceholder('form-input-triptype-oneway', Config::get('search.triptype.oneway'))
            ->setPlaceholder('form-input-from', Config::get('search.form.input.depart_place'))
            ->setPlaceholder('form-input-to', Config::get('search.form.input.arrive_place'))
            ->setPlaceholder('form-input-from-date', Config::get('search.form.input.depart_date'))
            ->setPlaceholder('form-input-to-date', Config::get('search.form.input.return_date'))
            ->save()->render();
    }

}
