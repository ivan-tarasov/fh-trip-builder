<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Static maps
    |--------------------------------------------------------------------------
    |
    | Five pages draw a map — city, country, airport, airline and route — and
    | until now each built its own URL in its own template, which is what made
    | changing provider a four-place edit. They ask StaticMap for one now, and
    | what the map looks like is here.
    |
    | The access token is deliberately not here. It is a credential, it differs
    | per environment, and this file is committed: it comes from MAPBOX_TOKEN in
    | the environment, the same way the database credentials do. With no token
    | set, MapView returns nothing and the pages simply render without a map —
    | which is a page missing a map rather than a page full of broken ones.
    |
    */

    // Pinned, not floating: this is half a megabyte of third-party JavaScript
    // that draws every map on the site, and "whatever is newest today" is not
    // a thing to discover from a visitor's browser.
    'gl_version' => 'v3.30.0',

    'static' => [

        // "username/style". Mapbox's own street style; the others it publishes
        // are outdoors, light, dark and satellite.
        'style' => 'mapbox/streets-v12',

        // 650x300 is what the four templates asked Yandex for, kept so the
        // swap changes no layout. The CSS scales the image to the block width,
        // so this is the aspect ratio and the detail, not the display size.
        'width' => 650,
        'height' => 300,

        // Twice the pixels for the same points, which is what a 650px-wide
        // image needs on any screen sold in the last decade. Mapbox counts a
        // retina image as one request, so this costs nothing but bytes.
        'retina' => true,

        // Room between the framed overlays and the edge, for the auto-framed
        // maps. Not just so a pin is not clipped: the point of the map is what
        // is *around* the places it marks, and a frame drawn tight to them
        // shows the pins and nothing else.
        'padding' => 72,

        // How far out a map opens when it has a single place to show.
        //
        // One point cannot be framed -- a box around it has no size -- so this
        // is the number that decides what "around here" means. 10 was too
        // close: Heathrow filled the picture and the map stopped at Slough one
        // way and Richmond the other. Each step out doubles the ground covered
        // in each direction.
        'zoom_single' => 9,

        // Small pins, for the flat <noscript> picture. The picture endpoint can
        // put at most one character on a pin, so it carries none; the live map
        // has no such limit and labels its pins properly. See 'label' below.
        'pin' => 'pin-s',

        // The site's own accent, and the width the flight path was drawn at
        // when it was measured against the map.
        'path_colour' => '0F766E',
        'path_width' => 5,

        // Pin labels are drawn as their own elements on top of the map, so what
        // they look like is in main.css with the rest of the site's type — see
        // `.map-label`. A symbol layer was the other option and would have put
        // the label inside the map's own rendering, but its background needs a
        // sprite image built at runtime, and it draws nothing at all, silently,
        // if the font it asks for is not one the style ships.
    ],
];
