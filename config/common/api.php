<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fake API
    |--------------------------------------------------------------------------
    |
    | Fake Flight API credentials. Values come from the environment (.env) —
    | never commit real URLs or tokens to this file.
    |
    */

    'fake' => [
        'url' => $_ENV['API_URL'] ?? '',
        'token' => $_ENV['API_TOKEN'] ?? '',
    ],

];
