<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Country content DB table
    |--------------------------------------------------------------------------
    |
    | C17 (#409)'s cache for `countries:content`: one Wikipedia summary per
    | country, the same idea `city_images` already is for cities (C10, #395),
    | built on the shared fetcher C16 (#408) pulled out once a second caller
    | needed the identical shape.
    |
    | Description-first, unlike `city_images`: no photo columns. C17's own
    | funnel entry is explicit that a country photo is not something this
    | app has asked for yet, and adding `image_key`/`image_source_url` here
    | speculatively would be building for a feature that may never come --
    | a cheap follow-up migration if it ever does.
    |
    | A cache, not a record, the same reason `city_images` is one: nothing
    | here is the only copy of anything -- Wikipedia is -- and every row can
    | be rebuilt. `extract` is nullable on purpose: a country with no
    | Wikipedia summary, or one that only ever resolved to a disambiguation
    | page, still gets a row with a null extract and a real `fetched_at` --
    | recorded even when nothing was found, so the next run does not spend
    | a request re-asking a country that has already answered "nothing".
    |
    | `utf8mb4` for `extract`, the same reason `city_images` needs it: real
    | prose carries accented and non-Latin names. `country_code` narrowed
    | back to `ascii`.
    |
    */

    'primary' => 'country_code',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'columns' => [
        [
            'name' => 'country_code',
            'type' => 'char',
            'length' => 2,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'extract',
            'type' => 'text',
            'length' => null,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'Wikipedia\'s own plain-text summary paragraph; null when nothing was found',
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
