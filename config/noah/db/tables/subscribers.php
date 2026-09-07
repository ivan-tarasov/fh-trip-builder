<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscribers DB table
    |--------------------------------------------------------------------------
    |
    | Addresses given to the footer's fare-alert form.
    |
    | A sign-up form sat in the footer once and was taken out again, because it
    | accepted an address and dropped it -- there was nowhere to put one. This
    | is that nowhere, filled in.
    |
    | An address and when it arrived, and nothing else. No name, no source page,
    | no last-seen: none of it is needed to send a mail, and a column that is
    | collected because it might be useful later is a column that has to be
    | explained to whoever asks what is held about them.
    |
    | `email` is UNIQUE rather than checked before insert. Two people submitting
    | the same address at once both pass a SELECT and both INSERT; the table is
    | the only place that race can actually be settled, so subscribing twice is
    | a duplicate-key the repository swallows rather than an error anybody sees.
    |
    */

    'primary' => 'id',
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',

    'indexes' => [
        ['name' => 'email', 'columns' => ['email'], 'unique' => true],
    ],

    'columns' => [
        [
            'name' => 'id',
            'type' => 'int',
            'length' => 9,
            'default' => false,
            'nullable' => false,
            'auto_inc' => true,
            'comment' => false,
        ],
        [
            // 254 is the longest address SMTP will carry, so anything longer is
            // not an address that was going to be deliverable.
            'name' => 'email',
            'type' => 'varchar',
            'length' => 254,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
        [
            'name' => 'subscribed_at',
            'type' => 'datetime',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'When the address was given, which is the whole of the consent record',
        ],
    ],
];
