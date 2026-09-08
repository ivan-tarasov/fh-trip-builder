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

        map.on('load', function () {
            drawPaths(map, config);
            drawLabels(map, config);
        });

        config.markers.forEach(function (marker) {
            new mapboxgl.Marker({ color: marker.colour }).setLngLat(marker.at).addTo(map);
        });
    });

    /**
     * The names beside the pins.
     *
     * A symbol layer rather than HTML, so the labels are drawn by the map
     * engine and sit in the map's own visual language -- and so they scale and
     * move with the pins rather than being positioned over them.
     *
     * `text-font` has to name a font the style ships or the labels render as
     * nothing at all, with no error: it is a stack Mapbox Streets already uses,
     * checked against the glyph endpoint. See config/common/maps.php.
     *
     * `text-allow-overlap` is on because these labels are the point of the
     * layer. Left off, Mapbox drops whichever collides -- and a route page
     * silently naming one of its two ends is worse than two labels that touch.
     * `text-ignore-placement` stays off, so ours still push the map's own
     * labels out of the way rather than being drawn through them.
     */
    function drawLabels(map, config) {
        var labelled = config.markers.filter(function (marker) {
            return marker.label;
        });

        if (!labelled.length) {
            return;
        }

        map.addSource('map-labels', {
            type: 'geojson',
            data: {
                type: 'FeatureCollection',
                features: labelled.map(function (marker) {
                    return {
                        type: 'Feature',
                        properties: { label: marker.label },
                        geometry: { type: 'Point', coordinates: marker.at },
                    };
                }),
            },
        });

        map.addLayer({
            id: 'map-labels',
            type: 'symbol',
            source: 'map-labels',
            layout: {
                'text-field': ['get', 'label'],
                'text-font': config.label.font,
                'text-size': config.label.size,
                /* Above the pin, not on it. `text-offset` is in ems of
                   `text-size`, and a default Mapbox marker is 41px tall with
                   its tip at the coordinate -- so clearing it takes 41/14 =
                   2.93em, and 3.2 leaves a few pixels of air. Anchored at the
                   text's bottom so the lift is away from the pin. */
                'text-anchor': 'bottom',
                'text-offset': [0, -3.2],
                'text-allow-overlap': true,
                'text-padding': 2,
            },
            paint: {
                'text-color': config.label.colour,
                'text-halo-color': config.label.halo,
                'text-halo-width': 1.5,
            },
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

        if (points.length === 1) {
            map.jumpTo({ center: points[0], zoom: 10 });

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
