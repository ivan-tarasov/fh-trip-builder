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
    'charset' => 'utf8mb4',

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
        // The maintenance commands work by distance band -- flights:realign
        // counts and deletes legs no aircraft can fly, and realign, reprice and
        // cabins all report samples per band. Every one of those was a full
        // scan of the whole table, and the delete ran one per 5,000-row batch.
        ['name' => 'distance', 'columns' => ['distance']],
        // flights:cleaning removes departures that have passed. The existing
        // indexes all lead with an airport or an airline, so a date-only sweep
        // could not seek on any of them.
        ['name' => 'departure_time', 'columns' => ['departure_time']],
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
            'name' => 'cabins',
            'type' => 'tinyint',
            'length' => 3,
            'default' => ['1'],
            'nullable' => false,
            'auto_inc' => false,
            // Bitmask of the cabins this flight sells: 1 Economy, 2 Premium
            // Economy, 4 Business, 8 First. A set rather than a grade, because
            // real cabins are not nested -- a short-haul narrowbody sells
            // Economy and Business but no Premium Economy. Defaults to 1 so an
            // unpopulated row still sells the cabin every flight has.
            'comment' => 'Cabin bitmask: 1=Y 2=W 4=C 8=F',
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
