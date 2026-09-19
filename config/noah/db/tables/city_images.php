<?php

return [

    /*
    |--------------------------------------------------------------------------
    | City images DB table
    |--------------------------------------------------------------------------
    |
    | One photo per city, from Wikipedia's own REST summary endpoint
    | (`en.wikipedia.org/api/rest_v1/page/summary/<name>`) -- the only source
    | that can plausibly cover an arbitrary destination rather than a
    | curated handful, since almost every real city has an infobox photo.
    | C10 (#395)'s own "Travel deals" carousel is the first reader.
    |
    | A cache, not a record, the same reason `route_day_price` is one:
    | nothing here is the only copy of anything -- Wikipedia is -- and every
    | row can be asked for again.
    |
    | Filled by a scheduled command (`flights:city-images`), never live on a
    | request: a homepage render is not the place to make eight external
    | HTTP calls to a service this app does not control the uptime of.
    |
    | `image_url` is nullable on purpose. A city with no Wikipedia photo, or
    | no Wikipedia article at all, gets a row with a null URL and a real
    | `fetched_at` -- recorded even when nothing was found, the same reason
    | `route_price_build` is, so the next run does not spend a request
    | re-asking a source that has already answered "nothing" once.
    |
    */

    'primary' => 'city_code',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
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
            'name' => 'image_url',
            'type' => 'varchar',
            // Measured, not guessed: the longest real one seen so far is 309
            // chars (a Wikimedia Commons filename URL-encoded), and a
            // shorter guess (512) already truncated one mid-run and failed
            // the whole command on it -- SQLSTATE 22001. 1024 leaves real
            // headroom rather than being tuned to today's longest.
            'length' => 1024,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'Null when Wikipedia had no usable photo for this city',
        ],
        [
            'name' => 'fetched_at',
            'type' => 'datetime',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],

];
