<?php

return [

    /*
    |--------------------------------------------------------------------------
    | City images DB table
    |--------------------------------------------------------------------------
    |
    | The cached answer for one city from Wikipedia's own REST summary
    | endpoint (`en.wikipedia.org/api/rest_v1/page/summary/<name>`) -- the
    | only source that can plausibly cover an arbitrary destination rather
    | than a curated handful, since almost every real city has an infobox
    | photo and a real summary paragraph. C10 (#395)'s "Travel deals"
    | carousel reads the photo; C15 (#405) is the planned reader for
    | `extract`.
    |
    | This app owns the photo, not just a link to it: `image_key` is our own
    | S3 key (`Noah\Cities\Content` downloads the bytes and uploads them),
    | never Wikipedia's own CDN URL directly -- a page must not depend on a
    | third party's hotlinking policy or uptime to render. `image_source_url`
    | is kept only to notice when Wikipedia's own photo has changed, so a
    | scheduled refresh does not re-download and re-upload identical bytes
    | every time it runs.
    |
    | A cache, not a record, the same reason `route_day_price` is one:
    | nothing here is the only copy of anything -- Wikipedia and our own S3
    | bucket are -- and every row can be rebuilt. Filled and refreshed by a
    | scheduled command (`cities:content`), never live on a request: a
    | homepage render is not the place to make eight external HTTP calls to
    | a service this app does not control the uptime of.
    |
    | `image_key`, `image_source_url` and `extract` are all nullable on
    | purpose. A city with no Wikipedia photo, no summary text, or no
    | Wikipedia article at all still gets a row with a real `fetched_at` --
    | recorded even when nothing was found, the same reason
    | `route_price_build` is, so the next run does not spend a request
    | re-asking a source that has already answered "nothing" once.
    |
    | `fetched_at` is also the staleness clock a scheduled refresh reads:
    | see `Noah\Cities\Content::STALE_AFTER_DAYS`.
    |
    | `utf8mb4` at the table level for `extract`, which is real prose and
    | will carry accented and non-Latin names (Québec, Zürich, ...); the
    | code and URL columns are narrowed back to `ascii`, the same split
    | `article_translations` and `post_translations` already use.
    |
    */

    'primary' => 'city_code',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'columns' => [
        [
            'name' => 'city_code',
            'type' => 'char',
            'length' => 3,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'image_key',
            'type' => 'varchar',
            'length' => 255,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'Our own S3 key under site.static.endpoint.cities; null when there is no photo',
        ],
        [
            'name' => 'image_source_url',
            'type' => 'varchar',
            // Same length as the old `image_url` column it replaces --
            // measured against a real 309-char Wikimedia Commons URL, with
            // headroom rather than a guess tuned to today's longest.
            'length' => 1024,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'The Wikipedia thumbnail URL last seen, to detect a changed photo -- never rendered',
        ],
        [
            'name' => 'extract',
            'type' => 'text',
            'length' => null,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'Wikipedia\'s own plain-text summary paragraph, for C15 (#405)',
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
