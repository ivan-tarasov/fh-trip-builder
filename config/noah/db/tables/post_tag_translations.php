<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airside tag names DB table
    |--------------------------------------------------------------------------
    |
    | A tag is a slug and a name, and the name is the only part of it there is.
    |
    | There is no `post_tags` table above this one, which departs from the split
    | every other family here uses: `articles` holds what does not translate and
    | `article_translations` holds what does. A tag has nothing in the first
    | category. A parent table carrying a slug and a `created_at` would be a
    | join every read had to make in order to learn nothing, so the names are
    | the canonical list and a tag exists because it has one.
    |
    | Keyed `slug, locale`, the composite string key `post_translations`,
    | `article_translations`, `currency_rates` and `route_day_price` already
    | use.
    |
    | No `enabled`. Holding a tag back would mean filtering it out of the pills,
    | the listing route, the related-post query and the sitemap, and there is no
    | reason yet to want that -- `airside:import` removes a tag the moment no
    | file names it, which is the only removal anybody has asked for.
    |
    */

    'primary' => 'slug, locale',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'columns' => [
        [
            'name' => 'slug',
            'type' => 'varchar',
            'length' => 64,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The tag, as it appears in a URL',
        ],
        [
            'name' => 'locale',
            'type' => 'varchar',
            'length' => 5,
            'charset' => 'ascii',
            'default' => 'en',
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A language tag: en, or en-CA where a region matters',
        ],
        [
            'name' => 'name',
            'type' => 'varchar',
            'length' => 48,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'What the pill says, and the heading of its listing page',
        ],
    ],
];
