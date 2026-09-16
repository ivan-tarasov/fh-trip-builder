<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Search daily counts DB table
    |--------------------------------------------------------------------------
    |
    | How many searches happened on a day, across every route -- `search`
    | itself cannot answer that: it is a running total and a last-touched
    | date per route, so a route searched ten times over a month reads as one
    | row, updated in place. This is the event count `search` overwrites,
    | bumped once per call from `SearchRepository::record()` (G7.1, #332).
    |
    | Starts empty the day this ships. There is no earlier per-day figure to
    | backfill this from -- the count was never kept -- so a chart reading
    | from it is honestly thin at first rather than wrongly padded.
    |
    */

    'primary' => 'search_date',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
        [
            'name' => 'search_date',
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
            'comment' => 'Every call to record(), not just a new route',
        ],
    ],
];
