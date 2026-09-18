<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route watch alerts sent DB table
    |--------------------------------------------------------------------------
    |
    | How many watch alerts went out on a day, across every route --
    | `route_watches` itself cannot answer that: `notified_price` says only
    | whether a watch is *currently* triggered, not when or how many times it
    | has ever been emailed. This is the event count, bumped once per real
    | send from `Check::execute()` (G21, #385), the same shape
    | `search_daily_counts` already gives Searches (G7.1, #332).
    |
    | Starts empty the day this ships. There is no earlier per-day figure to
    | backfill this from -- the count was never kept -- so a chart reading
    | from it is honestly thin at first rather than wrongly padded.
    |
    */

    'primary' => 'sent_date',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
        [
            'name' => 'sent_date',
            'type' => 'date',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'count',
            'type' => 'int',
            'length' => null,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Every real send, one row per watch that qualified',
        ],
    ],
];
