<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Article translations DB table
    |--------------------------------------------------------------------------
    |
    | Everything about an article that is written in a language: its heading,
    | the short name a narrow column uses, and the one sentence that serves as
    | the meta description, the hub card's line and the lead under the heading.
    |
    | Split from `articles` on the first day rather than when a second language
    | arrives, because the split is the expensive half. Retrofitting it later
    | would mean moving every text column out of a table other code already
    | reads, and the whole point of moving articles here was to make
    | translations cheap.
    |
    | Keyed on `slug, locale` -- the composite string key currency_rates and
    | route_day_price already use. One row per article per language, and the
    | app asks for `en` from one constant until there is a second one to ask
    | for; see ArticleRepository::DEFAULT_LOCALE.
    |
    | `short` is nullable and means "this title is too wide for a narrow
    | column". Two of the five need one: "Refunds and exchanges" measured 170px
    | against the 166 a footer column gives it, and "Changing passenger
    | details" is two lines there. Null means the title fits.
    |
    | `updated_at` is here and not on `articles` because it dates the words a
    | reader sees. A translation is rewritten without the icon moving, and an
    | icon changes without the prose being touched.
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
            'comment' => 'The article this translates',
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
            'name' => 'title',
            'type' => 'varchar',
            'length' => 120,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The heading, the document title and the breadcrumb label',
        ],
        [
            'name' => 'short',
            'type' => 'varchar',
            'length' => 60,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'The name a narrow column uses; null means the title fits',
        ],
        [
            'name' => 'summary',
            'type' => 'varchar',
            'length' => 255,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'One sentence: the meta description, the hub card and the lead',
        ],
        [
            'name' => 'updated_at',
            'type' => 'datetime',
            'length' => null,
            'default' => ['CURRENT_TIMESTAMP'],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'When these words last changed, not when the row was written',
        ],
    ],
];
