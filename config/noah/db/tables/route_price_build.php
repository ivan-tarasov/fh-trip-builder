<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route price build DB table
    |--------------------------------------------------------------------------
    |
    | When each route in `route_day_price` was last worked out.
    |
    | The prices themselves cannot answer this. A route with no fares at all and
    | a route nobody has asked for both have no rows, and only one of them is
    | worth the cost of asking again — so an attempt is recorded here even when
    | it found nothing.
    |
    */

    'primary' => 'from_code, to_code, cabin',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
        [
            'name' => 'from_code',
            'type' => 'char',
            'length' => 3,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'to_code',
            'type' => 'char',
            'length' => 3,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'cabin',
            'type' => 'varchar',
            'length' => 16,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'built_at',
            'type' => 'datetime',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'covers_until',
            'type' => 'date',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'How far ahead the build looked, so a blank day can be told from an unpriced one',
        ],
    ],
];
