<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Articles DB table
    |--------------------------------------------------------------------------
    |
    | The help articles, which used to be an array in config/common/help.php.
    | That file said "prose belongs in a template and not in a PHP array", and
    | this reverses it deliberately, for reasons the array could not serve:
    | translations want rows keyed by language, an editor wants somewhere to
    | write, and "last updated" is a fact a file cannot state honestly.
    |
    | This table holds only what does not translate. The title, the short name
    | and the summary are all language-specific and live in
    | article_translations; what is left here is the article's identity and its
    | place in the list.
    |
    | `slug` is the whole key, as it was in config: it is the URL, and it is
    | already what article_votes is keyed on -- which is why the votes join
    | onto this with nothing to migrate. There is no auto-increment id, and
    | adding one later would mean rewriting every vote.
    |
    | `position` is not decoration. The array's order was the only order the
    | hub's cards, the article aside's siblings and the sitemap had, and it is
    | the tiebreaker the footer column falls back on -- which, since
    | article_votes has no seeder, decides that column outright on a fresh
    | install. The numbers here reproduce the order the array was written in,
    | which put the two things people actually ask about, bags and money,
    | first.
    |
    | `enabled` follows airports and airlines: a row that exists but is not
    | sold. An article can be written and held back without deleting it, and
    | every read filters on it.
    |
    */

    'primary' => 'slug',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'indexes' => [
        // Every read of this table is "the articles in this category, in
        // order", so the two columns are asked for together and indexed
        // together. Not unique: two articles in one category can share a
        // position and fall back on the slug, which is what kept the order
        // stable before categories existed.
        ['name' => 'category_position', 'columns' => ['category', 'position']],
    ],

    'columns' => [
        [
            'name' => 'slug',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The article URL, and the key article_votes joins on',
        ],
        [
            'name' => 'category',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The article_categories row this belongs to',
        ],
        [
            'name' => 'icon',
            'type' => 'varchar',
            'length' => 48,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A Font Awesome class, e.g. fa-suitcase-rolling',
        ],
        [
            'name' => 'position',
            'type' => 'smallint',
            'length' => null,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Lowest first. The order the hub, the aside and the sitemap use',
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
