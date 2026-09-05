<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Breadcrumb pages
    |--------------------------------------------------------------------------
    |
    | Path to the name that path is called in a trail. This map is the whole
    | configuration: a page shows a breadcrumb when it is listed here or has an
    | ancestor that is, so leaving a path out is how it opts out.
    |
    | That is why the booking funnel is absent. `/search`, `/checkout` and the
    | confirmation carry a step indicator instead, which answers a different
    | question — how far through this task, rather than where in the site — and
    | on the search page a trail would be the third stacked strip after the step
    | track and the sort bar.
    |
    | Ancestors come from the URL rather than from parent declarations, so a new
    | page under one of these needs a label here and nothing else. A page under
    | none of them needs no entry at all unless it should show a trail.
    |
    | Not taken from site.main-menu: that is a curated list of five
    | destinations, and dropping a page from the nav would silently break the
    | trail on its children.
    |
    */

    'home' => 'Home',

    'pages' => [
        '/my/bookings' => 'My bookings',
        '/my/saved' => 'Saved flights',
        '/airlines' => 'Airlines',
        '/airports' => 'Airports',
        '/about' => 'About',
    ],
];
