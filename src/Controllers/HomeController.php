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

        $bg_image_id = rand(1,10);
        $bg_image_url = sprintf(
            '%s/%s/background/%s.jpg',
            Config::get('site.static.url'),
            Config::get('site.static.endpoint.images'),
            $bg_image_id
        );

        echo $templater
            ->setPlaceholder('bg_image_id',              $bg_image_id)
            ->setPlaceholder('bg_image_url',             $bg_image_url)
            ->setPlaceholder('form_action',              '/search/')
            ->setPlaceholder('api_airports_autofill',    Config::get('api.fake.url') . '/airports/autofill/?query=')
            ->setPlaceholder('input_triptype',           Config::get('search.form.input.triptype'))
            ->setPlaceholder('input_triptype_roundtrip', Config::get('search.triptype.roundtrip'))
            ->setPlaceholder('input_triptype_oneway',    Config::get('search.triptype.oneway'))
            ->setPlaceholder('input_from',               Config::get('search.form.input.depart_place'))
            ->setPlaceholder('input_to',                 Config::get('search.form.input.arrive_place'))
            ->setPlaceholder('input_from_date',          Config::get('search.form.input.depart_date'))
            ->setPlaceholder('input_to_date',            Config::get('search.form.input.return_date'))
            ->save()->render();
    }

}
