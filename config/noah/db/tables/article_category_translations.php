<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Article category translations DB table
    |--------------------------------------------------------------------------
    |
    | What a category is called, per language. The other half of
    | article_categories, on the pattern article_translations set: identity and
    | order on one table, words on this one, keyed by locale so a second
    | language is a row and not a column.
    |
    | `summary` is the sentence under the heading. It is not decoration on the
    | hub -- a category name alone ("Changes and refunds") says which pile an
    | article is in but not which pile a *reader* is in, and one line saying
    | what the group is for is what turns a list of nine titles into a page
    | somebody can scan. It is written as the file's prose rather than as a
    | header key, so `Import` refuses a category with nothing to say for the
    | same reason it refuses an article with no body.
    |
    | `updated_at` is here for the reason it is on article_translations, and
    | with the same guarantee: the importer moves it only where the words it is
    | loading differ from the words already stored, so it dates the sentence
    | and not the last deploy.
    |
    | There is no `short`. Articles have one because the footer column was
    | measured against a 166px column and two titles did not fit; nothing
    | renders a category name in a narrow space, and a column that exists
    | before it is needed is a column somebody has to guess the purpose of.
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
            'comment' => 'The category this translates',
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
            'comment' => 'The card heading and the sidebar label',
        ],
        [
            'name' => 'summary',
            'type' => 'varchar',
            'length' => 255,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'One sentence: what this group of articles is for',
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
