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
    | Secondary indexes for the search joins (departure/arrival airport,
    | airline) and the flights:add dedup GROUP BY (airline, number, date).
    */
    'indexes' => [
        ['name' => 'departure_airport', 'columns' => ['departure_airport']],
        ['name' => 'arrival_airport', 'columns' => ['arrival_airport']],
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
