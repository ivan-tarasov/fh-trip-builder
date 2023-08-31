<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airlines DB table
    |--------------------------------------------------------------------------
    |
    | Lorem ipsum...
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
            'name' => 'traffic',
            'type' => 'int',
            'length' => 6,
            'default' => false,
            'nullable' => false,
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
    ],

];
