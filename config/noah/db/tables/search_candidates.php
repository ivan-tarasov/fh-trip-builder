<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Search candidates cache
    |--------------------------------------------------------------------------
    |
    | The answer to one candidate query, kept so the next request for the same
    | search does not ask again.
    |
    | That query is the whole cost of a search: measured at 76-200ms against
    | everything else in the path adding up to about 4ms. It is the *join* that
    | is expensive rather than the answer -- a route returning eight itineraries
    | still took 131ms to find them -- so what is stored here is small and what
    | it saves is not (E31, #219).
    |
    | Keyed by a hash of the statement, its parameters and the highest flight
    | id. The key is then derived from the thing it stands for: change the SQL,
    | the sort, the cabin, the span, the date -- or write a single flight -- and
    | the key changes with it. There is no second list of "what makes a search
    | different" to keep in step, and the nightly generator retires the whole
    | table by writing its first row.
    |
    | Filters, the party and the sidebar are deliberately *not* in the key. They
    | are applied to the candidates afterwards in PHP, in about 2ms, so every
    | filter change and every "show more" reads this row rather than the
    | database.
    |
    | Deleting flights does not move the key, which is the one way a row can go
    | stale, and it is cheap: an itinerary whose legs have since been swept is
    | dropped by `assembleItinerary()`, which already refuses to show a card
    | whose legs did not hydrate. Such a row shortens a list; it cannot show a
    | flight that is gone.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
        [
            // sha256 of the statement and its parameters.
            'name' => 'id',
            'type' => 'char',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            // Gzipped, serialised candidate rows. A typical route is under 3KB
            // compressed and the cap is 2,001 rows, so mediumblob is room to
            // spare rather than an estimate.
            'name' => 'candidates',
            'type' => 'mediumblob',
            'length' => null,
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
    ],

    'indexes' => [
        // db:prune sweeps by age, and a date-only sweep can seek on nothing
        // else here.
        ['name' => 'built_at', 'columns' => ['built_at']],
    ],
];
