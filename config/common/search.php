<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Search form
    |--------------------------------------------------------------------------
    |
    | Search forms
    |
    */

    'form' => [
        'input' => [
            'hash' => 'hash',
            'depart_place' => 'from',
            'arrive_place' => 'to',
            'depart_date' => 'depart',
            'return_date' => 'return',
            'triptype' => 'triptype',
            'class' => 'class',
            'page' => 'page',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search page
    |--------------------------------------------------------------------------
    |
    | Search page
    |
    */

    'sort' => [
        'recommended' => [
            'id' => 'Recommended',
            'icon' => 'fa-thumbs-up',
            'title' => 'Recommended',
            'note' => 'The best balance of price and travel time',
            'order' => 'asc',
            'roundtrip' => 1,
            'oneway' => 1,
            'badge' => [
                'id' => 'value',
                'text' => 'Best value',
                'icon' => 'thumbs-up',
                'color' => 'primary',
            ],
        ],
        'price' => [
            'id' => 'Price',
            'icon' => 'fa-tag',
            'title' => 'Cheap ones first',
            'note' => 'Easy way to find most cheaper tickets',
            'order' => 'asc',
            'roundtrip' => 1,
            'oneway' => 1,
            'badge' => [
                'id' => 'price',
                'text' => 'Cheapest price',
                'icon' => 'check-circle',
                'color' => 'success',
            ],
        ],
        'duration' => [
            'id' => 'FlightTime',
            'icon' => 'fa-gauge-high',
            'title' => 'Flight time',
            'note' => 'We show lowest duration flights first',
            'order' => 'asc',
            'roundtrip' => 1,
            'oneway' => 1,
            'badge' => [
                'id' => 'duration',
                'text' => 'Fastest flight',
                'icon' => 'rocket',
                'color' => 'primary',
            ],
        ],
        'depart_time' => [
            'id' => 'Departure',
            'icon' => 'fa-plane-departure',
            'title' => 'Departure time',
            'note' => 'Tickets with earlier departure time will at the top of the list',
            'order' => 'asc',
            'roundtrip' => 0,
            'oneway' => 1,
            'badge' => [
                'id' => 'departure_time',
                'text' => 'Earlier departure',
                'icon' => 'plane-departure',
                'color' => 'badge-bd-indigo-200',
            ],
        ],
        'arrive_time' => [
            'id' => 'Arrival',
            'icon' => 'fa-plane-arrival',
            'title' => 'Arrival time',
            'note' => 'Tickets with earlier arrival time will at the top of the list',
            'order' => 'asc',
            'roundtrip' => 0,
            'oneway' => 1,
            'badge' => [
                'id' => 'arrival_time',
                'text' => 'Earlier arrival',
                'icon' => 'plane-arrival',
                'color' => 'dark',
            ],
        ],
        'layover_short' => [
            'id' => 'ShortLayovers',
            'icon' => 'fa-hourglass-half',
            'title' => 'Short layovers',
            'note' => 'Least time spent waiting between flights',
            'order' => 'asc',
            'roundtrip' => 1,
            'oneway' => 1,
            'badge' => [
                'id' => 'layover_short',
                'text' => 'Short layovers',
                'icon' => 'hourglass-half',
                'color' => 'teal',
            ],
        ],
        'rating' => [
            'id' => 'Popular',
            'icon' => 'fa-star',
            'title' => 'Popular first',
            'note' => 'First we show tickets with higher rating',
            'order' => 'desc',
            'roundtrip' => 1,
            'oneway' => 1,
            'badge' => [
                'id' => 'rating',
                'text' => 'Top rated',
                'icon' => 'star',
                'color' => 'danger',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Connections / layovers
    |--------------------------------------------------------------------------
    |
    | Bounds for assembling connecting itineraries at search time. A valid
    | layover departs between min/max minutes after the previous leg arrives;
    | max_stops caps how many connections are explored (0 = direct only);
    | roundtrip_topk caps the cheapest candidates kept per direction before
    | pairing, so round-trip pairing stays bounded.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    |
    | Time-of-day buckets are half-open minute-of-day windows [from, to), so a
    | flight belongs to exactly one. `gulf_countries` backs the "no layover in
    | the Gulf" toggle; a layover country outside both the origin's and the
    | destination's country is what the transit-visa toggle hides.
    |
    */

    'filters' => [
        'time_buckets' => [
            'early_morning' => ['title' => 'Early morning', 'icon' => 'cloud-sun', 'from' => 0, 'to' => 360],
            'morning' => ['title' => 'Morning', 'icon' => 'sun', 'from' => 360, 'to' => 720],
            'day' => ['title' => 'Afternoon', 'icon' => 'sun', 'from' => 720, 'to' => 1080],
            'evening' => ['title' => 'Evening', 'icon' => 'cloud-moon', 'from' => 1080, 'to' => 1260],
            'late_evening' => ['title' => 'Late evening', 'icon' => 'moon', 'from' => 1260, 'to' => 1440],
        ],

        'gulf_countries' => ['AE', 'SA', 'QA', 'KW', 'BH', 'OM'],

        // What counts as a layover spent overnight. The "Night layover" notice
        // on a card and the toggle that hides those itineraries read the same
        // two numbers, so the filter removes exactly what the notice flags.
        'night_from_hour' => 23,
        'night_to_hour' => 6,
    ],

    'connections' => [
        'min_connect_minutes' => 45,
        'max_connect_minutes' => 360,
        // 0 = direct only, 1 = direct + one connection, 2 = up to two.
        // Cost scales with how many candidates have to be ranked, which falls
        // sharply as the airport network widens: across 211 airports two stops
        // costs ~250-700ms, where across 50 it was ~2s and could time out. If
        // the network is ever narrowed again, re-measure before leaving this at 2.
        'max_stops' => 2,
        'roundtrip_topk' => 50,
    ],

];
