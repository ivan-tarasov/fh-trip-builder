<?php

namespace TripBuilder\Controllers;

use TripBuilder\AmazonS3;
use TripBuilder\DataBase\MySql;
use TripBuilder\Debug\dBug;
use TripBuilder\Config;
use TripBuilder\Routs;
use TripBuilder\Templater;

class HomeController extends AbstractController
{
    /**
     * @return void
     * @throws \Exception
     */
    public function index(): void
    {
        $templater = new Templater();

        $bg_image_id = rand(1,10);
        $bg_image_url = sprintf(
            '%s/%s/background/%s.jpg',
            Config::get('site.static.url'),
            Config::get('site.static.endpoint.images'),
            $bg_image_id
        );

        // Render POI
        $poi = Config::get('site.poi');

        shuffle($poi);

        foreach (array_rand($poi, 3) as $id) {
            $templater
                ->setPath('index')
                ->setFilename('poi')
                ->set()
                ->setPlaceholder('poi_image_url', sprintf(
                    '%s/%s/%s',
                    Config::get('site.static.url'),
                    Config::get('site.static.endpoint.poi'),
                    $poi[$id]['image']
                ))
                ->setPlaceholder('poi_title', $poi[$id]['title'])
                ->setPlaceholder('poi_city', $poi[$id]['city'])
                ->setPlaceholder('poi_country', $poi[$id]['country'])
                ->save();
        }

        $poi_cards = $templater->render();

        // Render top search list
        $this->db->orderBy('search_count');

        foreach ($this->db->get(MySql::TABLE_SEARCH, 5) as $rank => $search) {
            $templater
                ->setPath('index')
                ->setFilename('top-searches')
                ->set()
                ->setPlaceholder('search_url',   '/search/?hash=' . $search['hash'])
                ->setPlaceholder('search_rank',  $rank + 1)
                ->setPlaceholder('flight_direction', $search['triptype'] == 'roundtrip' ? 'arrow-left' : 'long')
                ->setPlaceholder('from_name',    $search['from_name'])
                ->setPlaceholder('to_name',      $search['to_name'])
                ->setPlaceholder('depart_date',  date('D, F j', strtotime($search['depart'])))
                ->setPlaceholder('return_date',  $search['return'] ? ' – ' . date('D, F j', strtotime($search['depart'])) : null)
                ->setPlaceholder('search_count', number_format($search['search_count']))
                ->save();
        }

        $top_searches = $templater->render();

        // Render top airlines
        $this->db->orderBy('book_count');

        foreach ($this->db->get(MySql::TABLE_AIRLINES, 7) as $airline) {
            $templater
                ->setPath('index')
                ->setFilename('top-airline')
                ->set()
                ->setPlaceholder('airline_title',    $airline['title'])
                ->setPlaceholder('book_count',       $airline['book_count'])
                ->setPlaceholder('airline_logo_url', AmazonS3::getUrl(sprintf(
                    '%s/%s/%s.png',
                    Config::get('site.static.endpoint.images'),
                    'suppliers',
                    $airline['code']
                )))
                ->save();
        }

        $top_airlines = $templater->render();

        // Render index page
        echo $templater
            ->setPath('index')
            ->setFilename('view')
            ->set()
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
            ->setPlaceholder('poi_cards',                $poi_cards)
            ->setPlaceholder('top_searches',             $top_searches)
            ->setPlaceholder('top_airlines',             $top_airlines)
            ->save()->render();
    }

}
