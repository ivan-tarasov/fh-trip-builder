<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airports DB table
    |--------------------------------------------------------------------------
    |
    */

    'primary' => 'code',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'columns' => [
        [
            'name' => 'code',
            'type' => 'char',
            'length' => 3,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'title',
            'type' => 'varchar',
            'length' => 255,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            // What to call this airport when its city is already named on the
            // line above it: "Gatwick" under London, not "London Gatwick
            // Airport". Empty everywhere it would say nothing -- an airport
            // that is the only one in its city is never drawn under a heading,
            // so it has no second name to have (C2.5, #106).
            //
            // Not derivable, measured: stripping the city and a trailing
            // "Airport" turns both `Brussels Airport` and `Istanbul Airport`
            // into "Airport", and leaves `Berlin-Schoenefeld` alone where
            // `Berlin Brandenburg` becomes "Brandenburg". These are written by
            // hand, and there are 41 of them rather than the 254 first feared,
            // because only a city with more than one airport ever nests.
            //
            // Nullable, because the seeder reads an empty cell as NULL -- which
            // is the honest reading: a CSV cannot write "no value" any other
            // way, and 1,050 of these rows have no value.
            'name' => 'short_title',
            'type' => 'varchar',
            'length' => 100,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'Name to show under its own city, where repeating the city says nothing',
        ],
        [
            'name' => 'country_code',
            'type' => 'char',
            'length' => 2,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'city_code',
            'type' => 'char',
            'length' => 3,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'city',
            'type' => 'varchar',
            'length' => 128,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'timezone',
            'type' => 'decimal',
            'length' => '4,2',
            'default' => '1.00',
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'timezone_name',
            'type' => 'varchar',
            'length' => 255,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'latitude',
            'type' => 'decimal',
            'length' => '9,4',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'longitude',
            'type' => 'decimal',
            'length' => '9,4',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'altitude',
            'type' => 'int',
            'length' => 4,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'enabled',
            'type' => 'int',
            'length' => 1,
            'default' => 1,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            // Relative share of the network an airport carries. Flight
            // generation samples routes in proportion to it, so hubs get the
            // traffic hubs actually get instead of every airport getting the
            // same. 0 keeps an airport out of the generated network entirely.
            'name' => 'traffic_weight',
            'type' => 'smallint',
            'length' => 4,
            'default' => [1],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'is_major',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'search_count',
            'type' => 'int',
            'length' => null,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'last_search',
            'type' => 'datetime',
            'length' => null,
            'default' => null,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],

    'indexes' => [
        // A search endpoint arrives as either an airport code or a city code,
        // and resolveAirportCodes() asks for both at once. With only the
        // primary key on `code` that OR could not seek at all and read the
        // whole table; indexing `city_code` lets it merge the two sides.
        ['name' => 'city_code', 'columns' => ['city_code']],
    ],

];
