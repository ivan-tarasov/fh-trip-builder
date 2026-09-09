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
    | the same destination.
    |
    | These two keep their trailing slash: both are published that way already,
    | `search` as a GET form's action in three templates and `saved` as a link
    | in the sections partial, and anybody's bookmarks have that spelling.
    |
    | It is not a site-wide rule, and this block used to claim it was. A page's
    | identity everywhere else is the form the router normalised to, which has
    | no slash: LayoutData::canonicalPath() returns it, the sitemap publishes it
    | because SitemapController reads the route keys, and every page emits it as
    | its canonical whichever spelling was asked for. The `more` links below had
    | drifted to two spellings across four entries for want of this being
    | written down.
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
    | Written before the pages existed, deliberately: the list was the
    | specification for what got built next. Four of the six columns now lead
    | somewhere -- airlines, cities, countries and airports -- and Directions is
    | the one still waiting.
    |
    | What that experiment got wrong is worth keeping. The codes here were real
    | ones checked against the seeders, on the theory that the links would start
    | working the day the pages did. They did not: a place's address is its name
    | and its code together -- "air-canada-ac", not "AC" -- so every column has
    | had to be respelled as its pages arrived. A code is not an address, and a
    | link written before there is anything to link to cannot know the
    | difference. A test walks these now instead.
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
            // Counted, like Cities below and unlike the four curated columns.
            // `source` sends it to LayoutData::popularRoutes().
            //
            // These were five hand-written pairs -- Montreal to Toronto,
            // Montreal to Paris and so on -- which was the right list to write
            // before there were route pages and the wrong one to keep after.
            // A route page exists for any of 42,578 city pairs you can fly
            // nonstop, and no list of five names the busy ones for long.
            //
            // What the query has to do that the cities one does not: only
            // return pairs that have a page. A search is recorded for whatever
            // anybody asked about, and five of the 163 city pairs searched here
            // have no nonstop and so no page -- see RouteRepository::popular().
            //
            // Six routes where its neighbours show five and a link to the
            // rest, because this is the one column with nowhere to send
            // anybody: there is no directory of routes and there cannot be
            // one, so the row the others spend on "All ..." is spent on
            // another route.
            //
            // Every column comes to the same six rows, which is the point --
            // this said "seven" for a while after a commit about label lengths
            // also dropped every count by one, and the number here is the kind
            // nobody rereads. FooterRenderTest now asserts the columns are the
            // same length as each other rather than any particular length, so
            // changing all six together stays easy and changing one does not.
            'title' => 'Directions',
            'source' => 'popular-routes',
            'count' => 6,
        ],
        [
            // Counted now, and the objection that kept it curated is answered
            // rather than ignored: summing a country's airport searches ranks it
            // by how many airports we sell there, so this takes the *busiest*
            // one instead. The United Kingdom leads on Heathrow alone.
            // See CountryRepository::mostSearched().
            'title' => 'Countries',
            'source' => 'most-searched-countries',
            'count' => 5,
            'more' => ['text' => 'All %s countries', 'url' => '/countries', 'total' => 'countries'],
        ],
        [
            // The one column whose pages exist and are not a list somebody has
            // to remember to edit. `source` sends it to
            // LayoutData::mostSearchedCities(), which reads the search counts
            // the app has been keeping since its first search.
            'title' => 'Cities',
            'source' => 'most-searched',
            'count' => 5,
            // The page it leads to is what gives all 231 city pages a route in.
            'more' => ['text' => 'All %s cities', 'url' => '/cities', 'total' => 'cities'],
        ],
        [
            // Counted, off `search_count`, and one airport per city: London
            // holds three of the four most-searched in this data, so ranked
            // airport by airport the column would be a list of London.
            //
            // The labels are still not the airport titles. "London (LHR)" fits
            // a narrow column where "Pierre Elliott Trudeau International" is
            // three lines of it and does not say Montreal anywhere -- see
            // LayoutData::mostSearchedAirports().
            'title' => 'Airports',
            'source' => 'most-searched-airports',
            'count' => 5,
            'more' => ['text' => 'All %s airports', 'url' => '/airports', 'total' => 'airports'],
        ],
        [
            // Counted, not curated: `book_count` is written every time a
            // booking is made, so this column keeps itself. Behind the count
            // sits the curated `traffic` tier, which is what an install nobody
            // has booked on yet orders by -- see AirlineRepository::mostBooked().
            'title' => 'Airlines',
            'source' => 'most-booked-airlines',
            'count' => 5,
            'more' => ['text' => 'All %s airlines', 'url' => '/airlines', 'total' => 'airlines'],
        ],
        [
            'title' => 'Help & tips',
            'links' => [
                'Baggage' => '/help/baggage',
                // "&", not "and": measured in the real face at 15px, the
                // spelled-out version is 170px against the 166 a column gives
                // it and the ampersand brings it to 153. The heading above it
                // is "Help & tips", so the column was already written this way.
                'Refunds & exchanges' => '/help/refunds',
                'Ticket did not arrive' => '/help/ticket-not-received',
                // Shorter than the article's own heading, which is "Changing
                // passenger details" and is two lines in a column this narrow.
                // The footer has never promised to repeat a page's title --
                // the Airports column beside it says "London (LHR)" where the
                // page says "Heathrow".
                'Passenger details' => '/help/passenger-details',
                'Flying with children' => '/help/flying-with-children',
            ],
            'more' => ['text' => 'All help topics', 'url' => '/help'],
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
