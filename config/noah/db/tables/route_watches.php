<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route watches DB table
    |--------------------------------------------------------------------------
    |
    | An email watching one route for a price (C6, #155) -- a new table
    | rather than columns on `subscribers`, which is deliberately just an
    | address ("none of it is needed to send a mail"): one address can watch
    | several routes, which columns on a single row cannot express.
    |
    | `notified_price` is how `alerts:check` avoids sending the same alert
    | every tick forever: null until the first time this watch's threshold is
    | met, then the price it was sent at. A further drop below that price
    | sends again; the price sitting still under threshold does not. Reset to
    | null once the price rises back above threshold, so the next dip alerts
    | fresh rather than staying silent because of what was sent months ago.
    |
    | `cabin` is stored explicitly, the same reasoning `route_day_price` gives
    | for its own column, even though the one entry point today (the route
    | page) is always economy -- the page itself has no cabin picker to offer
    | a choice with.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'indexes' => [
        // What alerts:check groups by: one cheapest() read per route rather
        // than per watch.
        ['name' => 'route', 'columns' => ['from_code', 'to_code', 'cabin']],
        // Registering the same watch twice updates it rather than
        // duplicating it, the same reasoning subscribers.email already gives.
        ['name' => 'watch', 'columns' => ['email', 'from_code', 'to_code', 'cabin'], 'unique' => true],
    ],

    'columns' => [
        [
            'name' => 'id',
            'type' => 'int',
            'length' => 11,
            'default' => false,
            'nullable' => false,
            'auto_inc' => true,
            'comment' => false,
        ],
        [
            'name' => 'email',
            'type' => 'varchar',
            'length' => 254,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
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
            'comment' => 'CabinClass value: economy, business and so on',
        ],
        [
            'name' => 'threshold',
            'type' => 'decimal',
            'length' => '8,2',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The all-in price (base + tax) a visitor wants to beat',
        ],
        [
            'name' => 'notified_price',
            'type' => 'decimal',
            'length' => '8,2',
            'default' => null,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'Null until the threshold is first met -- see the note above',
        ],
        [
            'name' => 'created',
            'type' => 'datetime',
            'length' => null,
            'default' => ['CURRENT_TIMESTAMP'],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],
];
