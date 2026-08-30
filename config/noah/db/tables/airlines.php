<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airlines DB table
    |--------------------------------------------------------------------------
    |
    */

    'primary' => 'code',
    'engine' => 'InnoDB',
    'charset' => 'utf8',

    'columns' => [
        [
            'name' => 'code',
            'type' => 'char',
            'length' => 2,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'title',
            'type' => 'varchar',
            'length' => 255,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'url',
            'type' => 'varchar',
            'length' => 255,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'phone',
            'type' => 'varchar',
            'length' => 32,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            // Where the airline is based, and the airports it operates from.
            // Flight generation only lets a carrier fly routes that touch one
            // of its hubs, or that stay inside its home country.
            'name' => 'country',
            'type' => 'char',
            'length' => 2,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'hubs',
            'type' => 'varchar',
            'length' => 255,
            'default' => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'traffic',
            'type' => 'int',
            'length' => 6,
            'default' => 0,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'is_major',
            'type' => 'tinyint',
            'length' => 1,
            'default' => 0,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'book_count',
            'type' => 'int',
            'length' => null,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'last_search',
            'type' => 'datetime',
            'length' => null,
            'default' => null,
            'nullable' => true,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],

];
