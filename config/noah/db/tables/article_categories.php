<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Article categories DB table
    |--------------------------------------------------------------------------
    |
    | The groups the help hub is built out of. Until now there were none: nine
    | articles in one flat list, ordered by `position` alone, which reads as a
    | pile once there are more than a handful. A category gives the hub a
    | sidebar to navigate by and gives an article the siblings worth offering
    | at the end of it -- the four other articles about paying are useful there
    | in a way that a fifth about baggage is not.
    |
    | Split the way articles are split, and for the same reason: this table
    | holds what does not translate, and the title and the sentence under it
    | live in article_category_translations. Doing it now costs one extra file
    | and saves moving every text column when a second language arrives.
    |
    | `slug` is the whole key, as on articles. It is not in a URL yet -- the hub
    | is one page with a section per category -- but it is what an article's
    | `category` column names, so it has to be stable and it has to be
    | readable in a content file.
    |
    | `accent` names a colour rather than being one. The hub draws each
    | category behind its own tinted icon, and the palette belongs in the
    | stylesheet where the rest of the palette is: a row saying `blue` cannot
    | put an unreadable colour on the page, where a row saying `#0af` can.
    | Stored per category rather than derived from `position` because the admin
    | panel will let an operator add a category, and colour is a choice they
    | should be able to make without every other category's shifting under
    | them.
    |
    | `enabled` follows articles, airports and airlines: a category can be
    | written and held back. Note what it does *not* do -- disabling a category
    | hides the group, not the articles in it, so every read that filters on it
    | has to say what happens to the orphans. ArticleRepository does.
    |
    */

    'primary' => 'slug',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
        [
            'name' => 'slug',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The name an article\'s `category` column points at',
        ],
        [
            'name' => 'icon',
            'type' => 'varchar',
            'length' => 48,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A Font Awesome class, e.g. fa-ticket',
        ],
        [
            'name' => 'accent',
            'type' => 'varchar',
            'length' => 16,
            'default' => 'blue',
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A palette name the stylesheet knows, not a colour',
        ],
        [
            'name' => 'position',
            'type' => 'smallint',
            'length' => null,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Lowest first. The order of the sidebar and of the cards below it',
        ],
        [
            'name' => 'enabled',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [1],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'created_at',
            'type' => 'datetime',
            'length' => null,
            'default' => ['CURRENT_TIMESTAMP'],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],
];
