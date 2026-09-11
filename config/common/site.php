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
    |--------------------------------------------------------------------------
    | Main Menu
    |--------------------------------------------------------------------------
    |
    | `enabled` is whether the page exists at all; `header` and `footer` are
    | where it is listed, both defaulting to true.
    |
    | The header carries only what somebody needs while they are booking, because
    | the homepage's section tray docks into the middle of it once the hero
    | scrolls past and a full menu leaves it nowhere to land. Everything else is
    | still one glance away in the footer, which lists the lot.
    |
    | Currency is not in here, and should not be put back. It is a control
    | rather than a destination: this map is keyed by URL and both its readers
    | -- the header and the footer column -- use the key as an href, so an entry
    | that must never become a link needs a special case in each of them
    | forever. It lives in partials/currency-switcher.html.twig instead.
    |
    | That also retired the `soon` flag, which marked a placeholder rendered as
    | text rather than a link. Currency was its last user. The hero's tray still
    | greys out its unbuilt sections, but it does that with its own markup.
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
        // Off until A8.4 writes the first posts. The same idiom as
        // software-tests below: a menu item leading to an empty section is
        // worse than no menu item, and `enabled` is where this file already
        // says "not yet".
        '/airside/' => [
            'text' => 'Airside',
            'icon' => 'fas fa-plane-departure',
            'enabled' => false,
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
    | specification for what got built next. All six columns lead somewhere now,
    | and none of them is a hand-written list any more -- Help & tips was the
    | last, and it went when articles gained something to be ranked by.
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
            // Counted, like every column here now. `source` sends it to
            // LayoutData::popularRoutes().
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
            // The last column to stop being a hand-written list, and the only
            // one whose ranking comes from readers saying so rather than from
            // what they searched or booked. `source` sends it to
            // LayoutData::topRatedHelp().
            //
            // The five labels that used to be written out here are the
            // `short` column on article_translations. They were measured for
            // this column's width and are still needed -- two of the titles do
            // not fit -- but they are facts about the articles, and this
            // column is built from that table now.
            //
            // Ordered by the votes on each article, with the repository's own
            // order as the tiebreaker. On a database nobody has voted on every
            // article is level, so the tiebreaker is what shows -- which is
            // category order first and `articles.position` within it, so the
            // column reads down the hub rather than across it.
            //
            // It shows `count` of however many articles exist, which stopped
            // being all of them when the catalogue grew past five.
            'title' => 'Help & tips',
            'source' => 'top-rated-help',
            'count' => 5,
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
