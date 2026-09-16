<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application name
    |--------------------------------------------------------------------------
    |
    | This option setting up global application name
    |
    */

    'description' => 'FlightHub PHP Coding Assessment',

    'keywords' => [
        'FlightHub',
        'assessment',
        'php',
    ],

    'author' => [
        'name' => 'Ivan Tarasov',
        'website' => 'https://tarasov.ca',
        'email' => 'ivan@tarasov.ca',
        // The admin panel's own user menu (G6.4, #348). There is no
        // accounts table this app could read either of these from --
        // "one password, one session flag, no accounts table"
        // (`AdminController`, A3.2, #100) -- so both are config, the same
        // way the two fields above already are, not a per-user record.
        'role' => 'Administrator',
        'avatar' => 'https://github.com/ivan-tarasov.png',
    ],

];
