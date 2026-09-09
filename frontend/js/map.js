/**
 * The maps on the city, country, airport, airline and route pages.
 *
 * Each one is a `<div data-map='{...}'>` whose attribute carries everything the
 * map needs -- see View\MapView::config(). Read from an attribute rather than a
 * script tag because a place name can hold an apostrophe, and an attribute is
 * the one place Twig's escaping and JSON.parse agree on the rules.
 *
 * Every coordinate in that payload is [lon, lat]. That is GeoJSON's order and
 * Mapbox's, and the opposite of how the PHP that produced it reads them --
 * which is the one mistake here that draws a map of the wrong place without
 * failing.
 *
 * The page renders and is readable before any of this runs: the container has
 * its height from CSS, and a `<noscript>` beside it holds a flat picture of the
 * same map for anything that never gets here.
 */
(function () {
    'use strict';

    var containers = document.querySelectorAll('[data-map]');

    if (!containers.length) {
        return;
    }

    if (typeof mapboxgl === 'undefined' || !mapboxgl.supported()) {
        // No WebGL, or the library did not load. The container stays empty and
        // the page keeps its shape, because the height is in the stylesheet
        // rather than measured from the map.
        return;
    }

    Array.prototype.forEach.call(containers, function (container) {
        var config;

        try {
            config = JSON.parse(container.getAttribute('data-map'));
        } catch (e) {
            return;
        }

        mapboxgl.accessToken = config.token;

        var map = new mapboxgl.Map({
            container: container,
            style: config.style,
            /* One finger scrolls the page and two move the map, which is the
               only behaviour that works for a map sitting in the middle of an
               article: a full-width map that swallows a scroll traps the reader
               on it. Mapbox draws its own "use two fingers" hint. */
            cooperativeGestures: true,
            /* Everything the map needs to show is already in the payload, so
               there is nothing to gain from letting it be dragged away and a
               tile bill to be had from it. */
            attributionControl: true,
        });

        map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'top-right');

        frame(map, config);

        // Paths need the style; markers and labels do not, so they go up now
        // and are on the page whether or not the tiles ever arrive.
        map.on('load', function () {
            drawPaths(map, config);
        });

        config.markers.forEach(function (marker) {
            new mapboxgl.Marker({ color: marker.colour }).setLngLat(marker.at).addTo(map);
        });

        drawLabels(map, config);
    });

    /**
     * The names beside the pins.
     *
     * Their own elements on top of the map rather than a symbol layer inside
     * it. The layer was the first attempt and is the more native answer, but
     * the label wanted a background, and a box behind a symbol layer's text
     * means building a stretchable sprite at runtime -- while an element takes
     * the site's own type and a `background` in the stylesheet. It also cannot
     * fail quietly: a symbol layer asking for a font the style does not ship
     * draws nothing at all and reports nothing.
     *
     * A second marker rather than a custom pin, so the pin stays Mapbox's own
     * and keeps its colour. Offset in pixels, unlike a symbol layer's ems: a
     * marker is 41px tall with its tip on the coordinate, so the label clears
     * it at 46 and sits just above the head.
     */
    function drawLabels(map, config) {
        config.markers.forEach(function (marker) {
            if (!marker.label) {
                return;
            }

            var element = document.createElement('span');
            element.className = 'map-label';
            element.textContent = marker.label;

            new mapboxgl.Marker({ element: element, anchor: 'bottom', offset: [0, -46] })
                .setLngLat(marker.at)
                .addTo(map);
        });
    }

    /**
     * Where the map opens.
     *
     * A zoom in the payload means the page wants one exact view -- the airport
     * page, whose single pin frames to nothing. Everything else is framed
     * around what it has to show, which is markers and path points together: a
     * route framed on its two ends alone cuts the top off an arc that bows a
     * thousand kilometres north of them.
     */
    function frame(map, config) {
        var points = config.markers.map(function (marker) {
            return marker.at;
        });

        config.paths.forEach(function (path) {
            points = points.concat(path);
        });

        if (!points.length) {
            return;
        }

        if (config.zoom !== null && config.zoom !== undefined) {
            map.jumpTo({ center: points[0], zoom: config.zoom });

            return;
        }

        var bounds = points.reduce(function (box, point) {
            return box.extend(point);
        }, new mapboxgl.LngLatBounds(points[0], points[0]));

        map.fitBounds(bounds, { padding: config.padding, animate: false });
    }

    /**
     * The flight path, where there is one.
     *
     * One layer per run rather than one layer of many lines, because a route
     * over the antimeridian arrives as two runs and they are two lines: joined
     * into one, the map draws the join straight back around the world.
     */
    function drawPaths(map, config) {
        config.paths.forEach(function (path, index) {
            var id = 'route-path-' + index;

            map.addSource(id, {
                type: 'geojson',
                data: {
                    type: 'Feature',
                    properties: {},
                    geometry: { type: 'LineString', coordinates: path },
                },
            });

            map.addLayer({
                id: id,
                type: 'line',
                source: id,
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: {
                    'line-color': config.path.colour,
                    'line-width': config.path.width,
                },
            });
        });
    }
})();
