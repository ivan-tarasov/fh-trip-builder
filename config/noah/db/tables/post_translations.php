<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airside post translations DB table
    |--------------------------------------------------------------------------
    |
    | Everything about a post that is written in a language: its heading, the
    | one sentence that serves as the meta description and the card's line, the
    | words describing the hero image, and the prose.
    |
    | Split on the first day for the reason `article_translations` was, and
    | recorded there at length: the split is the expensive half, and doing it
    | later would mean moving every text column out of a table other code
    | already reads.
    |
    | Keyed on `slug, locale`, the composite string key `currency_rates`,
    | `route_day_price` and `article_translations` already use. The app asks
    | for `en` from one constant until there is a second one to ask for; see
    | PostRepository::DEFAULT_LOCALE.
    |
    | No `short`. That column exists on an article because a footer column is
    | 166px wide and two of the five titles did not fit; posts are not in the
    | footer, so a second title would be a column nothing reads.
    |
    | `hero_alt` is here rather than beside `hero` on `posts` because alt text
    | is content: it is read out, it is translated, and it is the only part of
    | an image a reader who cannot see it receives. Empty rather than null when
    | the image is decorative -- but a hero never is, so the importer requires
    | it whenever a hero is named.
    |
    | `updated_at` dates the words, not the row. `airside:import` moves it only
    | when the body being imported differs from the body already stored, so
    | re-running the import does not claim an edit that never happened.
    |
    | `body` is markdown, for the reason `article_translations` gives: the Twig
    | environment registers `config` as a bare Config::get with no allow-list,
    | so a body treated as template source could resolve
    | `{{ config('db.password') }}`. Markdown goes through the converter
    | /about and /help already run, which escapes raw HTML rather than passing
    | it through -- which is also why a callout is written `{.callout}` with the
    | Attributes extension and never as a `<div>`.
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
            'comment' => 'The post this translates',
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
            'name' => 'summary',
            'type' => 'varchar',
            'length' => 255,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'One sentence: the meta description, the card and the lead',
        ],
        [
            'name' => 'hero_alt',
            'type' => 'varchar',
            'length' => 160,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'What the hero image shows; null only where there is no hero',
        ],
        [
            'name' => 'body',
            'type' => 'text',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Markdown. Rendered through the same converter /help uses',
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
