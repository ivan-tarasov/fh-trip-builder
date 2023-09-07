<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airports DB table
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
            'length' => 3,
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
            'name' => 'country_code',
            'type' => 'char',
            'length' => 2,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'city_code',
            'type' => 'char',
            'length' => 3,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'city',
            'type' => 'varchar',
            'length' => 128,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'timezone',
            'type' => 'decimal',
            'length' => '4,2',
            'default' => '1.00',
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'timezone_name',
            'type' => 'varchar',
            'length' => 255,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'latitude',
            'type' => 'decimal',
            'length' => '9,4',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'longitude',
            'type' => 'decimal',
            'length' => '9,4',
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'altitude',
            'type' => 'int',
            'length' => 4,
            'default' => 0,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'enabled',
            'type' => 'int',
            'length' => 1,
            'default' => 1,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'is_major',
            'type' => 'tinyint',
            'length' => 1,
            'default' => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name'     => 'search_count',
            'type'     => 'int',
            'length'   => null,
            'default'  => [0],
            'nullable' => false,
            'auto_inc' => false,
            'comment'  => false,
        ],
        [
            'name'     => 'last_search',
            'type'     => 'datetime',
            'length'   => null,
            'default'  => null,
            'nullable' => true,
            'auto_inc' => false,
            'comment'  => false,
        ],
    ],

];
