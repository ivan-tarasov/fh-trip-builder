<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aircraft DB table
    |--------------------------------------------------------------------------
    |
    | Aircraft types a generated flight can be operated by, keyed on the IATA
    | type code. `max_range_km` is what makes a type eligible for a leg: the
    | generator only puts an aircraft on a route it could actually fly, which is
    | why a 500 km hop never draws a widebody and a transatlantic leg never
    | draws a regional jet.
    |
    */

    'primary' => 'code',
    'engine' => 'InnoDB',
    'charset' => 'utf8',

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
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'max_range_km',
            'type' => 'smallint',
            'length' => 5,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'is_widebody',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],

];
