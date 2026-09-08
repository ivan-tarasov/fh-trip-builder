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
    | set, StaticMap returns no URL and the pages simply render without a map —
    | which is a page missing a picture rather than a page full of broken ones.
    |
    */

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
        // maps. Without it a pin at the edge of the frame is drawn half
        // outside it.
        'padding' => 40,

        // Small pins. The medium and large ones are the only sizes that can
        // carry a label, and none of these maps has one to carry — no
        // mainstream provider can put a name on a pin, so the route page names
        // its two ends in a legend underneath instead.
        'pin' => 'pin-s',

        // The site's own accent, and the width the flight path was drawn at
        // when it was measured against the map.
        'path_colour' => '0F766E',
        'path_width' => 5,
    ],
];
