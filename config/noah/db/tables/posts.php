<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airside posts DB table
    |--------------------------------------------------------------------------
    |
    | Everything about an Airside post that is not written in a language.
    |
    | Airside is the section for travel itself, as opposed to help, which is
    | what a reader needs in order to finish a booking here. The name is the
    | industry's own word for everything past the security line, and it states
    | that rule rather than describing a format -- see the funnel's A8 for the
    | candidates that were ruled out, and why "Guides" could not be one of them
    | next to a section already called "Help & tips".
    |
    | Separate from `articles` rather than a `kind` column on it, because the
    | ordering differs and that difference leaks into every read: help is
    | ordered by `position`, a number somebody chooses, and a post is ordered
    | by `published_at`, a date it already has. One table would leave half its
    | columns null for each kind and every query explaining which half.
    |
    | What a post has that a help topic does not: a date, an author, and a hero
    | image. What it does not have: `icon`, `short`, `position`, and no rating,
    | because "was this helpful" is a question about instructions.
    |
    | `hero` is here and its alt text is on the translation. The file does not
    | change with the language and the words describing it do, which is the
    | same line `articles` and `article_translations` are split along. It is
    | nullable so the importer can accept a post before its image exists,
    | rather than refusing the prose over a missing file.
    |
    | `enabled` follows `articles`, `airports` and `airlines`: a row that exists
    | but is not shown. A post can be written and held back without deleting
    | it, and every read filters on it.
    |
    */

    'primary' => 'slug',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'indexes' => [
        // Every read of this table is "the enabled posts, newest first", so
        // the two columns are asked for together and indexed together. Not
        // unique: two posts can share a date and fall back on the slug, which
        // is what keeps the order stable when three are imported at once.
        ['name' => 'enabled_published', 'columns' => ['enabled', 'published_at']],
    ],

    'columns' => [
        [
            'name' => 'slug',
            'type' => 'varchar',
            'length' => 64,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The URL, and the name of the file it was imported from',
        ],
        [
            'name' => 'published_at',
            'type' => 'datetime',
            'length' => null,
            'default' => ['CURRENT_TIMESTAMP'],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The date the post claims, and the order the section reads in',
        ],
        [
            'name' => 'author',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A byline. Help has none; a post is somebody writing',
        ],
        [
            'name' => 'hero',
            'type' => 'varchar',
            'length' => 160,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => 'Path to a committed image; null until one exists',
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
            'comment' => 'When the row was written, which is not what the post claims',
        ],
    ],
];
