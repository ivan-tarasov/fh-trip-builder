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
            'triptype'     => 'triptype',
            'depart_place' => 'from',
            'arrive_place' => 'to',
            'depart_date'  => 'depart',
            'return_date'  => 'return',
            'class'        => 'class',
            'page'         => 'page',
        ],
    ],

    'triptype' => [
        'roundtrip' => 'roundtrip',
        'oneway'    => 'oneway',
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
        'price' => [
            'id'        => 'Price',
            'title'     => 'Cheap ones first',
            'note'      => 'Easy way to find most cheaper tickets',
            'order'     => 'asc',
            'roundtrip' => 1,
            'oneway'    => 1,
            'badge' => [
                'id'    => 'price',
                'text'  => 'Cheapest price',
                'icon'  => 'check-circle',
                'color' => 'success'
            ]
        ],
        'duration' => [
            'id'        => 'FlightTime',
            'title'     => 'Flight time',
            'note'      => 'We show lowest duration flights first',
            'order'     => 'asc',
            'roundtrip' => 1,
            'oneway'    => 1,
            'badge' => [
                'id'    => 'duration',
                'text'  => 'Fastest flight',
                'icon'  => 'rocket',
                'color' => 'primary'
            ]
        ],
        'depart_time' => [
            'id'        => 'Departure',
            'title'     => 'Departure time',
            'note'      => 'Tickets with earlier departure time will at the top of the list',
            'order'     => 'asc',
            'roundtrip' => 0,
            'oneway'    => 1,
            'badge' => [
                'id'    => 'departure_time',
                'text'  => 'Earlier departure',
                'icon'  => 'plane-departure',
                'color' => 'badge-bd-indigo-200'
            ]
        ],
        'arrive_time' => [
            'id'        => 'Arrival',
            'title'     => 'Arrival time',
            'note'      => 'Tickets with earlier arrival time will at the top of the list',
            'order'     => 'asc',
            'roundtrip' => 0,
            'oneway'    => 1,
            'badge' => [
                'id'    => 'arrival_time',
                'text'  => 'Earlier arrival',
                'icon'  => 'plane-arrival',
                'color' => 'dark'
            ],
        ],
        'rating' => [
            'id'        => 'Popular',
            'title'     => 'Popular first',
            'note'      => 'First we show tickets with higher rating',
            'order'     => 'desc',
            'roundtrip' => 1,
            'oneway'    => 1,
            'badge' => [
                'id'    => 'rating',
                'text'  => 'Top rated',
                'icon'  => 'star',
                'color' => 'danger'
            ]
        ],
    ],

];
