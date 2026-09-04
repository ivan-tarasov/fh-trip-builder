<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aircraft DB table
    |--------------------------------------------------------------------------
    |
    | Aircraft types a generated flight can be operated by, keyed on the IATA
    | type code. `max_range_km` is what makes a type eligible for a leg -- the
    | generator will not put an aircraft on a route it could not physically fly
    | -- while `cruise_speed_kmh` is what sets how long that leg takes, so a
    | turboprop and a 787 no longer cross the same distance at the same speed.
    |
    | Which cabins a type has fitted lives in `aircraft_cabins`, one row per
    | cabin on board. That table is the source of truth for what a flight can
    | sell: `flights.cabins` is a copy of it, denormalised so a search can
    | filter on the cabin without joining.
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
            'name' => 'manufacturer',
            'type' => 'varchar',
            'length' => 32,
            'default' => false,
            'nullable' => true,
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
            'name' => 'cruise_speed_kmh',
            'type' => 'smallint',
            'length' => 4,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Typical cruise speed, km/h',
        ],
        [
            'name' => 'engine_count',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [2],
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
