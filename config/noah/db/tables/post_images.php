<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airside post image dimensions DB table
    |--------------------------------------------------------------------------
    |
    | What size a picture is, recorded once so the page never has to open the
    | file to find out.
    |
    | It used to open the file. `PostImages::dimensions()` called `getimagesize()`
    | on the staging directory, which worked for as long as that directory was
    | committed -- and A8.6 stopped committing it. On a deployed site the read
    | then fails, the `width` and `height` attributes are silently dropped, and
    | every image reflows the text under it as it loads. Nothing errors and the
    | page is correct locally, which is what made it worth a table rather than a
    | more careful read.
    |
    | Keyed by file name and not by post, because a size is a property of the
    | file: two posts naming the same picture describe the same pixels, and a
    | per-post row would say so twice and eventually disagree.
    |
    | The name is whatever the page asks for, which is not one spelling. A hero
    | is stored under its canonical hashed name -- `wing.3f9a2b1c.jpg` -- because
    | that is what the row in `posts` holds; a body image is stored under the
    | name the author typed, because the markdown asks for it that way. Both are
    | file names and both are unique, so one column holds them.
    |
    | Only the intrinsic size, and deliberately not the variants'. Every variant
    | is the same picture at a different width, so one ratio describes all of
    | them, and `srcset` tells the browser the widths already.
    |
    | Rows are written by `airside:import`, which is already holding the bytes
    | to hash them. Nothing prunes them yet: a replaced picture leaves its old
    | row behind, costing three columns and confusing nothing, because a name
    | nothing asks for is never looked up. Sweeping those is A8.11, along with
    | the objects they describe.
    |
    */

    'primary' => 'file',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
        [
            'name' => 'file',
            'type' => 'varchar',
            'length' => 128,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The file name the page asks for, hashed for a hero and plain for a body image',
        ],
        [
            'name' => 'width',
            'type' => 'smallint',
            'length' => 5,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Intrinsic width in pixels',
        ],
        [
            'name' => 'height',
            'type' => 'smallint',
            'length' => 5,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Intrinsic height in pixels',
        ],
    ],
];
