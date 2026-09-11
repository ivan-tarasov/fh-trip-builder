<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airside post-to-tag map
    |--------------------------------------------------------------------------
    |
    | Which posts carry which tags. Many-to-many, which is the whole reason tags
    | exist here rather than a column on `posts`: a post about hand luggage is
    | about security and about packing, and a single column would make somebody
    | choose.
    |
    | Both columns are the key, so a post cannot carry the same tag twice --
    | which the importer would otherwise allow from a file listing it twice.
    |
    | The index on `tag` is the one this table is really for. Every read is
    | either "the tags on this post", which the primary key serves left to
    | right, or "the posts with this tag", which is the listing page and the
    | related-post query, and which without this index is a scan.
    |
    | No foreign keys, because this schema has none anywhere. `airside:import`
    | is what keeps the two sides honest: it rewrites this table from the files
    | on every run, so a row pointing at a post or a tag that no longer exists
    | cannot survive one.
    |
    */

    'primary' => 'post, tag',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'indexes' => [
        ['name' => 'tag', 'columns' => ['tag']],
    ],

    'columns' => [
        [
            'name' => 'post',
            'type' => 'varchar',
            'length' => 64,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'posts.slug',
        ],
        [
            'name' => 'tag',
            'type' => 'varchar',
            'length' => 64,
            'charset' => 'ascii',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'post_tag_translations.slug',
        ],
    ],
];
