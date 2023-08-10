<?php

/*
|--------------------------------------------------------------------------
| Main configs
|--------------------------------------------------------------------------
*/
$config['app'] = [
    // Application name ================================================================================================
    'name' => 'Trip Builder',

    // Application version =============================================================================================
    'version' => [
        'major' => 1,
        'minor' => 4,
        'patch' => 17,
    ],

    'year' => 2023,

    'license' => [
        'type' => 'MIT',
        'url'  => 'https://github.com/ivan-tarasov/fh-trip-builder/blob/master/LICENSE.txt',
    ],

    'documentation' => 'https://github.com/ivan-tarasov/fh-trip-builder/blob/master/README.md',

    'meta' => [
        'description' => 'FlightHub PHP Coding Assignment',
        'keywords' => [
            'FlightHub',
            'assessment',
            'php',
        ],
    ],

    'author' => [
        'name'    => 'Ivan Tarasov',
        'website' => 'https://tarasov.ca',
        'email'   => 'ivan@tarasov.ca',
    ],

    // Git settings ====================================================================================================
    'git' => [
        'github' => [
            'url'  => 'github.com',
            'user' => 'ivan-tarasov',
            'repo' => 'fh-trip-builder'
        ],
    ],
];

/*
|--------------------------------------------------------------------------
| Main configs
|--------------------------------------------------------------------------
*/
$config['site'] = [
    // Where MySQL credentials saved ===================================================================================
    'mysql' => 'config.mysql.php',

    // Pagination settings: search page and booking page ===============================================================
    'pagination' => [
        'search'  => 7,
        'booking' => 100
    ],

    // Templates for some places =======================================================================================
    'templates' => [
        'sidebar' => [
            'sort' => 'list-group-checkable'
        ],
    ],

    // Main menu items =================================================================================================
    'main-menu' => [
        '/my/bookings/' => [
            'text'   => 'My bookings',
            'icon'   => 'far fa-address-book',
            'spacer' => 3
        ],
        '/airlines/' => [
            'text'   => 'Airlines',
            'icon'   => 'fas fa-plane'
        ],
        '/airports/' => [
            'text'   => 'Airports',
            'icon'   => 'fas fa-map-marked-alt',
            'spacer' => 3
        ],
        '/about/' => [
            'text'   => 'About project',
            'icon'   => 'fas fa-circle-info',
        ],
//        '/software-tests/' => [
//            'text'   => 'Software tests',
//            'icon'   => 'fas fa-code'
//        ],
    ],

    // Sorting section of search sidebar ===============================================================================
    'sort' => [
        'rating' => [
            'id'        => 'Popular',
            'text'      => 'Popular first',
            'note'      => 'First we show tickets with higher rating',
            'order'     => 'desc',
            'roundtrip' => 1,
            'oneway'    => 1,
            'badge' => [
                'id'    => 'rating',
                'text'  => 'Top rated',
                'icon'  => 'star',
                'color' => 'danger'
            ]
        ],
        'price' => [
            'id'        => 'Price',
            'text'      => 'Cheap ones first',
            'note'      => 'Easy way to find most cheaper tickets',
            'order'     => 'asc',
            'roundtrip' => 1,
            'oneway'    => 1,
            'badge' => [
                'id'    => 'price',
                'text'  => 'Cheapest price',
                'icon'  => 'check-circle',
                'color' => 'success'
            ]
        ],
        'duration' => [
            'id'        => 'FlightTime',
            'text'      => 'Flight time',
            'note'      => 'We show lowest duration flights first',
            'order'     => 'asc',
            'roundtrip' => 1,
            'oneway'    => 1,
            'badge' => [
                'id'    => 'duration',
                'text'  => 'Fastest flight',
                'icon'  => 'rocket',
                'color' => 'primary'
            ]
        ],
        'departure_time' => [
            'id'        => 'Departure',
            'text'      => 'Departure time',
            'note'      => 'Tickets with earlier departure time will at the top of the list',
            'order'     => 'asc',
            'roundtrip' => 0,
            'oneway'    => 1,
            'badge' => [
                'id'    => 'departure_time',
                'text'  => 'Earlier departure',
                'icon'  => 'plane-departure',
                'color' => 'badge-bd-indigo-200'
            ]
        ],
        'arrival_time' => [
            'id'        => 'Arrival',
            'text'      => 'Arrival time',
            'note'      => 'Tickets with earlier arrival time will at the top of the list',
            'order'     => 'asc',
            'roundtrip' => 0,
            'oneway'    => 1,
            'badge' => [
                'id'    => 'arrival_time',
                'text'  => 'Earlier arrival',
                'icon'  => 'plane-arrival',
                'color' => 'dark'
            ],
        ],
    ],

    // Social networks menu items ======================================================================================
    'footer-social' => [
        'LinkedIn' => [
            'url' => 'https://linkedin.com/in/ivan-tarasov-ca',
            'ico' => 'linkedin'
        ],
        'Telegram' => [
            'url' => 'https://t.me/karapuzoff',
            'ico' => 'telegram'
        ],
        'Facebook' => [
            'url' => 'https://facebook.com/karapuzoff',
            'ico' => 'facebook'
        ],
        'Instagram' => [
            'url' => 'https://instagram.com/karapuzoff',
            'ico' => 'instagram'
        ],
        'Twitter' => [
            'url' => 'https://twitter.com/karapuzoff',
            'ico' => 'twitter'
        ],
    ],

    // Git menu items ==================================================================================================
    'footer-git' => [
        'Explore the docs' => 'https://github.com/ivan-tarasov/fh-trip-builder/blob/master/README.md',
        'Report Bug'       => 'https://github.com/ivan-tarasov/fh-trip-builder/issues',
        'Request Feature'  => 'https://github.com/ivan-tarasov/fh-trip-builder/issues',
        'Pull requests'    => 'https://github.com/ivan-tarasov/fh-trip-builder/pulls'
    ],

    // Search forms active tab settings (frontend) =====================================================================
    'tab_active' => [
        'btn'  => ' active',
        'aria' => 'true',
        'div'  => ' show active'
    ],

];

/*
|--------------------------------------------------------------------------
| Fake Flight API credentials
|--------------------------------------------------------------------------
*/
$config['FlightAPI'] = [
    // FIXME: dev credentials
    'url'   => 'http://localhost:8888/api',
    'token' => 'SomeAPItoken_$ecretWORD---orHASH',
];
