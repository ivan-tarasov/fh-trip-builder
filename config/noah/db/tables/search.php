<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Searches DB table
    |--------------------------------------------------------------------------
    |
    | Lorem ipsum...
    |
    */

    'primary' => 'hash',
    'engine' => 'InnoDB',
    'charset' => 'utf8',

    'columns' => [
        [
            'name'     => 'hash',
            'type'     => 'char',
            'length'   => 32,
            'default'  => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment'  => false,
        ],
        [
            'name'     => 'from_code',
            'type'     => 'char',
            'length'   => 3,
            'default'  => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment'  => false,
        ],
        [
            'name'     => 'from_name',
            'type'     => 'varchar',
            'length'   => 64,
            'default'  => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment'  => false,
        ],
        [
            'name'     => 'to_code',
            'type'     => 'char',
            'length'   => 3,
            'default'  => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment'  => false,
        ],
        [
            'name'     => 'to_name',
            'type'     => 'varchar',
            'length'   => 64,
            'default'  => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment'  => false,
        ],
        [
            'name'     => 'depart',
            'type'     => 'char',
            'length'   => 10,
            'default'  => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment'  => false,
        ],
        [
            'name'     => 'return',
            'type'     => 'char',
            'length'   => 10,
            'default'  => false,
            'nullable' => true,
            'auto_inc' => false,
            'comment'  => false,
        ],
        [
            'name'     => 'triptype',
            'type'     => 'varchar',
            'length'   => 9,
            'default'  => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment'  => false,
        ],
        [
            'name'     => 'search_count',
            'type'     => 'int',
            'length'   => null,
            'default'  => [1],
            'nullable' => false,
            'auto_inc' => false,
            'comment'  => false,
        ],
        [
            'name'     => 'last_search',
            'type'     => 'datetime',
            'length'   => null,
            'default'  => ['CURRENT_TIMESTAMP'],
            'nullable' => false,
            'auto_inc' => false,
            'comment'  => false,
        ],
    ],

];
