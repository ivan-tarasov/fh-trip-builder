<?php

namespace TripBuilder\Controllers;

use GuzzleHttp\Exception\GuzzleException;
use TripBuilder\ApiClient\Api;
use TripBuilder\ApiClient\Credentials;
use TripBuilder\Debug\dBug;
use TripBuilder\Config;
use TripBuilder\Routs;
use TripBuilder\Templater;

class SearchController
{
    /**
     * @return void
     * @throws GuzzleException
     */
    public function index(): void
    {
        try {
            // If one of important params is empty or not provided – redirect to index page
            if (empty($_GET[Config::get('search.form.input.triptype')])
                || empty($_GET[Config::get('search.form.input.depart_place')])
                || empty($_GET[Config::get('search.form.input.arrive_place')])
                || empty($_GET[Config::get('search.form.input.depart_date')])
            ) {
                // TODO: uncomment after all will be done
                // echo '<script>window.location.replace("/");</script>';
            }

            // $activetab[$_GET[Config::get('search.form.input.triptype')]] = Config::get('site.tab_active');

            // new dBug([
            //     $activetab,
            // ]);

            $apiClient = new Api(Config::get('api.fake.url'));

            $headers = [
                'Authorization' => Credentials::getBearer(),
                'Accept'        => 'application/json',
            ];

            $data = [
                'trip_type'   => $_GET[Config::get('search.form.input.triptype')] == Config::get('search.triptype.roundtrip')
                    ? 'roundtrip'
                    : 'oneway',
                'from'        => $_GET[Config::get('search.form.input.depart_place')],
                'to'          => $_GET[Config::get('search.form.input.arrive_place')],
                'depart_date' => $_GET[Config::get('search.form.input.depart_date')],
                'return_date' => $_GET[Config::get('search.form.input.return_date')] ?: '',
                'adult_count' => 1, // FIXME: now we provide only 1 adult count
                'child_count' => 0, // FIXME: now we provide only 1 child count
            ];

            $getResponse = $apiClient->post('flights', $headers, $data);

            new dBug($getResponse);
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }

}
