<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Countries DB table
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
            'name' => 'code_iso_3',
            'type' => 'char',
            'length' => 3,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'title',
            'type' => 'varchar',
            'length' => 128,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],

];
