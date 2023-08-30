<?php

namespace TripBuilder\Controllers;

use GuzzleHttp\Exception\GuzzleException;
use TripBuilder\AmazonS3;
use TripBuilder\ApiClient\Api;
use TripBuilder\ApiClient\Credentials;
use TripBuilder\Debug\dBug;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Templater;

class SearchController
{
    const GET_FROM        = 'from',
          GET_TO          = 'to',
          GET_DEPART      = 'depart',
          GET_RETURN      = 'return',
          GET_TRIPTYPE    = 'triptype',
          GET_CLASS       = 'class',
          GET_PAGE        = 'page';

    const POST_SORT       = 'sort',
          POST_TIME_RANGE = 'time_range',
          POST_AIRLINES   = 'airlines';

    private array $get;

    private array $post;

    private Templater $templater;

    private $data;

    /**
     * @return void
     * @throws GuzzleException
     */
    public function index(): void
    {
        try {
            // Handle GET data
            $this->setGet([
                self::GET_FROM     => $_GET[Config::get('search.form.input.depart_place')] ?? null,
                self::GET_TO       => $_GET[Config::get('search.form.input.arrive_place')] ?? null,
                self::GET_DEPART   => $_GET[Config::get('search.form.input.depart_date')]  ?? null,
                self::GET_RETURN   => $_GET[Config::get('search.form.input.return_date')]  ?? null,
                self::GET_TRIPTYPE => $_GET[Config::get('search.form.input.triptype')]     ?? null,
                self::GET_CLASS    => $_GET[Config::get('search.form.input.class')]        ?? null,
                self::GET_PAGE     => $_GET[Config::get('search.form.input.page')]         ?? 1,
            ]);

            // Handle POST data
            $this->setPost([
                self::POST_SORT       => $_POST[self::POST_SORT]       ?? false,
                self::POST_TIME_RANGE => $_POST[self::POST_TIME_RANGE] ?? false,
                self::POST_AIRLINES   => $_POST[self::POST_AIRLINES]   ?? false,
            ]);

            // Handle SESSION data
            if ($this->post[self::POST_SORT]) {
                $_SESSION[self::POST_SORT] = $this->post[self::POST_SORT];
            } elseif (!isset($_SESSION[self::POST_SORT]) || !$this->get[self::GET_PAGE]) {
                $_SESSION[self::POST_SORT] = 'price';
            }

            // If one of important params is empty or not provided – redirect to index page
            if (empty($this->get[self::GET_TRIPTYPE])
                || empty($this->get[self::GET_FROM])
                || empty($this->get[self::GET_TO])
                || empty($this->get[self::GET_DEPART])
            ) {
                echo '<script>window.location.replace("/");</script>';
            }


            $activetab[$this->get[self::GET_TRIPTYPE]] = Config::get('site.tab_active');

            $apiClient = new Api(Config::get('api.fake.url'));

            $headers = [
                'Authorization' => Credentials::getBearer(),
                'Accept'        => 'application/json',
            ];

            $data = [
                'page'        => $this->get[self::GET_PAGE] ?? 1,
                'sort'        => 'price',
                'trip_type'   => $this->get[self::GET_TRIPTYPE] == Config::get('search.triptype.roundtrip')
                    ? 'roundtrip'
                    : 'oneway',
                'from'        => $this->get[self::GET_FROM],
                'to'          => $this->get[self::GET_TO],
                'depart_date' => $this->get[self::GET_DEPART],
                'return_date' => $this->get[self::GET_RETURN],
                'adult_count' => 1, // FIXME: now we provide only 1 adult count
                'child_count' => 0, // FIXME: now we provide only 0 child count
            ];

            $flights_response = $apiClient->post('flights', $headers, $data);

            $this->setData($flights_response->data);

            $total_flights = $this->data->total_flights;

            $this->templater = new Templater();

            /*
            |--------------------------------------------------------------------------
            | Lead form
            |--------------------------------------------------------------------------
            |
            | Building page top search form
            |
            */

            echo $this->templater
                ->setPath('search')
                ->setFilename('search-form-up')
                ->set()
                ->setPlaceholder('search_page_url',          '/search/')
                ->setPlaceholder('airports_autofill',        Config::get('api.fake.url') . '/airports/autofill/?query=')
                ->setPlaceholder('input_triptype',           Config::get('search.form.input.triptype'))
                ->setPlaceholder('input_triptype_roundtrip', Config::get('search.triptype.roundtrip'))
                ->setPlaceholder('input_triptype_oneway',    Config::get('search.triptype.oneway'))
                ->setPlaceholder('input_from',               Config::get('search.form.input.depart_place'))
                ->setPlaceholder('input_to',                 Config::get('search.form.input.arrive_place'))
                ->setPlaceholder('input_from_date',          Config::get('search.form.input.depart_date'))
                ->setPlaceholder('input_to_date',            Config::get('search.form.input.return_date'))
                ->setPlaceholder('depart_code',              $this->get[self::GET_FROM])
                ->setPlaceholder('arrive_code',              $this->get[self::GET_TO])
                ->setPlaceholder('depart_city',              $this->data->depart)
                ->setPlaceholder('arrive_city',              $this->data->arrive)
                ->setPlaceholder('depart_date',              $this->get[self::GET_DEPART])
                ->setPlaceholder('return_date',              $this->get[self::GET_RETURN])
                ->setPlaceholder('tab_rt_button',            $activetab[Config::get('search.triptype.roundtrip')]['btn']  ?? '')
                ->setPlaceholder('tab_rt_aria',              $activetab[Config::get('search.triptype.roundtrip')]['aria'] ?? '')
                ->setPlaceholder('tab_rt_div',               $activetab[Config::get('search.triptype.roundtrip')]['div']  ?? '')
                ->setPlaceholder('tab_ow_button',            $activetab[Config::get('search.triptype.oneway')]['btn']     ?? '')
                ->setPlaceholder('tab_ow_aria',              $activetab[Config::get('search.triptype.oneway')]['aria']    ?? '')
                ->setPlaceholder('tab_ow_div',               $activetab[Config::get('search.triptype.oneway')]['div']     ?? '')
                ->save()
                ->render();

            /*
            |--------------------------------------------------------------------------
            | Sidebar
            |--------------------------------------------------------------------------
            |
            | Building page sidebar
            |
            */

            // 1. Render button for filters
            $sb_update_button = $this->templater
                ->setPath('search/sidebar')
                ->setFilename('update-button')
                ->set()
                ->save()
                ->render();

            // 2. Building sort section
            $this->templater->setFilename('sort')->set();

            foreach (Config::get('search.sort') as $key => $params) {
                $this->templater
                    ->setPlaceholder('sb_sort_item_hide',    $params[array_key_first($activetab)] !== 1 ? ' d-none' : null)
                    ->setPlaceholder('sb_sort_item_id',      $params['id'])
                    ->setPlaceholder('sb_sort_item_value',   $key)
                    ->setPlaceholder('sb_sort_item_checked', $_SESSION[self::POST_SORT] == $key ? 'checked' : null)
                    ->setPlaceholder('sb_sort_item_title',   $params['title'])
                    ->setPlaceholder('sb_sort_item_note',    $params['note'])
                    ->save();
            }

            $sb_filter_sort = $this->templater
                ->setPlaceholder('form_url', sprintf(
                    '%s?%s',
                    Helper::getUrlPath(),
                    http_build_query(array_merge($this->get, [self::GET_PAGE => null]))
                ))
                ->render();

            // 3. Building time preferences filter
            $sb_filter_timerange = $this->templater
                ->setFilename('range')
                ->set()
                ->setPlaceholder('clock_range', $this->generateTimeRange())
                ->setPlaceholder('range_depart_from', '00:00')
                ->setPlaceholder('range_depart_to',   '23:59')
                ->setPlaceholder('range_return_from', '00:00')
                ->setPlaceholder('range_return_to',   '23:59')
                ->save()
                ->render();

            // 4. Building airlines filter with airlines from flights response
            $sb_filter_airlines = null;

            $airlines_request = array_unique(array_merge(...array_map(function ($item) {
                $values = [$item->outbound->carrier];

                if (isset($item->returning->carrier)) {
                    $values[] = $item->returning->carrier;
                }

                return $values;
            }, $this->data->flights ?? [])));

            if (! empty($airlines_request)) {
                $airlines_request = ['selected' => implode(',', $airlines_request)];

                $airlines_response = $apiClient->post('airlines', $headers, $airlines_request);

                if (!empty($airlines_response->data)) {
                    $this->templater
                        ->setPath('search/sidebar')
                        ->setFilename('airlines')
                        ->set();

                    foreach ($airlines_response->data as $airline) {
                        $this->templater
                            ->setPlaceholder('airline-iata-code', $airline->code)
                            ->setPlaceholder('airline-title', $airline->title)
                            ->setPlaceholder('airline-checked', 'checked')
                            ->save();
                    }

                    $sb_filter_airlines = $this->templater->render();
                }
            }

            // 5. Building and render sidebar
            $sidebar = $this->templater
                ->setPath('search/sidebar')
                ->setFilename('view')
                ->set()
                ->setPlaceholder('sb_update_button',    $sb_update_button)
                ->setPlaceholder('sb_filter_sort',      $sb_filter_sort)
                ->setPlaceholder('sb_filter_timerange', $sb_filter_timerange)
                ->setPlaceholder('sb_filter_airlines',  $sb_filter_airlines)
                ->save()
                ->render();

            /*
            |--------------------------------------------------------------------------
            | Flights
            |--------------------------------------------------------------------------
            |
            | Building flight cards and pagination bar. If flights not found in API
            | response - we show `No flights found` page
            |
            */

            if ($total_flights != 0) {
                $flight_cards   = $this->getFlightCards();
                $pagination_bar = $this->getPagination();

                $flights = $this->templater
                    ->setPath('search/cards')
                    ->setFilename('view')
                    ->set()
                    ->setPlaceholder('total_flights', Helper::plural($total_flights, 'flight', true))
                    ->setPlaceholder('flight_cards', $flight_cards)
                    ->setPlaceholder('pagination_bar', $pagination_bar)
                    ->save()
                    ->render();
            } else {
                $flights = $this->templater
                    ->setPath('search')
                    ->setFilename('no-result')
                    ->set()
                    ->setPlaceholder('not_found_img', AmazonS3::getUrl(sprintf(
                        '%s/%s',
                        Config::get('site.static.endpoint.images'),
                        'no-results.png'
                    )))
                    ->setPlaceholder('return_date', !empty($this->get[self::GET_RETURN]) ? ' to ' . $this->get[self::GET_RETURN] : null)
                    ->save()
                    ->render();
            }

            /*
            |--------------------------------------------------------------------------
            | Search page
            |--------------------------------------------------------------------------
            |
            | Combine together sidebar and flight cards with pagination bar to
            | building whole search page
            |
            */

            echo $this->templater->setPath('search')->setFilename('view')->set()
                ->setPlaceholder('sidebar-panel', $sidebar)
                ->setPlaceholder('flight-cards', $flights)
                ->save()
                ->render();
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }

    /**
     * Generating string for Time Range javascript
     *
     * @return string
     */
    private function generateTimeRange(): string
    {
        $startTime = new \DateTime('00:00');
        $endTime =   new \DateTime('23:59');
        $interval =  new \DateInterval('PT30M');

        $range = [];

        for ($time = clone $startTime; $time <= $endTime; $time->add($interval)) {
            $range[] = $time->format('H:i');
        }

        $range[] = '23:59';

        return "'" . implode("','", $range) . "'";
    }

    /**
     * @param $minutes
     * @return string
     */
    public function minutesToStringTime($minutes): string
    {
        $seconds = $minutes * 60;

        $dtF = new \DateTime('@0');
        $dtT = new \DateTime("@$seconds");

        $interval = $dtF->diff($dtT);

        $timeComponents = [
            'd' => $interval->format('%a'),
            'h' => $interval->format('%h'),
            'm' => $interval->format('%i'),
        ];

        $formattedTime = '';

        foreach ($timeComponents as $unit => $value) {
            if ($value != 0) {
                $formattedTime .= $value . $unit . ' ';
            }
        }

        return trim($formattedTime);
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function getFlightCards(): string
    {
        $package = '';

        foreach ($this->data->flights as $flight) {
            $carriers = [];

            foreach ([$flight->outbound, $flight->returning] as $ticket) {
                if (! $ticket) {
                    continue;
                }

                $carriers[] = [
                    'code'   => $ticket->carrier,
                    'number' => $ticket->number,
                    'name'   => $ticket->carrier_name,
                ];

                $this->templater
                    ->setPath('search/cards')
                    ->setFilename('flight-info')
                    ->set()
                    ->setPlaceholder('depart_time',     date('H:i', strtotime($ticket->depart->date_time)))
                    ->setPlaceholder('arrive_time',     date('H:i', strtotime($ticket->arrive->date_time)))
                    ->setPlaceholder('depart_date',     date('Y-m-d', strtotime($ticket->depart->date_time)))
                    ->setPlaceholder('arrive_date',     date('Y-m-d', strtotime($ticket->arrive->date_time)))
                    ->setPlaceholder('depart_city',     $ticket->depart->airport_city)
                    ->setPlaceholder('arrive_city',     $ticket->arrive->airport_city)
                    ->setPlaceholder('depart_airport',  $ticket->depart->airport_name)
                    ->setPlaceholder('arrive_airport',  $ticket->arrive->airport_name)
                    ->setPlaceholder('depart_code',     $ticket->depart->airport_code)
                    ->setPlaceholder('arrive_code',     $ticket->arrive->airport_code)
                    ->setPlaceholder('flight_duration', $this->minutesToStringTime($ticket->duration))
                    ->save();
            }

            $flight_info = $this->templater->render();

            // Flight logos
            $airline_logos = '';

            foreach ($carriers as $carrier) {
                $airline_logos .= $this->getCarrierLogo($carrier);
            }

            $package .= $this->templater
                ->setPath('search/cards')
                ->setFilename('body')
                ->set()
                ->setPlaceholder('outbound_id',        $flight->outbound->id)
                ->setPlaceholder('returning_id',       $flight->returning->id ?? null)
                ->setPlaceholder('flight_price_total', number_format($flight->price_base + $flight->price_tax, 2))
                ->setPlaceholder('flight_price_base',  number_format($flight->price_base, 2))
                ->setPlaceholder('flight_price_tax',   number_format($flight->price_tax, 2))
                ->setPlaceholder('flight_price_gst',   number_format(0, 2))
                ->setPlaceholder('flight_price_qst',   number_format(0, 2))
                ->setPlaceholder('airline_logos',      $airline_logos)

                ->setPlaceholder('flight_info', $flight_info)
                ->save()
                ->render();
        }

        return $package;
    }

    /**
     * @param $carrier
     * @return string
     * @throws \Exception
     */
    private function getCarrierLogo($carrier): string
    {
        return $this->templater
            ->setPath('search/cards')
            ->setFilename('airline-logo')
            ->set()
            ->setPlaceholder('flight_number', $carrier['number'])
            ->setPlaceholder('airline_title', $carrier['name'])
            ->setPlaceholder('logo_url', AmazonS3::getUrl(sprintf(
                '%s/suppliers/%s.png',
                Config::get('site.static.endpoint.images'),
                $carrier['code']
            )))
            ->save()
            ->render();
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function getPagination(): string
    {
        // If we have only 1 page - skip render
        if ($this->data->total_pages == 1) {
            return '';
        }

        // Render previous page button
        if ($this->get[self::GET_PAGE] > 1) {
            $url = sprintf(
                '%s?%s',
                Helper::getUrlPath(),
                http_build_query(array_merge($this->get, [self::GET_PAGE => $this->get[self::GET_PAGE] - 1]))
            );

            $button_prev = $this->templater
                ->setPath('search/pagination')
                ->setFilename('button_prev')
                ->set()
                ->setPlaceholder('href_url', $url)
                ->save()
                ->render();
        } else {
            $button_prev = null;
        }

        // Render next page button
        if ($this->get[self::GET_PAGE] < $this->data->total_pages) {
            $url = sprintf(
                '%s?%s',
                Helper::getUrlPath(),
                http_build_query(array_merge($this->get, [self::GET_PAGE => $this->get[self::GET_PAGE] + 1]))
            );

            $button_next = $this->templater
                ->setPath('search/pagination')
                ->setFilename('button_next')
                ->set()
                ->setPlaceholder('href_url', $url)
                ->save()
                ->render();
        } else {
            $button_next = null;
        }

        // Render page buttons
        $skipPages = false;

        for ($i = 1; $i <= $this->data->total_pages; $i++) {
            $isFirstPage   = $i === 1;
            $isLastPage    = $i === $this->data->total_pages;
            $isWithinRange = abs($i - $this->get[self::GET_PAGE]) <= 1;

            if ($this->data->total_pages >= 10 && !$isFirstPage && !$isLastPage && !$isWithinRange) {
                $skipPages = true;
                continue;
            }

            if ($skipPages) {
                $this->templater
                    ->setPath('search/pagination')
                    ->setFilename('button_skipped')
                    ->set()
                    ->save();

                $skipPages = false;
            }

            // Generate the page link
            if ($i != $this->get[self::GET_PAGE]) {
                $url = sprintf(
                    '%s?%s',
                    Helper::getUrlPath(),
                    http_build_query(array_merge($this->get, [self::GET_PAGE => $i]))
                );

                $this->templater
                    ->setPath('search/pagination')
                    ->setFilename('button_link')
                    ->set()
                    ->setPlaceholder('href_url', $url)
                    ->setPlaceholder('page_number', $i)
                    ->save();
            } else {
                $this->templater
                    ->setPath('search/pagination')
                    ->setFilename('button_current')
                    ->set()
                    ->setPlaceholder('page_number', $i)
                    ->save();
            }
        }

        $button_pages = $this->templater->render();

        // Render pagination bar
        return $this->templater
            ->setPath('search/pagination')
            ->setFilename('view')
            ->set()
            ->setPlaceholder('button_prev',   $button_prev)
            ->setPlaceholder('buttons_pages', $button_pages)
            ->setPlaceholder('button_next',   $button_next)
            ->save()
            ->render();
    }

    /**
     * @param $get
     * @return void
     */
    private function setGet($get): void
    {
        $this->get = $get;
    }

    /**
     * @param $post
     * @return void
     */
    private function setPost($post): void
    {
        $this->post = $post;
    }

    /**
     * @param $data
     * @return void
     */
    private function setData($data): void
    {
        $this->data = $data;
    }

}
