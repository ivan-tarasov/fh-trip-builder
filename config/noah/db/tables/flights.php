<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Flights DB table
    |--------------------------------------------------------------------------
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8',

    /*
    | Secondary indexes. The composite (departure_airport, arrival_airport,
    | departure_time) is the search filter: one-way and both legs of round-trip
    | range-scan it (route equality + a half-open date window), which keeps
    | `flights` the selective driving table. Its leading column also covers any
    | departure_airport-only lookup. The second index serves the flights:add
    | dedup GROUP BY (airline, number, date).
    */
    'indexes' => [
        ['name' => 'route_departure_time', 'columns' => ['departure_airport', 'arrival_airport', 'departure_time']],
        // A connecting leg's arrival is open (it can land anywhere), so the
        // route index above can't seek it by date; this one lets such legs seek
        // on (departure_airport, departure_time).
        ['name' => 'departure_airport_time', 'columns' => ['departure_airport', 'departure_time']],
        ['name' => 'airline_number_departure_time', 'columns' => ['airline', 'number', 'departure_time']],
    ],

    'columns' => [
        [
            'name' => 'id',
            'type' => 'int',
            'length' => 9,
            'default' => false,
            'nullable' => false,
            'auto_inc' => true,
            'comment' => false,
        ],
        [
            'name' => 'airline',
            'type' => 'char',
            'length' => 2,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'number',
            'type' => 'smallint',
            'length' => 4,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'aircraft',
            'type' => 'char',
            'length' => 3,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'fare_brand',
            'type' => 'char',
            'length' => 2,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'departure_airport',
            'type' => 'char',
            'length' => 3,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'departure_time',
            'type' => 'datetime',
            'length' => false,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'arrival_airport',
            'type' => 'char',
            'length' => 3,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'arrival_time',
            'type' => 'datetime',
            'length' => false,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'distance',
            'type' => 'int',
            'length' => 5,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'duration',
            'type' => 'int',
            'length' => 4,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'price_base',
            'type' => 'decimal',
            'length' => '6,2',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'price_tax',
            'type' => 'decimal',
            'length' => '6,2',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'rating',
            'type' => 'decimal',
            'length' => '3,2',
            'default' => '0.0',
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],

];
