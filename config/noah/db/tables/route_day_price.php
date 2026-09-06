<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route day price DB table
    |--------------------------------------------------------------------------
    |
    | The cheapest fare on each day of a route, for the calendar to print under
    | its days. A cache, not a record: nothing here is the only copy of
    | anything, and every row can be worked out again from `flights`.
    |
    | It is filled a route at a time, the first time someone opens a calendar on
    | that route, because the query behind it cannot be run inside a request —
    | the cheapest one-connection fare across ninety days takes seconds on a
    | busy route.
    |
    | Cabin is in the key rather than beside it. The cheapest business day on a
    | route is not the cheapest economy day: the uplift scales with haul and not
    | every flight sells every cabin.
    |
    | Base and tax are stored apart because a party cannot be applied to their
    | sum. A child pays three quarters of the fare but a whole adult's tax, and
    | a lap infant a tenth of the fare and no tax, so the two halves scale
    | differently — a single total could not be turned into what a family pays.
    |
    */

    'primary' => 'from_code, to_code, cabin, depart_date',
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
            'comment' => 'Airport or city code, as the form submitted it',
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
            'comment' => 'CabinClass value: economy, business and so on',
        ],
        [
            'name' => 'depart_date',
            'type' => 'date',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        // Wide enough for the sum of two legs at the top of the fare range.
        [
            'name' => 'price_base',
            'type' => 'decimal',
            'length' => '8,2',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'price_tax',
            'type' => 'decimal',
            'length' => '8,2',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],
];
