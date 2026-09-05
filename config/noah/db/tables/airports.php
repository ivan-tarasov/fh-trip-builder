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
            'name' => 'country_code',
            'type' => 'char',
            'length' => 2,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'city_code',
            'type' => 'char',
            'length' => 3,
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
            'default' => 0,
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

];
