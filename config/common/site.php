<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Static content
    |--------------------------------------------------------------------------
    |
    | Settings for static content. In this case we using Amazon S3 bucket
    |
    */

    'static' => [
        'url' => '//d3i7jsp0grgmab.cloudfront.net',
        'endpoint' => [
            'images' => 'images',
            'css'    => 'css',
            'js'     => 'js',
            'vendor' => 'vendor',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User avatar
    |--------------------------------------------------------------------------
    */

    'avatar' => '//github.com/ivan-tarasov.png?size=32',

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Search page and booking page pagination settings
    |
    */

    'pagination' => [
        'search'  => 7,
        'booking' => 100
    ],

    /*
    |--------------------------------------------------------------------------
    | Main Menu
    |--------------------------------------------------------------------------
    |
    | Main menu settings
    |
    */

    'main-menu' => [
        '/my/bookings/' => [
            'text'    => 'My bookings',
            'icon'    => 'far fa-address-book',
            'spacer'  => 3,
            'enabled' => true,
        ],
        '/airlines/'  => [
            'text'    => 'Airlines',
            'icon'    => 'fas fa-plane',
            'enabled' => true,
        ],
        '/airports/'  => [
            'text'    => 'Airports',
            'icon'    => 'fas fa-map-marked-alt',
            'spacer'  => 3,
            'enabled' => true,
        ],
        '/about/' => [
            'text'    => 'About project',
            'icon'    => 'fas fa-circle-info',
            'enabled' => true,
        ],
        '/software-tests/' => [
            'text'    => 'Software tests',
            'icon'    => 'fas fa-code',
            'enabled' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Networks
    |--------------------------------------------------------------------------
    |
    | Social networks menu items
    |
    */

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

    /*
    |--------------------------------------------------------------------------
    | Git Menu
    |--------------------------------------------------------------------------
    |
    | Git menu menu items
    |
    */

    'footer-git' => [
        'Explore the docs' => 'https://github.com/ivan-tarasov/fh-trip-builder/blob/master/README.md',
        'Report Bug'       => 'https://github.com/ivan-tarasov/fh-trip-builder/issues',
        'Request Feature'  => 'https://github.com/ivan-tarasov/fh-trip-builder/issues',
        'Pull requests'    => 'https://github.com/ivan-tarasov/fh-trip-builder/pulls'
    ],

    /*
    |--------------------------------------------------------------------------
    | Tab settings
    |--------------------------------------------------------------------------
    |
    | Search forms active tab settings
    |
    */

    'tab_active' => [
        'btn'  => ' active',
        'aria' => 'true',
        'div'  => ' show active'
    ],

];
