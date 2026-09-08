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

    'directory' => [
        'js' => '/frontend/js',
        'css' => '/frontend/css',
        'fonts' => '/frontend/fonts',
    ],

    'static' => [
        'url' => '//d3i7jsp0grgmab.cloudfront.net',
        'endpoint' => [
            'images' => 'images',
            'poi' => 'images/poi',
            'css' => 'css',
            'js' => 'js',
            'vendor' => 'vendor',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User avatar
    |--------------------------------------------------------------------------
    */


    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Search page and booking page pagination settings
    |
    */

    'pagination' => [
        'search' => 7,
        'booking' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Main Menu
    |--------------------------------------------------------------------------
    |
    | Main menu settings
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Canonical paths
    |--------------------------------------------------------------------------
    |
    | Where a page lives, spelled once. The router normalises a trailing slash
    | either way, so the only thing at stake is which form the app emits -- and
    | it has to be one form, or the same page renders two different actions for
    | the same destination. Trailing slash, because that is what every link the
    | app already publishes uses, and what is in anybody's bookmarks.
    |
    */

    'paths' => [
        'search' => '/search/',
        'saved' => '/my/saved/',
    ],

    /*
    | `enabled` is whether the page exists at all; `header` and `footer` are
    | where it is listed, both defaulting to true.
    |
    | The header carries only what somebody needs while they are booking, because
    | the homepage's section tray docks into the middle of it once the hero
    | scrolls past and a full menu leaves it nowhere to land. Everything else is
    | still one glance away in the footer, which lists the lot.
    |
    | `soon` marks a placeholder: rendered as text rather than a link, so it is
    | not offered to the keyboard and does not apologise for doing nothing when
    | clicked. The same rule the hero's own tray follows.
    */
    'main-menu' => [
        '/my/bookings/' => [
            'text' => 'My bookings',
            'icon' => 'fas fa-bookmark',
            'enabled' => true,
        ],
        '/my/saved/' => [
            'text' => 'Saved flights',
            'icon' => 'fas fa-heart',
            'spacer' => 3,
            'enabled' => true,
            'header' => false,
        ],
        '/airlines/' => [
            'text' => 'Airlines',
            'icon' => 'fas fa-plane',
            'enabled' => true,
            'header' => false,
        ],
        '/airports/' => [
            'text' => 'Airports',
            'icon' => 'fas fa-map-marked-alt',
            'spacer' => 3,
            'enabled' => true,
            'header' => false,
        ],
        '/about/' => [
            'text' => 'About project',
            'icon' => 'fas fa-circle-info',
            'enabled' => true,
            'header' => false,
        ],
        // Nothing to link to yet: every price on the site is CAD. It holds the
        // place the currency switch will take, and says so out loud.
        '/currency/' => [
            'text' => 'CAD',
            'icon' => 'fas fa-globe',
            'enabled' => true,
            'footer' => false,
            'soon' => true,
        ],
        '/software-tests/' => [
            'text' => 'Software tests',
            'icon' => 'fas fa-code',
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
            'ico' => 'linkedin',
        ],
        'Telegram' => [
            'url' => 'https://t.me/karapuzoff',
            'ico' => 'telegram',
        ],
        'Facebook' => [
            'url' => 'https://facebook.com/karapuzoff',
            'ico' => 'facebook',
        ],
        'Instagram' => [
            'url' => 'https://instagram.com/tarasov.ca',
            'ico' => 'instagram',
        ],
        'Twitter' => [
            'url' => 'https://twitter.com/karapuzoff',
            'ico' => 'twitter',
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
        'Report Bug' => 'https://github.com/ivan-tarasov/fh-trip-builder/issues',
        'Request Feature' => 'https://github.com/ivan-tarasov/fh-trip-builder/issues',
        'Pull requests' => 'https://github.com/ivan-tarasov/fh-trip-builder/pulls',
    ],

    /*
    |--------------------------------------------------------------------------
    | Index POI cards
    |--------------------------------------------------------------------------
    |
    | Fake POI cards on the index page. Maybe later it becomes real
    |
    */

    'poi' => [
        [
            'country' => 'Turkey',
            'city' => 'Istanbul',
            'title' => 'Istanbul Delights: Points of Interest',
            'image' => 'istanbul.jpeg',
        ],
        [
            'country' => 'United States',
            'city' => 'Miami',
            'title' => 'Exploring Miami’s Hidden Gems',
            'image' => 'miami-01.jpeg',
        ],
        [
            'country' => 'Canada',
            'city' => 'Montréal',
            'title' => 'Montreal Magic: Must-See Places',
            'image' => 'montreal-01.jpeg',
        ],
        [
            'country' => 'United States',
            'city' => 'New York',
            'title' => 'New York City’s Top Attractions',
            'image' => 'new-york-01.jpeg',
        ],
        [
            'country' => 'France',
            'city' => 'Paris',
            'title' => 'Parisian Delights: Must-Visit Places in Paris',
            'image' => 'paris-01.jpeg',
        ],
        [
            'country' => 'Brasil',
            'city' => 'Rio de Janeiro',
            'title' => 'Discovering Rio de Janeiro: Iconic Landmarks',
            'image' => 'rio-de-janeiro-01.jpeg',
        ],
        [
            'country' => 'Australia',
            'city' => 'Sydney',
            'title' => 'Sydney’s Spectacular Sights',
            'image' => 'sydney-01.jpeg',
        ],
        [
            'country' => 'Japan',
            'city' => 'Tokio',
            'title' => 'Tokyo’s Iconic Destinations',
            'image' => 'tokio-01.jpeg',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Footer link columns
    |--------------------------------------------------------------------------
    |
    | The way into the site for somebody arriving from a search engine, and the
    | way around it for somebody who has scrolled to the bottom looking for one.
    |
    | Every one of these leads to a page that does not exist yet and answers 404
    | today. That is deliberate and it is the whole point of writing them down:
    | the list is the specification for what gets built next, and the codes are
    | real ones checked against the seeders, so the links start working the day
    | the pages do rather than needing to be rewritten.
    |
    | One shape for every column, so a single partial renders all of them. The
    | old footer had three loops over three different shapes, which is exactly
    | why nothing was ever shared between them.
    |
    | `more` is only present where there is somewhere for it to go. Airlines and
    | Airports have index pages; the other four do not, and a "more" link into
    | another 404 is a dead end offering to show you more dead ends.
    |
    */

    'footer-columns' => [
        [
            'title' => 'Airlines',
            'links' => [
                'Air Canada' => '/airline/AC',
                'WestJet' => '/airline/WS',
                'Delta Air Lines' => '/airline/DL',
                'American Airlines' => '/airline/AA',
                'United Airlines' => '/airline/UA',
            ],
            'more' => ['text' => 'All airlines', 'url' => '/airlines/'],
        ],
        [
            'title' => 'Directions',
            'links' => [
                'Montreal — Toronto' => '/route/YMQ-YTO',
                'Montreal — Vancouver' => '/route/YMQ-YVR',
                'Montreal — Paris' => '/route/YMQ-PAR',
                'Toronto — New York' => '/route/YTO-NYC',
                'Toronto — London' => '/route/YTO-LON',
            ],
        ],
        [
            // The one column whose pages exist, and so the one that is not a
            // list somebody has to remember to edit. `source` sends it to
            // LayoutData::mostSearchedCities(), which reads the search counts
            // the app has been keeping since its first search.
            'title' => 'Cities',
            'source' => 'most-searched',
            'count' => 5,
        ],
        [
            'title' => 'Airports',
            'links' => [
                'Montréal–Trudeau' => '/airport/YUL',
                'Toronto Pearson' => '/airport/YYZ',
                'Vancouver' => '/airport/YVR',
                'New York JFK' => '/airport/JFK',
                'London Heathrow' => '/airport/LHR',
            ],
            'more' => ['text' => 'All airports', 'url' => '/airports/'],
        ],
        [
            'title' => 'Countries',
            'links' => [
                'Canada' => '/country/canada-ca',
                'United States' => '/country/united-states-us',
                'United Kingdom' => '/country/united-kingdom-gb',
                'France' => '/country/france-fr',
                'Japan' => '/country/japan-jp',
            ],
        ],
        [
            'title' => 'Help & tips',
            'links' => [
                'Baggage' => '/help/baggage',
                'Refunds and exchanges' => '/help/refunds',
                'Ticket did not arrive' => '/help/ticket-not-received',
                'Changing passenger details' => '/help/passenger-details',
                'Flying with children' => '/help/flying-with-children',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Footer destinations
    |--------------------------------------------------------------------------
    |
    | The block above the columns, and the only part of the footer that differs
    | between pages: the homepage shows it, nothing else does.
    |
    | Curated rather than counted. A "most popular" list taken from the search
    | table would be honest and would also be whatever three routes somebody
    | last clicked on a demo database. (The cities column above is counted, and
    | can be: 254 rows grouped in 0.8ms, in a footer that was already asking the
    | database for its flight count.)
    |
    | Same cities as the POI cards above, which is deliberate: the cards say
    | there is something to see there, and these say you can get there -- and
    | now they do: every one of these resolves to a real city page, which is
    | checked by a test rather than left to whoever edits this list next.
    |
    */

    'footer-destinations' => [
        ['city' => 'Istanbul', 'country' => 'Turkey', 'url' => '/city/istanbul-ist'],
        ['city' => 'Miami', 'country' => 'United States', 'url' => '/city/miami-mia'],
        ['city' => 'Montreal', 'country' => 'Canada', 'url' => '/city/montreal-ymq'],
        ['city' => 'New York', 'country' => 'United States', 'url' => '/city/new-york-nyc'],
        ['city' => 'Paris', 'country' => 'France', 'url' => '/city/paris-par'],
        ['city' => 'Rio de Janeiro', 'country' => 'Brasil', 'url' => '/city/rio-de-janeiro-rio'],
        ['city' => 'Sydney', 'country' => 'Australia', 'url' => '/city/sydney-syd'],
        ['city' => 'Tokyo', 'country' => 'Japan', 'url' => '/city/tokyo-tyo'],
        ['city' => 'London', 'country' => 'United Kingdom', 'url' => '/city/london-lon'],
        ['city' => 'Toronto', 'country' => 'Canada', 'url' => '/city/toronto-yto'],
        ['city' => 'Vancouver', 'country' => 'Canada', 'url' => '/city/vancouver-yvr'],
        ['city' => 'Bangkok', 'country' => 'Thailand', 'url' => '/city/bangkok-bkk'],
    ],

];
